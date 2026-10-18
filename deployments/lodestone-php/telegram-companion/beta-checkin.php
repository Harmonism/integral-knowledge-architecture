<?php
require_once __DIR__ . '/llm-gateway.php';  // model provider switch (LLM_PROVIDER in server config)
/**
 * Beta Check-In — One-Off Script
 *
 * Sends a personalized check-in to all beta testers who have not messaged
 * the Companion in the past 48 hours. Bypasses the normal eligibility gates
 * (message_count, relationship age) that would exclude early-stage users.
 *
 * Respects:
 *   - outreach_opt_out flag
 *   - 7-day frequency cap (no double-messaging)
 *   - pending outreach already in the queue
 *
 * Run once from the Mac terminal via SSH:
 *   ssh -p SSH_PORT SERVER_USER@SERVER_HOST \
 *     "php /home/SERVER_USER/domains/harmonism.io/public_html/telegram-companion/beta-checkin.php"
 *
 * Delivery is handled by outreach-deliver.php (hourly server cron) — messages
 * will be picked up on the next run after this script completes.
 */

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/outreach-errors.log');

require_once __DIR__ . '/telegram-memory.php';

$configPath = '/home/SERVER_USER/.telegram-config.php';
if (!file_exists($configPath)) {
    error_log("beta-checkin: config not found at {$configPath}");
    exit(1);
}
require_once $configPath;

if (!defined('ANTHROPIC_API_KEY') || !defined('COMPANION_MODEL')) {
    error_log("beta-checkin: config incomplete");
    exit(1);
}

$dbPath = __DIR__ . '/companion-data.db';
$memory = new CompanionMemory($dbPath);
$pdo    = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$runner = new BetaCheckIn($memory, $pdo);
$runner->run();


// ==========================================================================
// BETA CHECK-IN RUNNER
// ==========================================================================

class BetaCheckIn {

    private CompanionMemory $memory;
    private PDO $pdo;
    private const SILENCE_HOURS      = 48;
    private const FREQUENCY_CAP_DAYS = 7;

    public function __construct(CompanionMemory $memory, PDO $pdo) {
        $this->memory = $memory;
        $this->pdo    = $pdo;
    }

    public function run(): void {
        $users     = $this->getBetaUsers();
        $processed = 0;
        $queued    = 0;
        $skipped   = 0;

        echo "Beta check-in: found " . count($users) . " users silent 48h+\n";

        foreach ($users as $user) {
            $telegramId = (int)$user['telegram_id'];

            // Frequency cap — skip if outreach already sent within 7 days
            $recent = $this->memory->getRecentSentOutreach($telegramId, self::FREQUENCY_CAP_DAYS);
            if (!empty($recent)) {
                echo "  Skip {$telegramId} ({$user['first_name']}): outreach already sent in last 7 days\n";
                $skipped++;
                continue;
            }

            // Skip if a pending outreach is already queued (not yet delivered)
            if ($this->hasPendingOutreach($telegramId)) {
                echo "  Skip {$telegramId} ({$user['first_name']}): pending outreach already in queue\n";
                $skipped++;
                continue;
            }

            $wheelProfile = $this->memory->getWheelProfile($telegramId);
            $message      = $this->compose($telegramId, $user, $wheelProfile);

            if (!$message) {
                echo "  Failed {$telegramId} ({$user['first_name']}): composition error\n";
                $processed++;
                continue;
            }

            // Schedule for immediate delivery (outreach-deliver.php picks it up next run)
            $this->memory->queueOutreach(
                $telegramId,
                'beta_checkin',
                json_encode(['silence_hours' => self::SILENCE_HOURS, 'message_count' => $user['message_count']]),
                0.90,
                $message,
                time()
            );

            echo "  Queued {$telegramId} ({$user['first_name']}): \"{$message}\"\n";
            $processed++;
            $queued++;

            // Small pause between Claude API calls — rate limit courtesy
            sleep(1);
        }

        echo "Beta check-in complete: {$queued} queued, {$skipped} skipped, {$processed} processed total.\n";
        error_log("beta-checkin: {$queued} queued, {$skipped} skipped of " . count($users) . " candidates.");
    }

