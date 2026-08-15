<?php
/**
 * Proactive Outreach Engine — Task A
 *
 * Scans all eligible practitioners, evaluates seven trigger types, applies
 * seven gating rules, composes a Claude-generated message for each surviving
 * trigger, and queues it for delivery by outreach-deliver.php (Task B).
 *
 * Run daily at 3:00 AM UTC via Cowork scheduled task (SSH → server CLI):
 *   ssh -p SSH_PORT SERVER_USER@SERVER_HOST \
 *     "php /home/SERVER_USER/domains/harmonism.io/public_html/telegram-companion/outreach-engine.php"
 *
 * Delivery is handled separately by outreach-deliver.php (hourly server cron).
 *
 * Architecture: Proactive Outreach Architecture.md in vault Digital/
 */

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/outreach-errors.log');

require_once __DIR__ . '/telegram-memory.php';

// ===== CONFIG =====
$configPath = '/home/SERVER_USER/.telegram-config.php';
if (!file_exists($configPath)) {
    error_log("Outreach engine: config not found at {$configPath}");
    exit(1);
}
require_once $configPath;

if (!defined('ANTHROPIC_API_KEY') || !defined('COMPANION_MODEL')) {
    error_log("Outreach engine: config incomplete");
    exit(1);
}

// ===== RUN =====
$memory = new CompanionMemory(__DIR__ . '/companion-data.db');
$engine = new OutreachEngine($memory);
$engine->run();


// ==========================================================================
// OUTREACH ENGINE
// ==========================================================================

class OutreachEngine {

    private CompanionMemory $memory;

    // Trigger priority (lower = higher priority). Phase transition wins a tie.
    private const TRIGGER_PRIORITY = [
        'phase_transition' => 1,
        'resistance'       => 2,
        'stagnation'       => 3,
        'pillar_collapse'  => 4,
        'dormancy'         => 5,
        'seasonal'         => 6,
    ];

    // Gating constants
    private const MIN_CONFIDENCE          = 0.70;
    private const FREQUENCY_CAP_DAYS      = 7;

    // Engagement level ordering for comparison
    private const LEVEL_ORDER = [
        'unknown'     => 0,
        'absent'      => 1,
        'nascent'     => 2,
        'functional'  => 3,
        'developing'  => 4,
        'sophisticated' => 5,
        'sovereign'   => 6,
    ];

    public function __construct(CompanionMemory $memory) {
        $this->memory = $memory;
    }

    /**
     * Main entry point. Process every eligible user.
     */
    public function run(): void {
        $users = $this->memory->getEligibleUsers();
        $processed = 0;
        $queued = 0;

        foreach ($users as $user) {
            $result = $this->processUser($user);
            $processed++;
            if ($result) $queued++;
        }

        error_log("Outreach engine: processed {$processed} users, queued {$queued} messages.");
    }

    /**
     * Evaluate, gate, compose, and queue for one user.
     * Returns true if a message was queued.
     */
    private function processUser(array $user): bool {
        $telegramId = (int)$user['telegram_id'];

        // Load wheel profile once
        $wheelProfile = $this->memory->getWheelProfile($telegramId);

        // Evaluate all triggers
        $triggers = $this->evaluateTriggers($telegramId, $user, $wheelProfile);

        if (empty($triggers)) return false;

        // ---- GATING ----

        // G1: Frequency cap — check if already sent within FREQUENCY_CAP_DAYS
        $recentSent = $this->memory->getRecentSentOutreach($telegramId, self::FREQUENCY_CAP_DAYS);
        if (!empty($recentSent)) {
            foreach ($triggers as $t) {
                $this->memory->logGatedOutreach($telegramId, $t['type'], json_encode($t['detail']), $t['confidence'], 'G1_frequency_cap');
            }
            return false;
        }

        // G2: Confidence threshold — filter below minimum
        $passing = array_filter($triggers, fn($t) => $t['confidence'] >= self::MIN_CONFIDENCE);
        if (empty($passing)) {
            foreach ($triggers as $t) {
                $this->memory->logGatedOutreach($telegramId, $t['type'], json_encode($t['detail']), $t['confidence'], 'G2_low_confidence');
            }
            return false;
        }

        // Sort by priority; pick the highest-priority surviving trigger
        usort($passing, function ($a, $b) {
            $pa = self::TRIGGER_PRIORITY[$a['type']] ?? 99;
            $pb = self::TRIGGER_PRIORITY[$b['type']] ?? 99;
            return $pa <=> $pb;
        });
        $winner = array_values($passing)[0];

        // Log suppressed triggers
        foreach ($passing as $t) {
            if ($t !== $winner) {
                $this->memory->logGatedOutreach($telegramId, $t['type'], json_encode($t['detail']), $t['confidence'], 'G1_lower_priority');
            }
        }

        // ---- COMPOSE ----
        $message = $this->compose($telegramId, $winner, $user, $wheelProfile);
        if (!$message) {
            error_log("Outreach engine: composition failed for user {$telegramId}");
            return false;
        }

        // ---- SCHEDULE ----
        $scheduledAt = $this->computeScheduledAt($user);
        $this->memory->queueOutreach(
            $telegramId,
            $winner['type'],
            json_encode($winner['detail']),
            $winner['confidence'],
            $message,
            $scheduledAt
        );

        return true;
    }


