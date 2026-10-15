<?php
require_once __DIR__ . '/llm-gateway.php';  // model provider switch (LLM_PROVIDER in server config)
/**
 * Retroactive Wheel Recalibration — one-shot CLI tool.
 *
 * Re-runs the profile-learning Claude call against each user's recent history
 * using the new evidence-discipline prompt + signed-accumulator threshold.
 * Emits a JSON-line audit trail to stdout so dry-run output can be reviewed
 * before any write.
 *
 * Safe by default: `--dry-run` simulates the threshold outcome but writes
 * nothing. `--apply` delegates to the memory class's own `updateWheelPillar()`
 * so the threshold logic stays in a single canonical location.
 *
 * USAGE
 *   php retroactive-wheel-recalibration.php \
 *       --surface=telegram \
 *       [--dry-run | --apply] \
 *       [--min-messages=10] \
 *       [--limit=N] \
 *       [--user=ID] \
 *       [--window=60] \
 *       [--summaries=3]
 *
 *   Examples
 *     # Dry-run Telegram, show every user that would change:
 *     php retroactive-wheel-recalibration.php --surface=telegram --dry-run
 *
 *     # Dry-run single Telegram user:
 *     php retroactive-wheel-recalibration.php --surface=telegram --user=7815332
 *
 *     # Apply on website surface:
 *     php retroactive-wheel-recalibration.php --surface=website --apply
 *
 * OUTPUT
 *   One JSON object per line on stdout. Event types:
 *     - `start`     : run parameters (surface, mode, user count)
 *     - `skip`      : user skipped (too few messages, no wheel state, etc.)
 *     - `api_error` : Claude call failed or JSON parse failed
 *     - `pillar`    : per-pillar proposal + simulated outcome
 *                     outcomes ∈ {
 *                       nochange,
 *                       applied_high_conf,
 *                       cancelled_opposite,
 *                       applied_threshold_crossed,
 *                       pending_accumulate
 *                     }
 *     - `nochange`  : user processed, no proposed changes
 *     - `error`     : unhandled exception on a single user
 *     - `complete`  : end-of-run summary counters
 *
 *   Pipe to `tee` to keep a log, `jq` to filter:
 *     php retroactive-wheel-recalibration.php --surface=telegram --dry-run \
 *         | tee /tmp/recalibrate-telegram.jsonl \
 *         | jq -c 'select(.type=="pillar" and .outcome != "nochange")'
 */

// ===== CLI GUARD =====
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

// ===== ARG PARSING =====
$opts = [
    'surface'      => null,       // telegram|website (required)
    'mode'         => 'dry-run',  // dry-run|apply
    'min_messages' => 20,         // floor: below 20 messages, retroactive signal is too thin
    'limit'        => null,       // cap on users processed
    'user'         => null,       // single user for debugging
    'window'       => 180,        // recent-message window for Claude (raised from 60 after evidence-miss on Tahir)
    'summaries'    => 5,          // number of prior summaries to include
    'model'        => 'sonnet',   // sonnet (default, 5x cheaper) | opus (original, structural accuracy)
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')                              { $opts['mode']         = 'dry-run'; continue; }
    if ($arg === '--apply')                                { $opts['mode']         = 'apply';   continue; }
    if (preg_match('/^--surface=(telegram|website)$/', $arg, $m)) { $opts['surface']      = $m[1];       continue; }
    if (preg_match('/^--min-messages=(\d+)$/', $arg, $m))         { $opts['min_messages'] = (int) $m[1]; continue; }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m))                { $opts['limit']        = (int) $m[1]; continue; }
    if (preg_match('/^--user=(.+)$/', $arg, $m))                  { $opts['user']         = $m[1];       continue; }
    if (preg_match('/^--window=(\d+)$/', $arg, $m))               { $opts['window']       = (int) $m[1]; continue; }
    if (preg_match('/^--summaries=(\d+)$/', $arg, $m))            { $opts['summaries']    = (int) $m[1]; continue; }
    if (preg_match('/^--model=(sonnet|opus|haiku)$/', $arg, $m))  { $opts['model']        = $m[1];       continue; }
    fwrite(STDERR, "Unknown arg: {$arg}\n");
    exit(1);
}

if (!$opts['surface']) {
    fwrite(STDERR, "Error: --surface=telegram|website is required\n");
    exit(1);
}

// ===== SURFACE-SPECIFIC BOOTSTRAP =====
$surface = $opts['surface'];