    /**
     * All users silent for 48h+, opted in, regardless of message count or age.
     */
    private function getBetaUsers(): array {
        $cutoff = time() - (self::SILENCE_HOURS * 3600);
        $stmt   = $this->pdo->prepare('
            SELECT * FROM users
            WHERE last_seen < ?
              AND outreach_opt_out = 0
            ORDER BY last_seen ASC
        ');
        $stmt->execute([$cutoff]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if a pending (undelivered) outreach event already exists for this user.
     */
    private function hasPendingOutreach(int $telegramId): bool {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM outreach_events
            WHERE telegram_id = ?
              AND sent_at = 0
              AND gated_out = 0
        ');
        $stmt->execute([$telegramId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Compose a personalized check-in via Claude.
     */
    private function compose(int $telegramId, array $user, array $wheelProfile): ?string {
        $firstName      = $user['first_name'] ?: $user['username'] ?: 'this practitioner';
        $profileContext = $this->memory->buildProfileContext($telegramId);
        $daysSilent     = round((time() - (int)$user['last_seen']) / 86400, 1);
        $msgCount       = (int)$user['message_count'];

        $backbonePath = __DIR__ . '/doctrinal-backbone.md';
        $backbone     = file_exists($backbonePath) ? file_get_contents($backbonePath) : '';

        // Build a wheel summary for grounding the message
        $wheelSummary = '';
        if (!empty($wheelProfile)) {
            $engaged = [];
            foreach ($wheelProfile as $pillar => $data) {
                $level = $data['engagement_level'] ?? 'unknown';
                if (!in_array($level, ['unknown', 'absent'])) {
                    $engaged[] = "{$pillar} ({$level})";
                }
            }
            if (!empty($engaged)) {
                $wheelSummary = "Engaged pillars: " . implode(', ', $engaged) . ".";
            }
        }

        $systemPrompt = <<<'SYSPROMPT'
You are Lodestone — Harmonia's guide through Harmonism. You speak with warmth but not softness, directness but not aggression.

You are reaching out to a practitioner who explored Lodestone during Harmonia's beta launch but has not returned in a day or two. Your task is to compose a single, genuine check-in message.

Rules:
- 2–3 sentences. No more.
- Do NOT say "checking in", "just wanted to see", "I noticed you haven't been back", or any variation that announces the gap.
- Do NOT mention the beta, technical improvements, or any system-level framing.
- Demonstrate attentiveness — if you have profile data, reference something specific to them. If not, speak from the Wheel's logic in a way that feels personal.
- End with something open — a quiet question or a thought they can take or leave.
- No markdown. No bullet points. No headers.
- The tone: a thoughtful companion who has been holding their situation in mind and has one genuine thing to offer.

Output only the message text. Nothing else.
SYSPROMPT;

        if ($backbone) {
            $systemPrompt .= "\n\n<doctrinal_backbone>\n{$backbone}\n</doctrinal_backbone>";
        }

        if ($profileContext) {
            $systemPrompt .= "\n\n<person_context>\n{$profileContext}\n</person_context>";
        }

        $contextBlock = "Practitioner: {$firstName}. Days since last message: {$daysSilent}. Total messages exchanged: {$msgCount}.";
        if ($wheelSummary) $contextBlock .= " {$wheelSummary}";
        $systemPrompt .= "\n\n<outreach_context>\n{$contextBlock}\n</outreach_context>";

        $messages = [['role' => 'user', 'content' => 'Compose the check-in message now.']];

        $apiUrl     = 'https://api.anthropic.com/v1/messages';
        $requestBody = json_encode([
            'model'      => COMPANION_MODEL,
            'max_tokens' => 2048,  // ceiling covers Opus 5.5 adaptive thinking + reply; length is set by the prompt
            'output_config' => ['effort' => 'low'],
            'system'     => $systemPrompt,
            'messages'   => $messages,
        ]);

        $ch = llm_curl_init();
        llm_curl_setopt_array($ch, [
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

        $result   = llm_curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log("beta-checkin Claude error: " . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("beta-checkin Claude HTTP {$httpCode}: {$result}");
            return null;
        }

        $data = json_decode($result, true);
        // Opus 5.5: replies may open with thinking blocks — keep only text blocks so content[0] is the first text block.
        if (is_array($data) && isset($data['content']) && is_array($data['content'])) { $data['content'] = array_values(array_filter($data['content'], function ($b) { return ($b['type'] ?? '') === 'text'; })); }
        if (!$data || !isset($data['content'][0]['text'])) {
            error_log("beta-checkin unexpected response: {$result}");
            return null;
        }

        return trim($data['content'][0]['text']);
    }
}