    // =====================================================================
    // TRIGGER EVALUATION
    // =====================================================================

    private function evaluateTriggers(int $telegramId, array $user, array $wheelProfile): array {
        $triggers = [];

        // T1: Dormancy
        $t = $this->checkDormancy($user);
        if ($t) $triggers[] = $t;

        // T2: Stagnation (per pillar)
        foreach ($wheelProfile as $pillar => $data) {
            $t = $this->checkStagnation($pillar, $data);
            if ($t) { $triggers[] = $t; break; } // only surface worst-case pillar
        }

        // T3: Phase Transition
        $t = $this->checkPhaseTransition($wheelProfile);
        if ($t) $triggers[] = $t;

        // T4: Resistance Surfacing (per pillar — pick first)
        foreach ($wheelProfile as $pillar => $data) {
            $t = $this->checkResistance($pillar, $data);
            if ($t) { $triggers[] = $t; break; }
        }

        // T5: Pillar Collapse
        $t = $this->checkPillarCollapse($wheelProfile);
        if ($t) $triggers[] = $t;

        // T6: Seasonal Resonance
        $t = $this->checkSeasonal($wheelProfile);
        if ($t) $triggers[] = $t;

        return $triggers;
    }

    /**
     * T1: The practitioner has gone quiet longer than their normal cadence.
     */
    private function checkDormancy(array $user): ?array {
        $daysSinceSeen = (time() - (int)$user['last_seen']) / 86400;

        // Cadence-proportional threshold
        $relationshipDays = max(1, (time() - (int)$user['first_seen']) / 86400);
        $avgInterval      = $relationshipDays / max(1, (int)$user['message_count']);
        $threshold        = max(5, $avgInterval * 2.5);

        if ($daysSinceSeen < $threshold) return null;

        // Confidence scales with how far past threshold they are
        $confidence = min(0.95, 0.70 + (($daysSinceSeen - $threshold) / $threshold) * 0.25);

        return [
            'type'       => 'dormancy',
            'confidence' => round($confidence, 2),
            'detail'     => [
                'days_absent' => round($daysSinceSeen),
                'threshold'   => round($threshold),
            ],
        ];
    }

    /**
     * T2: A pillar is being circled repeatedly without advancing.
     * Conditions: discussed >= 6 times + low engagement + recent activity.
     */
    private function checkStagnation(string $pillar, array $data): ?array {
        if ((int)$data['discussion_count'] < 6) return null;
        if ((int)$data['last_discussed'] < strtotime('-30 days')) return null;

        $level = $data['engagement_level'];
        $stagnantLevels = ['absent', 'nascent', 'functional'];
        if (!in_array($level, $stagnantLevels)) return null;

        // Higher count = higher confidence the pattern is real
        $count      = (int)$data['discussion_count'];
        $confidence = min(0.90, 0.72 + ($count - 6) * 0.02);

        return [
            'type'       => 'stagnation',
            'confidence' => round($confidence, 2),
            'detail'     => [
                'pillar'           => $pillar,
                'engagement_level' => $level,
                'discussion_count' => $count,
                'concerns'         => $data['primary_concerns'],
                'growth_edges'     => $data['growth_edges'],
            ],
        ];
    }