if ($surface === 'telegram') {
    require_once __DIR__ . '/telegram-memory.php';
    $configPath = '/home/SERVER_USER/.telegram-config.php';
    if (!file_exists($configPath)) {
        // Local dev fallback — try alongside script
        $configPath = __DIR__ . '/.telegram-config.php';
    }
    if (!file_exists($configPath)) {
        fwrite(STDERR, "Config not found at {$configPath}\n");
        exit(1);
    }
    require_once $configPath;
    $memory      = new CompanionMemory(__DIR__ . '/companion-data.db');
    $idField     = 'telegram_id';
    $tableName   = 'users';
    $idCastFn    = 'intval';
} else {
    require_once __DIR__ . '/../companion-memory.php';
    $configPath = '/home/SERVER_USER/.companion-config.php';
    if (!file_exists($configPath)) {
        $configPath = __DIR__ . '/../.companion-config.php';
    }
    if (!file_exists($configPath)) {
        fwrite(STDERR, "Config not found at {$configPath}\n");
        exit(1);
    }
    require_once $configPath;
    $memory      = new WebCompanionMemory();  // auto-resolves website-companion-data.db
    $idField     = 'client_id';
    $tableName   = 'clients';
    $idCastFn    = 'strval';
}

if (!defined('ANTHROPIC_API_KEY') || !defined('COMPANION_MODEL')) {
    fwrite(STDERR, "Config missing ANTHROPIC_API_KEY or COMPANION_MODEL\n");
    exit(1);
}

// ===== LEVEL ORDER (mirrors memory class) =====
$levelOrder = [
    'unknown' => 0, 'absent' => 1, 'nascent' => 2,
    'functional' => 3, 'developing' => 4, 'sophisticated' => 5, 'sovereign' => 6,
];

// ===== JSON LOGGER =====
function emit(array $payload): void {
    $payload['ts'] = time();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    @ob_flush();
    @flush();
}

// ===== WHEEL PROMPT (identical to process-queue.php / companion-api.php) =====
$SYSTEM_PROMPT = <<<'WHEEL'
You are analyzing a conversation to update a person's Wheel of Harmony profile.
The Wheel has 8 pillars: presence, health, matter, service, relationships, learning, nature, recreation.

Engagement levels (ordered scale — scores move in either direction):
0 - unknown: No data yet
1 - absent: Pillar not engaged — blind spot or active avoidance
2 - nascent: Awareness exists but no real practice or structure
3 - functional: Basic engagement — meets needs but unexamined
4 - developing: Active work, growing sophistication
5 - sophisticated: Deep engagement, integrated practice
6 - sovereign: Mastery — this pillar is a source of strength

EVIDENCE DISCIPLINE — read this carefully:

1. Treat each pillar's current recorded level as a HYPOTHESIS, not a floor. The conversation is your evidence for whether that hypothesis still holds, holds stronger, or should be revised downward.

2. ABSENCE of mention is NOT evidence. If the person did not discuss a pillar in this conversation, do not propose any change for it — omit it from your output entirely.

3. CONTRADICTION with the recorded level IS evidence. If the recorded level says "developing Health" but the person describes stopped practices, returned bad habits, or ongoing decline — propose the appropriate lower level. Do not assume progress is linear or permanent. Do not preserve a level charitably against contradictory evidence.

4. Report regression and advancement with equal readiness. The system has a conservative threshold that holds back single-signal drops — your job is to surface the evidence honestly; the threshold handles noise.

5. Cross-pillar inference: a single observation can carry evidence for multiple pillars. Chronic work stress signals both service (overextension) and health (sleep, recovery). Spending time in nature unprompted signals both nature and presence. Include all pillars where the observation carries real evidence.

CONFIDENCE TAGGING — when proposing a level change, tag it:

- HIGH confidence: the person explicitly describes the new state or a stable new pattern ("I stopped walking three months ago, I'm back on soda, I've gained 5kg"; "I've been meditating daily for six weeks now"). Explicit description of the change itself, or of a pattern sustained over weeks or longer.

- LOW confidence: brief mention, off-handed remark, single data point, or inferred from tone ("bad week for sleep"; "tried journaling yesterday"). Could be a one-off, could be a trend — need to see it again.

Default to LOW unless the evidence meets the HIGH bar.

Respond with ONLY valid JSON, no other text. Format:
{
  "pillar_name": {
    "engagement_level": "level",
    "level_change_confidence": "high",
    "primary_concerns": "current active issues (empty string if none)",
    "strengths": "what they're doing well (empty string if none)",
    "growth_edges": "where growth is available (empty string if none)",
    "resistance_noted": false
  }
}