    /**
     * T3: The practitioner's Wheel position suggests readiness for the next
     * Way of Harmony phase, but they haven't moved yet.
     */
    private function checkPhaseTransition(array $wheelProfile): ?array {
        $levels = [];
        foreach ($wheelProfile as $pillar => $data) {
            $levels[$pillar] = $data['engagement_level'];
        }

        $engaged = fn($p) => (self::LEVEL_ORDER[$levels[$p] ?? 'unknown'] ?? 0) >= self::LEVEL_ORDER['functional'];

        // Tier 1 (Presence + Health) → suggest Matter
        if ($engaged('presence') && $engaged('health')) {
            $matterReady  = !$engaged('matter');
            $serviceReady = !$engaged('service');
            if ($matterReady || $serviceReady) {
                // Only trigger if they've been in Tier 1 long enough (both discussed >= 5x)
                $presenceCount = (int)($wheelProfile['presence']['discussion_count'] ?? 0);
                $healthCount   = (int)($wheelProfile['health']['discussion_count'] ?? 0);
                if ($presenceCount >= 5 && $healthCount >= 5) {
                    return [
                        'type'       => 'phase_transition',
                        'confidence' => 0.85,
                        'detail'     => [
                            'current_phase' => 'Tier 1 — Foundation (Presence + Health)',
                            'next_phase'    => 'Tier 2 — Structure (Matter + Service)',
                            'readiness'     => [
                                'presence_level' => $levels['presence'],
                                'health_level'   => $levels['health'],
                            ],
                        ],
                    ];
                }
            }
        }

        // Tier 2 (Matter + Service) → suggest Relationships
        if ($engaged('presence') && $engaged('health') && $engaged('matter') && $engaged('service')) {
            if (!$engaged('relationships')) {
                return [
                    'type'       => 'phase_transition',
                    'confidence' => 0.87,
                    'detail'     => [
                        'current_phase' => 'Tier 2 — Structure (Matter + Service)',
                        'next_phase'    => 'Tier 3 — The Crucible (Relationships)',
                        'readiness'     => [
                            'matter_level'  => $levels['matter'],
                            'service_level' => $levels['service'],
                        ],
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * T4: A pillar with noted resistance hasn't been brought up in 3+ weeks,
     * while the practitioner remains active in other areas.
     */
    private function checkResistance(string $pillar, array $data): ?array {
        if (!(int)$data['resistance_noted']) return null;
        if (!(int)$data['last_discussed']) return null;

        $daysSinceDiscussed = (time() - (int)$data['last_discussed']) / 86400;
        if ($daysSinceDiscussed < 21) return null;

        return [
            'type'       => 'resistance',
            'confidence' => 0.75,
            'detail'     => [
                'pillar'              => $pillar,
                'days_since_discussed' => round($daysSinceDiscussed),
                'concerns'            => $data['primary_concerns'],
            ],
        ];
    }

    /**
     * T5: A pillar previously engaged (discussed 3+ times) has gone silent
     * for 60+ days while other pillars remain active.
     */
    private function checkPillarCollapse(array $wheelProfile): ?array {
        $now = time();
        $silence = 60 * 86400;

        // Find pillars that went silent
        $activePillarsExist = false;
        $collapsedPillars   = [];

        foreach ($wheelProfile as $pillar => $data) {
            $count       = (int)$data['discussion_count'];
            $lastDiscussed = (int)$data['last_discussed'];

            if ($count >= 3) {
                if (($now - $lastDiscussed) > $silence) {
                    $collapsedPillars[] = [
                        'pillar'    => $pillar,
                        'days_gone' => round(($now - $lastDiscussed) / 86400),
                    ];
                } else {
                    $activePillarsExist = true;
                }
            }
        }

        if (empty($collapsedPillars) || !$activePillarsExist) return null;

        // Pick the one gone longest
        usort($collapsedPillars, fn($a, $b) => $b['days_gone'] - $a['days_gone']);
        $worst = $collapsedPillars[0];

        return [
            'type'       => 'pillar_collapse',
            'confidence' => 0.72,
            'detail'     => $worst,
        ];
    }

    /**
     * T6: A solar event is within 2 days and resonates with the practitioner's
     * active pillar engagement.
     */
    private function checkSeasonal(array $wheelProfile): ?array {
        $year  = (int)date('Y');
        $today = mktime(0, 0, 0, (int)date('n'), (int)date('j'), $year);

        $solarEvents = [
            ['name' => 'Spring Equinox',  'month' => 3,  'day' => 20, 'pillars' => ['nature', 'presence']],
            ['name' => 'Summer Solstice', 'month' => 6,  'day' => 21, 'pillars' => ['nature', 'recreation']],
            ['name' => 'Autumn Equinox',  'month' => 9,  'day' => 23, 'pillars' => ['nature', 'learning']],
            ['name' => 'Winter Solstice', 'month' => 12, 'day' => 21, 'pillars' => ['presence', 'health']],
        ];

        foreach ($solarEvents as $event) {
            $eventTs = mktime(0, 0, 0, $event['month'], $event['day'], $year);
            $diffDays = abs($today - $eventTs) / 86400;

            if ($diffDays > 2) continue;

            // Only fire if the practitioner has meaningful engagement in resonant pillars
            foreach ($event['pillars'] as $pillar) {
                $level = $wheelProfile[$pillar]['engagement_level'] ?? 'unknown';
                if (!in_array($level, ['unknown', 'absent'])) {
                    return [
                        'type'       => 'seasonal',
                        'confidence' => 0.65,
                        'detail'     => [
                            'event'           => $event['name'],
                            'resonant_pillar' => $pillar,
                            'pillar_level'    => $level,
                        ],
                    ];
                }
            }
        }

        return null;
    }


    // =====================================================================
    // COMPOSITION
    // =====================================================================

    /**
     * Build the trigger-specific instruction for the composition prompt.
     */
    private function buildTriggerInstruction(array $trigger, string $firstName, array $wheelProfile): string {
        $type   = $trigger['type'];
        $detail = $trigger['detail'];

        switch ($type) {
            case 'dormancy':
                $days      = $detail['days_absent'];
                $threshold = $detail['threshold'];
                return "This practitioner — {$firstName} — has been silent for {$days} days. "
                    . "Based on their usual rhythm (typically active every ~{$threshold} days), this is a meaningful pause. "
                    . "Reach out as a companion who has been holding their developmental arc during their absence — not as an alert, not with concern, not asking if they're 'okay'. "
                    . "Simply as someone who has something genuine to say, grounded in what you know of where they stand on the Wheel. "
                    . "Reference something specific from their profile. 2–3 sentences. Warm and unhurried.";

            case 'stagnation':
                $pillar  = $detail['pillar'];
                $level   = $detail['engagement_level'];
                $count   = $detail['discussion_count'];
                $concerns = $detail['concerns'] ?: 'unspecified concerns';
                $edges   = $detail['growth_edges'] ?: 'unspecified growth edges';
                return "This practitioner has discussed {$pillar} {$count} times. Their engagement level has remained at '{$level}' — "
                    . "they keep returning to this territory but haven't broken through. "
                    . "Known concerns: {$concerns}. Known growth edges: {$edges}. "
                    . "Surface the pattern as a gentle observation — not a diagnosis, not a prescription. "
                    . "Something like noticing aloud what you've been seeing from the outside. "
                    . "2–3 sentences. Leave the door open; do not push it.";

            case 'phase_transition':
                $current = $detail['current_phase'];
                $next    = $detail['next_phase'];
                $readiness = $detail['readiness'];
                $readinessStr = implode(', ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($readiness), $readiness));
                return "This practitioner has built genuine ground in their current phase: {$current}. "
                    . "Profile: {$readinessStr}. "
                    . "The Way of Harmony's next phase — {$next} — is now architecturally available to them. "
                    . "They may not have named this readiness themselves. Invite them to notice it. "
                    . "One observation, one question. 2–3 sentences. Do not explain the whole Wheel sequence — "
                    . "trust that a single well-placed observation does more than a lecture.";

            case 'resistance':
                $pillar = $detail['pillar'];
                $days   = $detail['days_since_discussed'];
                $concerns = $detail['concerns'] ?: 'something they've kept at a distance';
                return "This practitioner has resistance noted in their {$pillar} pillar (concerns: {$concerns}). "
                    . "They have not brought it up in {$days} days, while remaining active in other areas. "
                    . "Reach out with a single, open observation — not a push, not a diagnosis. "
                    . "You are noting an absence, nothing more. Make it easy to not respond. "
                    . "2 sentences maximum.";

            case 'pillar_collapse':
                $pillar   = $detail['pillar'];
                $daysGone = $detail['days_gone'];
                return "This practitioner previously engaged meaningfully with {$pillar} but has not mentioned it in {$daysGone} days. "
                    . "Other pillars remain active. Name the observation without assuming a reason — "
                    . "life may have redirected them, or this pillar may be asking for attention in a different way. "
                    . "2–3 sentences. Curious, not concerned.";

            case 'seasonal':
                $event  = $detail['event'];
                $pillar = $detail['resonant_pillar'];
                $level  = $detail['pillar_level'];
                return "The {$event} is approaching. This practitioner has active engagement in {$pillar} (level: {$level}). "
                    . "Compose a brief observation that connects the turning of the year to their specific Wheel work. "
                    . "Not generic. Not motivational. Something that emerges from where they actually are. "
                    . "2–3 sentences.";

            default:
                return "Reach out to this practitioner with a brief, relevant observation about their Wheel journey. 2–3 sentences.";
        }
    }

    /**
     * Compose a proactive message via Claude.
     */
    private function compose(int $telegramId, array $trigger, array $user, array $wheelProfile): ?string {
        $firstName      = $user['first_name'] ?: $user['username'] ?: 'this practitioner';
        $profileContext = $this->memory->buildProfileContext($telegramId);

        $backbonePath = __DIR__ . '/doctrinal-backbone.md';
        $backbone     = file_exists($backbonePath) ? file_get_contents($backbonePath) : '';

        $triggerInstruction = $this->buildTriggerInstruction($trigger, $firstName, $wheelProfile);

        $systemPrompt = <<<'SYSPROMPT'
You are MunAI — Harmonia's guide through Harmonism. You speak with warmth but not softness, directness but not aggression.

You are composing a proactive outreach message — you are reaching out, the practitioner did not message you first. This means every word carries more weight. You are not composing a reactive response; you are composing the thing a genuine companion would say after thinking about someone's journey.

Rules for this message:
- 2–4 sentences maximum. No more.
- Demonstrate that you have been paying attention — reference something specific to this person, not a generic observation.
- Do not explain that you are reaching out proactively.
- Do not use phrases like "I wanted to check in" or "just checking in".
- Do not create urgency or obligation.
- Do not bullet points, no headers, no markdown formatting.
- End with something open — a question, a quiet invitation, a thought they can take or leave.
- The tone is: a thoughtful friend who has been holding your situation in mind and has one thing worth saying.

Output only the message text itself — nothing else, no preamble, no explanation.
SYSPROMPT;

        if ($backbone) {
            $systemPrompt .= "\n\n<doctrinal_backbone>\n{$backbone}\n</doctrinal_backbone>";
        }

        if ($profileContext) {
            $systemPrompt .= "\n\n<person_context>\n{$profileContext}\n</person_context>";
        }

        $systemPrompt .= "\n\n<proactive_outreach_context>\n{$triggerInstruction}\n</proactive_outreach_context>";

        $messages = [['role' => 'user', 'content' => 'Compose the outreach message now.']];

        return callOutreachClaude($systemPrompt, $messages);
    }

    /**
     * Compute the Unix timestamp for delivery: 10:00 AM in the practitioner's
     * timezone (or 10:00 AM UTC if timezone is unknown). Since the engine runs
     * at 3 AM UTC, the 10 AM slot is always same-day and in the future.
     */
    private function computeScheduledAt(array $user): int {
        $timezone = $user['timezone'] ?? '';

        if ($timezone) {
            try {
                $tz = new DateTimeZone($timezone);
                $dt = new DateTime('today', $tz);
                $dt->setTime(10, 0, 0);
                // If 10 AM local has already passed (shouldn't happen at 3 AM UTC,
                // but guard against deep timezone offsets), push to tomorrow
                if ($dt->getTimestamp() < time()) {
                    $dt->modify('+1 day');
                }
                return $dt->getTimestamp();
            } catch (Exception $e) {
                // Fall through to UTC default
            }
        }

        // Default: 10:00 AM UTC today
        $scheduled = mktime(10, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        if ($scheduled < time()) {
            $scheduled = strtotime('+1 day', $scheduled);
        }
        return $scheduled;
    }
}


// ==========================================================================
// CLAUDE API (dedicated low-token call for outreach composition)
// ==========================================================================

function callOutreachClaude(string $systemPrompt, array $messages): ?string {
    $apiUrl = 'https://api.anthropic.com/v1/messages';

    $requestBody = json_encode([
        'model'      => COMPANION_MODEL,
        'max_tokens' => 300,  // Outreach messages are short — 2-4 sentences
        'system'     => $systemPrompt,
        'messages'   => $messages,
    ]);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $requestBody,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        error_log("Outreach Claude API curl error: " . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("Outreach Claude API HTTP {$httpCode}: {$result}");
        return null;
    }

    $data = json_decode($result, true);
    if (!$data || !isset($data['content'][0]['text'])) {
        error_log("Outreach Claude API unexpected response: {$result}");
        return null;
    }

    return trim($data['content'][0]['text']);
}