Omit level_change_confidence if engagement_level is unchanged from the recorded level.

Set resistance_noted to true ONLY if you observe the person actively avoiding, minimizing, or deflecting from a pillar.
WHEEL;

// ===== MODEL ID MAP =====
function resolveModelId(string $alias): string {
    switch ($alias) {
        case 'opus':   return defined('COMPANION_MODEL') ? COMPANION_MODEL : 'claude-opus-5-5';
        case 'sonnet': return 'claude-sonnet-4-6';
        case 'haiku':  return 'claude-haiku-4-5-20251001';
        default:       return 'claude-sonnet-4-6';
    }
}

// ===== CLAUDE CALLER =====
function callClaudeApi(string $modelId, string $systemPrompt, string $userMessage): ?array {
    // Prompt caching: system prompt is identical across every call in this run,
    // so wrap it as a cacheable block. First call writes at 1.25x; subsequent
    // reads within ~5 min at 0.1x. For N users back-to-back, saves ~90% on the
    // system-prompt tokens. Opus 4.6 through Opus 5 reject assistant-message prefill, so we
    // rely on the user-message JSON instruction + caller salvage regex instead.
    // `temperature` is deprecated on Opus 4.7+ and Sonnet 5 — sending it returns
    // HTTP 400. Only Sonnet 4.6 and Haiku 4.5 still accept it, so the parameter is
    // now conditional; without it those models run at the API default (Decision #1185).
    $acceptsTemperature = in_array($modelId, ['claude-sonnet-4-6', 'claude-haiku-4-5-20251001'], true);

    $payload = [
        'model'       => $modelId,
        'max_tokens'  => 2048,
        'system'      => [
            [
                'type'          => 'text',
                'text'          => $systemPrompt,
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ],
        'messages'    => [['role' => 'user', 'content' => $userMessage]],
    ];
    if ($acceptsTemperature) {
        // tighter than live pipeline — recalibration wants consistency
        $payload['temperature'] = 0.4;
    }
    $body = json_encode($payload);

    $ch = llm_curl_init();
    llm_curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw  = llm_curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);

    if ($err) {
        return ['error' => "curl: {$err}"];
    }
    if ($code !== 200) {
        return ['error' => "http {$code}: " . mb_substr((string) $raw, 0, 300)];
    }
    $data = json_decode($raw, true);
    // Opus 5.5: replies may open with thinking blocks — keep only text blocks so content[0] is the first text block.
    if (is_array($data) && isset($data['content']) && is_array($data['content'])) { $data['content'] = array_values(array_filter($data['content'], function ($b) { return ($b['type'] ?? '') === 'text'; })); }
    if (!$data || !isset($data['content'][0]['text'])) {
        return ['error' => 'unexpected response shape'];
    }
    return [
        'text'                        => $data['content'][0]['text'],
        'input_tokens'                => $data['usage']['input_tokens']                   ?? null,
        'output_tokens'               => $data['usage']['output_tokens']                  ?? null,
        'cache_creation_input_tokens' => $data['usage']['cache_creation_input_tokens']    ?? null,
        'cache_read_input_tokens'     => $data['usage']['cache_read_input_tokens']        ?? null,
    ];
}

// ===== THRESHOLD SIMULATOR (mirrors updateWheelPillar, READ-ONLY) =====
/**
 * Given the current DB state and a proposed update, return the outcome
 * category and the values that WOULD be written — without writing anything.
 * Used for dry-run logging and as the authoritative description of what
 * `updateWheelPillar` will do on --apply.
 */
function simulateThreshold(
    string $currentLevel,
    int    $currentAccum,
    string $proposedLevel,
    string $confidence,
    array  $levelOrder
): array {
    $currentOrder  = $levelOrder[strtolower($currentLevel)]  ?? 0;
    $proposedOrder = $levelOrder[strtolower($proposedLevel)] ?? 0;

    if ($proposedOrder === $currentOrder) {
        return [
            'outcome'         => 'nochange',
            'new_level'       => $currentLevel,
            'new_accumulator' => $currentAccum,
            'level_applied'   => false,
        ];
    }

    if (strtolower($confidence) === 'high') {
        return [
            'outcome'         => 'applied_high_conf',
            'new_level'       => $proposedLevel,
            'new_accumulator' => 0,
            'level_applied'   => true,
        ];
    }

    // Low confidence — signed accumulator.
    $proposedDir = $proposedOrder > $currentOrder ? 1 : -1;
    $currentDir  = $currentAccum > 0 ? 1 : ($currentAccum < 0 ? -1 : 0);

    if ($currentDir !== 0 && $currentDir !== $proposedDir) {
        return [
            'outcome'         => 'cancelled_opposite',
            'new_level'       => $currentLevel,
            'new_accumulator' => 0,
            'level_applied'   => false,
        ];
    }

    $newAccum = $currentAccum + $proposedDir;
    if (abs($newAccum) >= 2) {
        return [
            'outcome'         => 'applied_threshold_crossed',
            'new_level'       => $proposedLevel,
            'new_accumulator' => 0,
            'level_applied'   => true,
        ];
    }

    return [
        'outcome'         => 'pending_accumulate',
        'new_level'       => $currentLevel,
        'new_accumulator' => $newAccum,
        'level_applied'   => false,
    ];
}

// ===== USER LIST =====
$pdo = new PDO('sqlite:' . (
    $surface === 'telegram'
        ? __DIR__ . '/companion-data.db'
        : (file_exists(__DIR__ . '/../website-companion-data.db')
            ? __DIR__ . '/../website-companion-data.db'
            : __DIR__ . '/../../website-companion-data.db')
));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$minMessages = max(0, (int) $opts['min_messages']);
if ($opts['user'] !== null) {
    $stmt = $pdo->prepare("SELECT {$idField} FROM {$tableName} WHERE {$idField} = ?");
    $stmt->execute([$opts['user']]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $sql = "SELECT {$idField} FROM {$tableName} WHERE message_count >= ? ORDER BY message_count DESC";
    if ($opts['limit']) $sql .= ' LIMIT ' . (int) $opts['limit'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$minMessages]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$resolvedModel = resolveModelId($opts['model']);

emit([
    'type'         => 'start',
    'surface'      => $surface,
    'mode'         => $opts['mode'],
    'user_count'   => count($rows),
    'min_messages' => $minMessages,
    'window'       => $opts['window'],
    'summaries'    => $opts['summaries'],
    'model_alias'  => $opts['model'],
    'model_id'     => $resolvedModel,
]);

// ===== MAIN LOOP =====
$counters = [
    'users_processed'      => 0,
    'users_skipped'        => 0,
    'users_errored'        => 0,
    'users_nochange'       => 0,
    'api_errors'           => 0,
    'pillar_nochange'               => 0,
    'pillar_applied_high_conf'      => 0,
    'pillar_cancelled_opposite'     => 0,
    'pillar_applied_threshold_crossed' => 0,
    'pillar_pending_accumulate'     => 0,
    'total_input_tokens'          => 0,
    'total_output_tokens'         => 0,
    'total_cache_creation_tokens' => 0,
    'total_cache_read_tokens'     => 0,
];

foreach ($rows as $userId) {
    try {
        $castedId = $idCastFn($userId);

        // Pull current state
        $wheel      = $memory->getWheelProfile($castedId);
        $recentText = $memory->getRecentMessagesText($castedId, $opts['window']);
        $summaries  = $memory->getConversationSummaries($castedId, $opts['summaries']);

        // Guard: nothing to recalibrate
        if (empty($wheel) || trim($recentText) === '') {
            $counters['users_skipped']++;
            emit([
                'type'   => 'skip',
                'user'   => $castedId,
                'reason' => empty($wheel) ? 'no_wheel_profile' : 'no_recent_messages',
            ]);
            continue;
        }

        // Build current wheel state string
        $currentStateLines = [];
        foreach ($wheel as $pillar => $data) {
            $line = "{$pillar}: {$data['engagement_level']}";
            if (!empty($data['primary_concerns'])) $line .= " (concerns: {$data['primary_concerns']})";
            if (!empty($data['strengths']))        $line .= " (strengths: {$data['strengths']})";
            if (!empty($data['growth_edges']))     $line .= " (edges: {$data['growth_edges']})";
            $currentStateLines[] = $line;
        }
        $currentWheelStr = implode("\n", $currentStateLines);

        // Build summaries block
        $summaryLines = [];
        foreach ($summaries as $s) {
            $summaryLines[] = "[{$s['period_start']}→{$s['period_end']}] "
                . ($s['pillars_discussed'] ? "pillars: {$s['pillars_discussed']}. " : '')
                . $s['summary'];
        }
        $summaryBlock = empty($summaryLines)
            ? "(none)"
            : implode("\n\n", $summaryLines);

        $userMessage =
            "Current Wheel profile:\n{$currentWheelStr}\n\n"
            . "Prior conversation summaries (oldest→newest):\n{$summaryBlock}\n\n"
            . "Recent conversation (most recent {$opts['window']} messages):\n{$recentText}\n\n"
            . "Re-evaluate the recorded levels against this history. Apply the EVIDENCE DISCIPLINE rules. Only include pillars where the recent evidence supports a level change or where contradiction with the recorded level is present. Omit pillars where the evidence simply confirms the current level.\n\n"
            . "CRITICAL: Respond with ONLY the JSON object. No preamble, no explanation, no reasoning aloud. The first character of your response must be `{` and the last must be `}`. If no updates are warranted, respond with `{}`.";

        $result = callClaudeApi($resolvedModel, $SYSTEM_PROMPT, $userMessage);
        if (!$result || isset($result['error'])) {
            $counters['api_errors']++;
            $counters['users_errored']++;
            emit([
                'type'  => 'api_error',
                'user'  => $castedId,
                'error' => $result['error'] ?? 'null result',
            ]);
            continue;
        }

        $counters['total_input_tokens']          += (int) ($result['input_tokens']                ?? 0);
        $counters['total_output_tokens']         += (int) ($result['output_tokens']               ?? 0);
        $counters['total_cache_creation_tokens'] += (int) ($result['cache_creation_input_tokens'] ?? 0);
        $counters['total_cache_read_tokens']     += (int) ($result['cache_read_input_tokens']     ?? 0);

        // Parse JSON. Opus often preambles before the object — the salvage regex
        // below handles that.
        $text = trim($result['text']);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $updates = json_decode($text, true);
        if (!is_array($updates)) {
            // Salvage: extract the largest balanced {...} block. Handles cases where
            // the model still preambles or trails prose despite pre-fill.
            if (preg_match('/\{.*\}/s', $text, $m)) {
                $updates = json_decode($m[0], true);
            }
        }
        if (!is_array($updates)) {
            $counters['api_errors']++;
            $counters['users_errored']++;
            emit([
                'type'         => 'api_error',
                'user'         => $castedId,
                'error'        => 'json_parse_failed',
                'raw_preview'  => mb_substr($text, 0, 300),
            ]);
            continue;
        }

        if (empty($updates)) {
            $counters['users_nochange']++;
            emit(['type' => 'nochange', 'user' => $castedId]);
            $counters['users_processed']++;
            continue;
        }

        // Per-pillar simulation + optional apply
        $pillarList = ['presence', 'health', 'matter', 'service', 'relationships', 'learning', 'nature', 'recreation'];
        foreach ($updates as $pillar => $data) {
            $pillar = strtolower(trim((string) $pillar));
            if (!in_array($pillar, $pillarList, true)) continue;
            if (!is_array($data)) continue;

            $currentLevel  = $wheel[$pillar]['engagement_level']    ?? 'unknown';
            $currentAccum  = (int) ($wheel[$pillar]['signal_accumulator'] ?? 0);
            $proposedLevel = strtolower($data['engagement_level']   ?? $currentLevel);
            $confidence    = strtolower($data['level_change_confidence'] ?? 'low');

            $sim = simulateThreshold(
                $currentLevel, $currentAccum,
                $proposedLevel, $confidence,
                $levelOrder
            );

            $counterKey = 'pillar_' . $sim['outcome'];
            if (isset($counters[$counterKey])) $counters[$counterKey]++;

            emit([
                'type'            => 'pillar',
                'user'            => $castedId,
                'pillar'          => $pillar,
                'current_level'   => $currentLevel,
                'current_accum'   => $currentAccum,
                'proposed_level'  => $proposedLevel,
                'confidence'      => $confidence,
                'outcome'         => $sim['outcome'],
                'new_level'       => $sim['new_level'],
                'new_accumulator' => $sim['new_accumulator'],
                'primary_concerns' => $data['primary_concerns'] ?? '',
                'strengths'       => $data['strengths']        ?? '',
                'growth_edges'    => $data['growth_edges']     ?? '',
                'resistance_noted' => !empty($data['resistance_noted']),
            ]);

            if ($opts['mode'] === 'apply') {
                // Delegate to the canonical memory method — threshold runs once,
                // inside the method. Pass the Claude proposal intact (including
                // level_change_confidence, which updateWheelPillar will strip).
                $memory->updateWheelPillar($castedId, $pillar, $data);
            }
        }

        $counters['users_processed']++;
    } catch (\Throwable $e) {
        $counters['users_errored']++;
        emit([
            'type'  => 'error',
            'user'  => isset($castedId) ? $castedId : (string) $userId,
            'error' => $e->getMessage(),
        ]);
    }
}

emit(array_merge(['type' => 'complete'], $counters));
