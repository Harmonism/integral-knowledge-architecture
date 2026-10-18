#!/usr/bin/env php
<?php
require_once __DIR__ . '/llm-gateway.php';  // model provider switch (LLM_PROVIDER in server config)
/**
 * Companion Response Queue Processor (Decision #277, updated #XXX)
 *
 * Persistent CLI worker that polls for pending Claude API jobs queued by the
 * webhook handler. Runs as a long-lived process with 3-second polling interval.
 * A cron watchdog restarts it if it dies (see watchdog-queue.sh).
 *
 * Architecture:
 *   telegram-bot.php (webhook) → queues job in SQLite (with model choice) → exits
 *   process-queue.php (worker) → claims job → calls Claude (Opus or Haiku) → sends Telegram → loops
 *
 * This decoupling eliminates the LiteSpeed timeout that was killing long responses.
 * The persistent worker eliminates the 0-60s cron latency of the previous design.
 *
 * Usage:
 *   php process-queue.php              — Run as persistent worker (default)
 *   php process-queue.php --once       — Process one job and exit
 *   php process-queue.php --status     — Show queue status
 *   php process-queue.php --costs      — Show per-user cost report (all time)
 *   php process-queue.php --costs 30   — Show per-user cost report (last N days)
 *   php process-queue.php --cron       — Legacy cron mode (process batch and exit)
 *
 * Cron watchdog (every 5 minutes):
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * /path/to/watchdog-queue.sh
 */

// Persistent worker can run for hours; set generous limit
set_time_limit(0);
ignore_user_abort(true);

// ===== ERROR LOGGING =====
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/companion-errors.log');

// ===== CONFIGURATION =====
// Polling interval — 500ms in microseconds. Earlier value of 3s added an
// average ~1.5s of dead-time wait between webhook queueing a job and the
// worker claiming it. SQLite read on the indexed status column is cheap;
// the bottleneck is the Claude API call, not the poll. Keeping the wait
// short cuts a meaningful slice of perceived latency on every message.
define('POLL_INTERVAL_US', 500_000);  // 0.5s between queue checks
define('MAX_RUNTIME', 3600);          // Max worker lifetime in seconds (1 hour), then exit for watchdog restart
define('MAX_JOBS_PER_CYCLE', 10);     // Safety cap per poll cycle

// ===== LODESTONE CORE VERSION =====
// The behavioural contract this surface delivers against. Bump when the
// doctrinal backbone, either system prompt, the routing policy, or the memory
// schema changes — and bump BOTH surfaces together (telegram-bot.php /
// process-queue.php and companion-api.php carry the same constant). The
// prompt_hash stamped beside it on every assistant row is the ground truth: two
// surfaces declaring the same Core while carrying different hashes have
// drifted, and the data will say so. Release ladder lives in the vault.
// 0.9.0 = pre-reliability-contract.
// 0.9.1 = system prompts aligned to doctrinal backbone v2.4 (count grammar, the
//         twelve-pillar Architecture, the sub-wheel centres) + spoken replies on
//         Telegram (users.voice_mode, response_queue.inbound_voice/user_lang).
if (!defined('MUNAI_CORE_VERSION')) {
    define('MUNAI_CORE_VERSION', '0.9.2');
}

// ===== STREAMING + CACHE CONSTANTS =====
// These MUST be defined at the top of the file, before the worker loop
// reaches any code path that calls processJob/callClaude/callClaudeStream.
// Top-level `const` declarations in PHP are processed when execution
// reaches the line, not at compile time — declaring them later in the
// file produces "Undefined constant" fatals on every job. (Use define()
// here for the same reason the constants above use define(): unambiguous
// runtime registration, no ordering surprises.)

// Master switch for SSE pseudo-streaming. When true, the placeholder is
// edited every ~1.1s with the accumulated text as Claude generates;
// finalizeStreamedDelivery applies parse_mode=Markdown on the final pass.
// When false, the placeholder remains until the call completes, then is
// replaced in one shot. STREAM_DIAG log lines fire on any stream failure
// (HTTP code, byte count, raw head sample) for post-mortem.
define('ENABLE_SSE_STREAMING', true);

// Pseudo-streaming pacing — minimum seconds between editMessageText calls
// mid-stream. Telegram's per-chat rate limit is roughly 1 message/second
// for private chats; 1.1s leaves margin while still surfacing the
// response as it forms. The final edit ignores this gate.
define('MIN_EDIT_GAP_SEC', 1.1);

// Maximum mid-stream display length before truncating with ellipsis.
// Telegram caps message text at 4096 chars; we leave 6 chars headroom.
define('STREAM_DISPLAY_CAP', 4090);

// Cache breakpoint marker — buildSystemPrompt() in telegram-bot.php inserts
// this between the static prefix (persona heredoc + doctrinal backbone)
// and the per-call dynamic suffix. callClaude/callClaudeStream split on it
// and wrap the prefix as cache_control:ephemeral with 1h TTL so the
// ~27K-token cached prefix (persona ~11K + doctrinal backbone ~15K + tags)
// hits the prompt cache at 0.1x cost on warm calls within the 1-hour
// window. 1h TTL: create=2x base input, read=0.1x (vs default 5min:
// create=1.25x, read=0.1x). Break-even at <1 warm hit per session — wins
// for any practitioner who returns within 60 minutes. Learning-call sites
// stay at default 5min since they fire every 10 messages and may not see
// repeated hits within an hour.
define('CACHE_BREAKPOINT_MARKER', "\n\n<!-- CACHE_BREAKPOINT -->\n");

// ===== EARLY CLI FLAG HANDLING (before lock) =====
$mode = $argv[1] ?? '';

// --status and --costs bypass the lock — they're read-only diagnostics
if ($mode === '--status') {
    require_once __DIR__ . '/telegram-memory.php';
    require_once __DIR__ . '/telegram-rag.php';
    require_once __DIR__ . '/companion-classifier.php';
    $configPath = '/home/SERVER_USER/.telegram-config.php';
    if (!file_exists($configPath)) { echo "ERROR: Config not found\n"; exit(1); }
    require_once $configPath;
    $memory = new CompanionMemory(__DIR__ . '/companion-data.db');
    showStatus($memory);
    exit(0);
}

if ($mode === '--costs') {
    require_once __DIR__ . '/telegram-memory.php';
    $memory = new CompanionMemory(__DIR__ . '/companion-data.db');
    $days = isset($argv[2]) ? (int) $argv[2] : null;
    showCosts($memory, $days);
    exit(0);
}

// ===== LOCK FILE — prevent concurrent workers =====
$lockFile = __DIR__ . '/queue-processor.lock';
$lockFp = fopen($lockFile, 'c');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Another processor is already running
    if ($mode === '--cron') {
        // Safety-net cron: worker is alive, nothing to do
        exit(0);
    }
    echo "Worker already running (see queue-processor.lock)\n";
    exit(0);
}

// Write PID to lock file for diagnostics
ftruncate($lockFp, 0);
fwrite($lockFp, (string) getmypid());

// ===== LOAD DEPENDENCIES =====
require_once __DIR__ . '/telegram-memory.php';
require_once __DIR__ . '/telegram-rag.php';
require_once __DIR__ . '/companion-classifier.php';
if (file_exists(__DIR__ . '/companion-voice.php')) {
    require_once __DIR__ . '/companion-voice.php';
}
if (file_exists(__DIR__ . '/companion-membership.php')) {
    require_once __DIR__ . '/companion-membership.php';
}

// ===== LOAD CONFIG =====
$configPath = '/home/SERVER_USER/.telegram-config.php';
if (!file_exists($configPath)) {
    error_log("[worker] Config not found at {$configPath}");
    exit(1);
}
require_once $configPath;

if (!defined('TELEGRAM_BOT_TOKEN') || !defined('ANTHROPIC_API_KEY') || !defined('COMPANION_MODEL')) {
    error_log("[worker] Config incomplete");
    exit(1);
}

// ===== INITIALIZE =====
$memory = new CompanionMemory(__DIR__ . '/companion-data.db');

// Reset any jobs stuck in 'processing' for more than 5 minutes (crashed processor)
$resetCount = $memory->resetStuckJobs(300);
if ($resetCount > 0) {
    error_log("[worker] Reset {$resetCount} stuck job(s)");
}

// Prune completed/failed jobs older than 24 hours
$memory->pruneQueue(86400);

// ===== LEGACY CRON MODE =====
if ($mode === '--cron') {
    $jobsProcessed = 0;
    while ($jobsProcessed < MAX_JOBS_PER_CYCLE) {
        $job = $memory->claimNextJob();
        if (!$job) break;
        processJob($job, $memory);
        $jobsProcessed++;
    }
    if ($jobsProcessed > 0) {
        error_log("[worker] Cron mode: processed {$jobsProcessed} job(s)");
    }
    releaseLock($lockFp, $lockFile);
    exit(0);
}

// ===== SINGLE JOB MODE =====
if ($mode === '--once') {
    $job = $memory->claimNextJob();
    if ($job) {
        processJob($job, $memory);
    }
    releaseLock($lockFp, $lockFile);
    exit(0);
}

// ===== PERSISTENT WORKER MODE (default) =====
$startTime = time();
error_log("[worker] Starting persistent worker (PID " . getmypid() . "), max runtime " . MAX_RUNTIME . "s");

// Install signal handlers for graceful shutdown
$shutdownRequested = false;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() use (&$shutdownRequested) {
        $shutdownRequested = true;
        error_log("[worker] SIGTERM received, shutting down gracefully");
    });
    pcntl_signal(SIGINT, function() use (&$shutdownRequested) {
        $shutdownRequested = true;
        error_log("[worker] SIGINT received, shutting down gracefully");
    });
}

$totalJobsProcessed = 0;

while (true) {
    // Check shutdown conditions
    if ($shutdownRequested) {
        error_log("[worker] Shutdown requested, exiting after {$totalJobsProcessed} jobs");
        break;
    }

    if ((time() - $startTime) >= MAX_RUNTIME) {
        error_log("[worker] Max runtime reached ({$totalJobsProcessed} jobs processed), exiting for watchdog restart");
        break;
    }

    // Process signals if pcntl is available
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    // Poll for jobs
    $jobsThisCycle = 0;
    while ($jobsThisCycle < MAX_JOBS_PER_CYCLE) {
        $job = $memory->claimNextJob();
        if (!$job) break;
        processJob($job, $memory);
        $jobsThisCycle++;
        $totalJobsProcessed++;
    }

    // Periodic maintenance (every ~60 seconds worth of polls)
    if ($totalJobsProcessed > 0 && $totalJobsProcessed % 20 === 0) {
        $memory->pruneQueue(86400);
        $resetCount = $memory->resetStuckJobs(300);
        if ($resetCount > 0) {
            error_log("[worker] Reset {$resetCount} stuck job(s) during maintenance");
        }
    }

    // Sleep before next poll
    usleep(POLL_INTERVAL_US);
}

releaseLock($lockFp, $lockFile);
exit(0);


// ==========================================================================
// FUNCTIONS
// ==========================================================================

/**
 * Process a single queued job.
 *
 * Flow:
 *   1. Send a placeholder message; capture the message_id.
 *   2. Stream the Claude API call. The SSE write-callback edits the
 *      placeholder every ~1.1s with the accumulated text (no parse_mode —
 *      mid-stream Markdown is unreliable).
 *   3. After the stream completes, scrub leading timestamps and finalize:
 *      edit the placeholder with parse_mode=Markdown for the first chunk,
 *      send overflow chunks as new messages.
 *   4. Store the assistant message, run profile learning + consolidation
 *      (the user already has their answer at this point — these run as
 *      background work without blocking perceived latency).
 *   5. Mark the job complete with token + cost telemetry.
 *
 * Falls back to non-streaming callClaude if the placeholder send fails or
 * the stream returns an error before producing any text.
 */
function processJob(array $job, CompanionMemory $memory): void {
    $jobId = $job['id'];
    $chatId = (int) $job['chat_id'];
    $systemPrompt = $job['system_prompt'];
    $history = $job['history'];
    // Use model from queue if set, otherwise fall back to config constant
    $model = $job['model'] ?? COMPANION_MODEL;
    // Image data for multimodal messages (Decision #399)
    $imageData = isset($job['image_data']) ? json_decode($job['image_data'], true) : null;

    $startTime = microtime(true);
    error_log("[worker] Processing job #{$jobId} for chat {$chatId} (model={$model}" . ($imageData ? ", has_image" : "") . ")");

    // Send typing indicator (Telegram auto-clears it after ~5s; the
    // placeholder edit cycle keeps the chat lively without further refresh).
    sendTelegramAction($chatId, 'typing');

    // ===== STEP 1: SEND PLACEHOLDER =====
    // The placeholder gives us a message_id we can edit as the stream
    // produces deltas. If the send fails (network, Telegram outage),
    // we fall back to non-streaming and return one block at the end.
    $placeholderMsgId = null;
    $placeholderResp = sendTelegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text'    => '…',
        'disable_web_page_preview' => true,
    ]);
    if (is_array($placeholderResp) && ($placeholderResp['ok'] ?? false)) {
        $placeholderMsgId = $placeholderResp['result']['message_id'] ?? null;
    }
    error_log("[worker] Job #{$jobId} — placeholder " . ($placeholderMsgId !== null
        ? "sent msg_id={$placeholderMsgId}"
        : "FAILED resp=" . substr(json_encode($placeholderResp), 0, 200)));

    // ===== STEP 2: GENERATE THE RESPONSE =====
    // Two paths:
    //   (a) ENABLE_SSE_STREAMING=true — open a streaming Claude call, edit the
    //       placeholder every ~1.1s with the accumulated text, finalise in
    //       finalizeStreamedDelivery() with parse_mode=Markdown.
    //   (b) ENABLE_SSE_STREAMING=false — skip streaming, call Claude normally;
    //       the placeholder remains visible for the duration of the call,
    //       then gets replaced by finalizeStreamedDelivery() with the full
    //       response. User sees "…" instantly + sudden full reply.
    //
    // The placeholder + finalize loop runs identically in both modes, so the
    // user-facing UX during the initial "waiting" phase is the same. Only the
    // mid-call editMessageText behaviour differs.
    $apiResult = null;
    $usedFallback = false;
    // The practitioner's model choice covers this reply only (Decision #1329).
    if (function_exists('llm_set_user_model') && method_exists($memory, 'getModelChoice')) {
        llm_set_user_model($memory->getModelChoice($chatId) ?: null);
    }

    if (ENABLE_SSE_STREAMING && $placeholderMsgId !== null) {
        $lastEditAt   = microtime(true);
        $lastSentText = '';

        $apiResult = callClaudeStream(
            $systemPrompt, $history, $model, $imageData, true,
            function (string $text, bool $isFinal) use ($chatId, $placeholderMsgId, &$lastEditAt, &$lastSentText) {
                $clean = stripLeadingTimestamp($text);
                if ($clean === '' || $clean === $lastSentText) return;
                $now = microtime(true);
                if (!$isFinal && ($now - $lastEditAt) < MIN_EDIT_GAP_SEC) return;
                if ($isFinal) return;  // finalizeStreamedDelivery handles the final edit.

                $display = mb_strlen($clean) > STREAM_DISPLAY_CAP
                    ? mb_substr($clean, 0, STREAM_DISPLAY_CAP) . '…'
                    : $clean;

                sendTelegramRequest('editMessageText', [
                    'chat_id'    => $chatId,
                    'message_id' => $placeholderMsgId,
                    'text'       => $display,
                    'disable_web_page_preview' => true,
                ]);
                $lastEditAt   = $now;
                $lastSentText = $clean;
            }
        );

        if ($apiResult === null) {
            error_log("[worker] Job #{$jobId} — streaming returned null, falling back to non-streaming");
            $usedFallback = true;
        }
    }

    // Either streaming is disabled, or it failed. Use the non-streaming path.
    if ($apiResult === null) {
        error_log("[worker] Job #{$jobId} — calling callClaude (non-streaming, splitCache=true)");
        $apiResult = callClaude($systemPrompt, $history, $model, $imageData, false, true);
        error_log("[worker] Job #{$jobId} — callClaude returned " . ($apiResult === null
            ? 'NULL'
            : 'text_len=' . mb_strlen($apiResult['text'] ?? '')
              . ' in=' . ($apiResult['input_tokens'] ?? '?')
              . ' out=' . ($apiResult['output_tokens'] ?? '?')));
    }

    if (function_exists('llm_set_user_model')) llm_set_user_model(null);
    $apiElapsed = round(microtime(true) - $startTime, 1);

    if (!$apiResult) {
        error_log("[worker] Job #{$jobId} FAILED — Claude returned null after {$apiElapsed}s (model={$model})");

        $errMsg = "I encountered a difficulty processing that. Please try again in a moment.";
        if ($placeholderMsgId !== null) {
            sendTelegramRequest('editMessageText', [
                'chat_id'    => $chatId,
                'message_id' => $placeholderMsgId,
                'text'       => $errMsg,
                'disable_web_page_preview' => true,
            ]);
        } else {
            sendTelegramMessage($chatId, $errMsg);
        }

        if ($job['attempts'] >= 3) {
            $memory->failJob($jobId, "Claude API returned null after {$job['attempts']} attempts");
        } else {
            $memory->failJob($jobId, "Attempt {$job['attempts']} failed — will not retry");
        }
        return;
    }

    $response     = $apiResult['text'];
    $inputTokens  = $apiResult['input_tokens'];
    $outputTokens = $apiResult['output_tokens'];
    $cacheCreate  = $apiResult['cache_creation_input_tokens'] ?? null;
    $cacheRead    = $apiResult['cache_read_input_tokens']     ?? null;
    $stopReason   = $apiResult['stop_reason']                 ?? null;

    // ===== TIMESTAMP PREFIX SCRUB (belt-and-suspenders against Decision #386 pattern leak) =====
    // Past eras prefixed conversation-history messages with "[YYYY-MM-DD HH:MM UTC]" to give the
    // model temporal awareness. The pattern leaked back out: the model mirrored its own prior
    // outputs and began each reply with a bracketed timestamp, overriding every system-prompt
    // instruction ("NEVER open with a timestamp"). Prompt-level guards lose to in-context
    // imitation of the model's own prior turns. The scrub below is the actual guarantee: no
    // matter what the model produces, the leading timestamp is stripped before we store or send.
    // Without this, a single contaminated stored reply re-seeds the pattern on the next turn.
    $response = stripLeadingTimestamp($response);

    // Compute cost in USD using Anthropic pricing (per million tokens).
    // Cached input is billed differently: cache_creation at 1.25x base input,
    // cache_read at 0.1x base input. The $inputTokens field reports
    // *uncached* input; we add the differentiated rates on top.
    //   Opus 5: input $5/M, output $25/M, cache_create $6.25/M, cache_read $0.50/M
    //   Haiku 4.5: input $1/M, output $5/M, cache_create $1.25/M, cache_read $0.10/M
    $costUsd = null;
    if ($inputTokens !== null && $outputTokens !== null) {
        if ($model === MODEL_HAIKU) {
            $inputRate = 1.00;
            $outputRate = 5.00;
        } else {
            $inputRate = 5.00;
            $outputRate = 25.00;
        }
        $costUsd =
              ($inputTokens             * $inputRate         / 1_000_000)
            + ($outputTokens            * $outputRate        / 1_000_000)
            + (((int) $cacheCreate)     * $inputRate * 1.25  / 1_000_000)
            + (((int) $cacheRead)       * $inputRate * 0.10  / 1_000_000);
        $costUsd = round($costUsd, 6);
    }

    $cacheLog = '';
    if ($cacheCreate !== null || $cacheRead !== null) {
        $cacheLog = ", cache_create={$cacheCreate}, cache_read={$cacheRead}";
    }
    $streamLog = $usedFallback ? ', stream=fallback' : ', stream=ok';
    $stopLog   = $stopReason ? ", stop={$stopReason}" : '';
    error_log("[worker] Job #{$jobId} — Claude responded in {$apiElapsed}s (" . mb_strlen($response) . " chars, model={$model}, in={$inputTokens}, out={$outputTokens}{$cacheLog}{$streamLog}{$stopLog}, cost=\${$costUsd})");

    // ===== STEP 3: FINAL DELIVERY =====
    // Apply Markdown parse_mode and split overflow if response > 4096 chars.
    if ($placeholderMsgId !== null) {
        // Whether streaming succeeded or fell back to non-streaming, the
        // placeholder exists in the chat. Reuse it as the host for the final
        // response so the user sees a single message that finalised, not a
        // ghost placeholder followed by a fresh message.
        error_log("[worker] Job #{$jobId} — calling finalizeStreamedDelivery (msg_id={$placeholderMsgId}, response_len=" . mb_strlen($response) . ", stop_reason=" . ($stopReason ?? '-') . ")");
        finalizeStreamedDelivery($chatId, $placeholderMsgId, $response, $stopReason);
        error_log("[worker] Job #{$jobId} — finalizeStreamedDelivery returned");
    } else {
        // No placeholder ever created (initial sendMessage failed) — deliver
        // as a fresh message. Append truncation marker here too so the
        // practitioner gets the same continuation signal regardless of which
        // delivery path the worker took.
        error_log("[worker] Job #{$jobId} — no placeholder, sending fresh message");
        $deliveryText = $response;
        if ($stopReason === 'max_tokens') {
            $deliveryText = rtrim($deliveryText) . "\n\n" . '_— response continues; say "go on" to continue —_';
            error_log("[worker] truncation marker appended on fresh-message path (chat_id={$chatId})");
        }
        sendTelegramMessage($chatId, $deliveryText);
    }

    $userVisibleAt = round(microtime(true) - $startTime, 1);

    // ===== STEP 4: STORE + BACKGROUND LEARNING =====
    // Order matters: the user has already SEEN the response by now.
    // Profile learning + consolidation run after delivery so they don't
    // contribute to the latency the practitioner experiences. They still
    // block the next job in the queue (single-worker bottleneck), but
    // for the practitioner's own next message this only matters when
    // they reply within seconds — and on that timescale the previous
    // message's learning was almost certainly going to run regardless.
    // Stamp the row with the Lodestone that produced it: declared Core version,
    // resolved model ID, and a fingerprint of the static system-prompt prefix.
    $memory->addMessage($chatId, 'assistant', $response, null, [
        'core_version' => MUNAI_CORE_VERSION,
        'model'        => $model,
        'prompt_hash'  => lodestonePromptHash($systemPrompt),
    ]);

    // ===== STEP 4b: SPOKEN REPLY (optional, additive) =====
    // The text is delivered and stored by this point. The voice is an extra
    // message after it and never a substitute: nothing here can delay, alter or
    // withhold the answer the practitioner is owed (#1145). Witness mode is read
    // off the system prompt the webhook built — in acute activation Lodestone stays
    // in text (#535, #1139). Crisis replies never reach this worker at all.
    if (function_exists('lodestoneVoiceSendReply')) {
        $voiceMode = $memory->getVoiceMode($chatId);
        if ($voiceMode !== 'off') {
            $xaiKey = defined('XAI_API_KEY') && XAI_API_KEY !== '' && !str_contains(XAI_API_KEY, 'YOUR-') ? XAI_API_KEY : '';
            $voice = lodestoneVoiceSendReply($chatId, $response, [
                'mode'           => $voiceMode,
                'inbound_voice'  => (bool) ($job['inbound_voice'] ?? 0),
                'witness'        => str_contains($systemPrompt, 'witness_mode_active'),
                'lang'           => (string) ($job['user_lang'] ?? 'en'),
                'has_key'        => $xaiKey !== '',
                'api_key'        => $xaiKey,
                'voice'          => $memory->getVoiceGender($chatId),
                'chars_used_24h' => $memory->getVoiceCharsUsed24h($chatId),
            ]);
            if ($voice['sent']) {
                $memory->addVoiceChars($chatId, $voice['chars']);
            } elseif ($voice['reason'] !== 'mode_off') {
                error_log("[worker] Job #{$jobId} — voice skipped ({$voice['reason']})");
            }
        }
    }

    // Increment Harmonia Membership 24h rolling counter (Telegram surface).
    // Done after successful Claude completion + delivery so failed turns
    // don't burn the daily allowance. Tier downgrades + reset logic are
    // handled in membership_check_rate_limit_telegram in telegram-bot.php.
    if (function_exists('membership_increment_telegram')) {
        membership_increment_telegram($memory->getDb(), $chatId);
    }

    if ($job['do_profile_learning']) {
        error_log("[worker] Job #{$jobId} — running profile learning (post-delivery)");
        try {
            updateUserProfile($memory, $chatId);
            updateWheelProfile($memory, $chatId);
            if (method_exists($memory, 'updateConversationContext')) {
                updateConversationContext($memory, $chatId);
            }
        } catch (\Exception $e) {
            error_log("[worker] Profile learning failed for chat {$chatId}: " . $e->getMessage());
        }
    }

    if ($job['do_consolidation']) {
        error_log("[worker] Job #{$jobId} — running consolidation (post-delivery)");
        try {
            consolidateConversation($memory, $chatId);
        } catch (\Exception $e) {
            error_log("[worker] Consolidation failed for chat {$chatId}: " . $e->getMessage());
        }
    }

    $totalElapsed = round(microtime(true) - $startTime, 1);
    error_log("[worker] Job #{$jobId} COMPLETED in {$totalElapsed}s (API: {$apiElapsed}s, user-visible: {$userVisibleAt}s, model={$model})");

    $memory->completeJob($jobId, $response, $inputTokens, $outputTokens, $costUsd);
}


/**
 * Replace the streaming placeholder with the final response, applying
 * Markdown parse_mode and splitting at paragraph boundaries if the
 * response exceeds Telegram's 4096-char limit.
 *
 * Strategy: first chunk replaces the placeholder via editMessageText;
 * subsequent chunks (if any) go out as fresh sendMessage calls. This
 * preserves the streaming UX (the response visibly forms in the
 * placeholder) while still handling long responses.
 *
 * When $stopReason === 'max_tokens', a truncation marker is appended to the
 * delivered text (visible to the practitioner) but NOT mutated into the
 * caller's $response — the caller stores the unmarked text via addMessage
 * so future Claude calls don't see the marker and start mirroring it back.
 * Marker is currently English-only; per-language map is a follow-up.
 */
function finalizeStreamedDelivery(int $chatId, int $placeholderMsgId, string $response, ?string $stopReason = null): void {
    // Append truncation marker for delivery only when Claude was cut off at
    // the output cap. Italic via Telegram Markdown (legacy) — underscores
    // safe because the marker text contains none. Em-dashes for visual
    // distinction even if italic styling fails.
    if ($stopReason === 'max_tokens') {
        $marker = '_— response continues; say "go on" to continue —_';
        $response = rtrim($response) . "\n\n" . $marker;
        error_log("[worker] truncation marker appended (stop_reason=max_tokens, chat_id={$chatId})");
    }

    $maxLen = 4096;
    $chunks = mb_strlen($response) > $maxLen
        ? splitMessage($response, $maxLen)
        : [$response];

    if (empty($chunks) || $chunks[0] === '') {
        error_log("[worker] finalizeStreamedDelivery: empty response, nothing to deliver (placeholder will stay as '…')");
        return;
    }

    // First chunk → edit the placeholder.
    $first = $chunks[0];
    $editResult = sendTelegramRequest('editMessageText', [
        'chat_id'    => $chatId,
        'message_id' => $placeholderMsgId,
        'text'       => $first,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true,
    ]);
    $editOk = is_array($editResult) && ($editResult['ok'] ?? false);
    if (!$editOk) {
        error_log("[worker] finalizeStreamedDelivery: edit FAILED resp=" . substr(json_encode($editResult), 0, 300));
    }

    // Overflow chunks → send as fresh messages, paced to preserve order.
    $count = count($chunks);
    for ($i = 1; $i < $count; $i++) {
        sendTelegramRequest('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => $chunks[$i],
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ]);
        usleep(300000);
    }
}


/**
 * Strip any leading metadata-style prefix from a model output.
 *
 * The model has repeatedly mirrored bracketed metadata patterns from
 * conversation history into its own replies, despite every system-prompt
 * instruction telling it never to. Two patterns are scrubbed here as
 * belt-and-suspenders defense:
 *
 *   (a) "[YYYY-MM-DD HH:MM UTC]" — the legacy Decision #386 leak. Past eras
 *       prefixed conversation-history messages with this format; the model
 *       picked it up as a reply opener.
 *
 *   (b) "[ago: X]" — the per-message timing tag added to user messages in
 *       getHistory. Asymmetric (only on user messages) precisely so the
 *       model never sees a template to mirror, but if it ever does produce
 *       this format we want it stripped before reaching the practitioner.
 *
 * Matches at the start of the response (with optional ** markdown decoration
 * and tolerant of doubled/tripled prefixes):
 *
 *   "[2026-04-18 14:30 UTC] Good..."         → "Good..."
 *   "[2026-04-18 14:30] Good..."             → "Good..."
 *   "**[2026-04-18 14:30 UTC]** Good..."     → "Good..."
 *   "[ago: 8 hours] Good..."                 → "Good..."
 *   "[ago: 12 minutes] [ago: just now] Good..." → "Good..."
 */
function stripLeadingTimestamp(string $text): string {
    $patterns = [
        // Legacy bracketed-timestamp prefix.
        '/^\s*(?:\*\*)?\[\s*\d{4}-\d{2}-\d{2}(?:[T\s]+\d{1,2}:\d{2}(?::\d{2})?)?\s*(?:UTC|Z)?\s*\](?:\*\*)?\s*/u',
        // [ago: X] tag — case-insensitive, tolerates whitespace inside.
        '/^\s*(?:\*\*)?\[\s*ago\s*:\s*[^\]]{1,40}\](?:\*\*)?\s*/iu',
    ];
    // Repeat up to 4 times to catch doubled/tripled prefixes or mixed
    // legacy-timestamp + ago-tag pairs ("[2026-04-18] [ago: 2 hours] ...").
    for ($i = 0; $i < 4; $i++) {
        $changed = false;
        foreach ($patterns as $p) {
            $new = preg_replace($p, '', $text);
            if ($new !== null && $new !== $text) {
                $text = $new;
                $changed = true;
            }
        }
        if (!$changed) break;
    }
    return ltrim($text);
}


/**
 * Fingerprint the static half of a system prompt.
 *
 * Hashes only the cacheable prefix — persona heredoc + doctrinal backbone,
 * everything before CACHE_BREAKPOINT_MARKER. The dynamic suffix (this
 * practitioner's profile, RAG retrieval, timestamps) changes every call and
 * would drown the signal. Two messages sharing a prompt_hash came from the same
 * doctrinal and persona layer; two that differ did not, whatever version number
 * they declare.
 */
function lodestonePromptHash(string $systemPrompt): string {
    $prefix = $systemPrompt;
    if (defined('CACHE_BREAKPOINT_MARKER')) {
        $pos = strpos($systemPrompt, CACHE_BREAKPOINT_MARKER);
        if ($pos !== false) {
            $prefix = substr($systemPrompt, 0, $pos);
        }
    }
    return substr(sha1($prefix), 0, 8);
}

/**
 * Release lock file.
 */
function releaseLock($lockFp, string $lockFile): void {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    // Unlink only if the path still points at OUR lock. deploy.sh kills the
    // old worker and launches a replacement immediately; a graceful shutdown
    // that unlinks by path deletes the lock the NEW worker just created and
    // holds, which (a) makes deploy.sh's `[ -f lock ]` check report "worker
    // may not have started" and (b) lets the watchdog start a second
    // concurrent worker five minutes later. See Decision #1185.
    if (is_file($lockFile)
        && trim((string) @file_get_contents($lockFile)) === (string) getmypid()) {
        @unlink($lockFile);
    }
}


/**
 * Show queue status for diagnostics.
 */
function showStatus(CompanionMemory $memory): void {
    $db = new PDO('sqlite:' . __DIR__ . '/companion-data.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $counts = $db->query('
        SELECT status, COUNT(*) as cnt FROM response_queue GROUP BY status
    ')->fetchAll(PDO::FETCH_KEY_PAIR);

    echo "=== Response Queue Status ===\n";
    echo "  Pending:    " . ($counts['pending'] ?? 0) . "\n";
    echo "  Processing: " . ($counts['processing'] ?? 0) . "\n";
    echo "  Completed:  " . ($counts['completed'] ?? 0) . "\n";
    echo "  Failed:     " . ($counts['failed'] ?? 0) . "\n";

    // Show recent jobs
    $recent = $db->query('
        SELECT id, chat_id, status, attempts, model,
               datetime(created_at, "unixepoch") as created,
               datetime(completed_at, "unixepoch") as completed,
               CASE WHEN completed_at IS NOT NULL THEN completed_at - created_at ELSE NULL END as duration_s,
               CASE WHEN error IS NOT NULL THEN substr(error, 1, 80) ELSE NULL END as error_short
        FROM response_queue ORDER BY created_at DESC LIMIT 10
    ')->fetchAll(PDO::FETCH_ASSOC);

    if ($recent) {
        echo "\n  Recent jobs:\n";
        foreach ($recent as $row) {
            $dur = $row['duration_s'] ? "{$row['duration_s']}s" : '-';
            $err = $row['error_short'] ? " | {$row['error_short']}" : '';
            $mdl = $row['model'] ? " model={$row['model']}" : '';
            echo "    #{$row['id']} chat={$row['chat_id']} {$row['status']}{$mdl} attempts={$row['attempts']} dur={$dur}{$err}\n";
        }
    }

    // Show worker status
    $lockFile = __DIR__ . '/queue-processor.lock';
    if (file_exists($lockFile)) {
        $pid = trim(file_get_contents($lockFile));
        echo "\n  Worker PID: {$pid}";
        // Check if process is actually running (POSIX systems)
        if ($pid && function_exists('posix_kill')) {
            $running = posix_kill((int)$pid, 0);
            echo $running ? " (RUNNING)" : " (STALE — process not found)";
        }
        echo "\n";
    } else {
        echo "\n  Worker: NOT RUNNING (no lock file)\n";
    }
}


/**
 * Show per-user cost breakdown.
 * Usage: php process-queue.php --costs [DAYS]
 *   --costs       All-time costs
 *   --costs 7     Last 7 days
 *   --costs 30    Last 30 days
 */
function showCosts(CompanionMemory $memory, ?int $days = null): void {
    $rows = $memory->getUserCosts($days);
    $period = $days ? "last {$days} days" : "all time";

    echo "=== Companion Cost Report ({$period}) ===\n\n";

    if (!$rows) {
        echo "  No cost data recorded yet. Costs will appear after the next deploy.\n";
        return;
    }

    $totalCost = 0;
    $totalInput = 0;
    $totalOutput = 0;
    $totalJobs = 0;

    printf("  %-20s %8s %10s %10s %10s\n", "User", "Jobs", "Input Tk", "Output Tk", "Cost USD");
    printf("  %-20s %8s %10s %10s %10s\n", str_repeat('-', 20), str_repeat('-', 8), str_repeat('-', 10), str_repeat('-', 10), str_repeat('-', 10));

    foreach ($rows as $row) {
        $name = $row['first_name'] ?: ($row['username'] ?: "Chat#{$row['chat_id']}");
        $name = mb_substr($name, 0, 20);
        $cost = (float) ($row['total_cost'] ?? 0);

        printf("  %-20s %8d %10s %10s %10s\n",
            $name,
            $row['job_count'],
            number_format($row['total_input'] ?? 0),
            number_format($row['total_output'] ?? 0),
            '$' . number_format($cost, 4)
        );

        $totalCost += $cost;
        $totalInput += ($row['total_input'] ?? 0);
        $totalOutput += ($row['total_output'] ?? 0);
        $totalJobs += $row['job_count'];
    }

    printf("\n  %-20s %8d %10s %10s %10s\n",
        "TOTAL",
        $totalJobs,
        number_format($totalInput),
        number_format($totalOutput),
        '$' . number_format($totalCost, 4)
    );

    echo "\n  Users tracked: " . count($rows) . "\n";

    if ($totalJobs > 0) {
        $avgCost = $totalCost / $totalJobs;
        echo "  Avg cost/job: \$" . number_format($avgCost, 4) . "\n";
    }
}


/**
 * Call Claude API with specified model.
 * Returns array with 'text', 'input_tokens', 'output_tokens' — or null on failure.
 *
 * @param bool $cacheSystem   Wrap entire system prompt as one cached block.
 *                            For learning calls where the whole prompt is static.
 * @param bool $splitCache    Split system prompt on CACHE_BREAKPOINT_MARKER and
 *                            cache only the prefix. For the user-facing response
 *                            path where persona+backbone is static but the rest
 *                            (RAG, profile, temporal) varies. Falls through to
 *                            uncached pass-through if marker is absent (legacy
 *                            queued jobs from before this change).
 */
function callClaude(string $systemPrompt, array $messages, string $model = null, ?array $imageData = null, bool $cacheSystem = false, bool $splitCache = false, ?int $maxTokensOverride = null): ?array {
    $model = $model ?? COMPANION_MODEL;
    $apiUrl = 'https://api.anthropic.com/v1/messages';

    // Haiku gets lower max_tokens — simple messages don't need 8192.
    // Image messages always get Opus-level tokens (vision responses need room).
    // Learning calls (wheel/profile/consolidation) pass an override of 4096 to
    // accommodate Opus 4.7's slightly more verbose structured-JSON output.
    // Opus user-facing ceiling raised 2048 → 8192 to handle long-form analytical
    // responses (multi-section reports, full-history reflections) without hitting
    // max_tokens mid-sentence. Billed only for tokens actually generated, so the
    // short-response cost floor is unchanged; the ceiling rises for the comp/paid
    // analytical work that legitimately needs the room.
    $maxTokens = $maxTokensOverride ?? (($model === MODEL_HAIKU && !$imageData) ? 512 : 8192);

    // If image data is present, modify the last user message to include
    // both the image and the text as multimodal content blocks (Decision #399).
    // Claude's API expects: content: [{type: "image", ...}, {type: "text", ...}]
    if ($imageData && !empty($messages)) {
        $lastIdx = count($messages) - 1;
        if ($messages[$lastIdx]['role'] === 'user') {
            $textContent = $messages[$lastIdx]['content'];
            $messages[$lastIdx]['content'] = [
                [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $imageData['mime_type'],
                        'data' => $imageData['base64'],
                    ],
                ],
                [
                    'type' => 'text',
                    'text' => $textContent,
                ],
            ];
        }
    }

    // Prompt caching strategy.
    //
    // (a) $splitCache — split on CACHE_BREAKPOINT_MARKER. The prefix (persona +
    //     doctrinal backbone, ~27K tokens, byte-identical across users) is wrapped
    //     as cache_control:ephemeral with ttl:'1h'; the suffix (per-call dynamic
    //     context) is sent uncached. This is the dominant path for the user-facing
    //     response call. If the marker is absent (legacy queued jobs from a prior
    //     deploy), fall through to uncached pass-through — no failure, just no
    //     cache hit. Prefix-stability discipline: never edit the cached content
    //     outside a Decision-anchored doctrinal pass — every byte change
    //     invalidates every active cache across the entire user base.
    //
    // (b) $cacheSystem — wrap the WHOLE prompt as one cached block. Used by
    //     profile learning calls where the entire system prompt is static.
    //     Mutually exclusive with $splitCache; if both are true, $splitCache wins.
    //
    // Required minimum ~1024 tokens (Haiku/Sonnet) / ~2048 (Opus). Below the
    // minimum the directive is silently ignored — no harm.
    if ($splitCache) {
        $markerPos = strpos($systemPrompt, CACHE_BREAKPOINT_MARKER);
        if ($markerPos !== false) {
            $staticPrefix  = substr($systemPrompt, 0, $markerPos);
            $dynamicSuffix = substr($systemPrompt, $markerPos + strlen(CACHE_BREAKPOINT_MARKER));
            $systemField = [
                [
                    'type'          => 'text',
                    'text'          => $staticPrefix,
                    // 1h TTL: extends the cache window from the 5min default to
                    // 1 hour, covering practitioner-session gaps that previously
                    // forced cold rebuilds. Create=2x base input, read=0.1x.
                    // Break-even at <1 warm hit/session.
                    'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
                ],
                [
                    'type' => 'text',
                    'text' => $dynamicSuffix,
                ],
            ];
        } else {
            // Legacy job queued before the marker was introduced — pass as-is.
            $systemField = $systemPrompt;
        }
    } elseif ($cacheSystem) {
        $systemField = [[
            'type'          => 'text',
            'text'          => $systemPrompt,
            'cache_control' => ['type' => 'ephemeral'],
        ]];
    } else {
        $systemField = $systemPrompt;
    }

    $requestBody = json_encode([
        'model' => $model,
        'max_tokens' => $maxTokens,
        'system' => $systemField,
        'messages' => $messages,
    ]);

    $ch = llm_curl_init();
    llm_curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,     // Generous — CLI has no web server pressure
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $result = llm_curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        error_log("[worker] Claude API curl error: " . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("[worker] Claude API HTTP {$httpCode} (model={$model}): {$result}");
        return null;
    }

    $data = json_decode($result, true);
    // Opus 5.5: replies may open with thinking blocks — keep only text blocks so content[0] is the first text block.
    if (is_array($data) && isset($data['content']) && is_array($data['content'])) { $data['content'] = array_values(array_filter($data['content'], function ($b) { return ($b['type'] ?? '') === 'text'; })); }
    if (!$data || !isset($data['content'][0]['text'])) {
        error_log("[worker] Claude API unexpected response: {$result}");
        return null;
    }

    return [
        'text'                        => $data['content'][0]['text'],
        'input_tokens'                => $data['usage']['input_tokens']                ?? null,
        'output_tokens'               => $data['usage']['output_tokens']               ?? null,
        'cache_creation_input_tokens' => $data['usage']['cache_creation_input_tokens'] ?? null,
        'cache_read_input_tokens'     => $data['usage']['cache_read_input_tokens']     ?? null,
        // stop_reason: 'end_turn' (normal), 'max_tokens' (cap-hit, response was
        // cut off mid-generation), 'stop_sequence', or 'tool_use'. Caller uses
        // this to append a truncation marker in finalizeStreamedDelivery when
        // 'max_tokens' fires, so the practitioner knows to ask for continuation
        // instead of silently receiving a half-thought.
        'stop_reason'                 => $data['stop_reason']                          ?? null,
    ];
}


/**
 * Streaming variant of callClaude — used for the user-facing response so the
 * reply surfaces in the Telegram client as it's generated (via repeated
 * editMessageText calls) instead of arriving as a single block after 60–120s.
 *
 * Telegram has no native token streaming, so we emulate it: send a placeholder
 * message, edit it every ~1.1s with the accumulated text, do a final edit
 * with Markdown parse_mode once the stream completes. Total wall-clock is
 * unchanged; perceived latency drops dramatically.
 *
 * The $onChunk callback receives the cumulative text and an isFinal flag.
 * Implementations should throttle their own external work (this function
 * does not gate the callback — it fires on every text_delta event).
 *
 * Returns the same shape as callClaude or null on failure.
 */
function callClaudeStream(
    string $systemPrompt,
    array $messages,
    ?string $model,
    ?array $imageData,
    bool $splitCache,
    callable $onChunk
): ?array {
    $model = $model ?? COMPANION_MODEL;
    $apiUrl = 'https://api.anthropic.com/v1/messages';

    // Opus user-facing ceiling raised 2048 → 8192 — see note in callClaude().
    $maxTokens = ($model === MODEL_HAIKU && !$imageData) ? 512 : 8192;

    if ($imageData && !empty($messages)) {
        $lastIdx = count($messages) - 1;
        if ($messages[$lastIdx]['role'] === 'user') {
            $textContent = $messages[$lastIdx]['content'];
            $messages[$lastIdx]['content'] = [
                [
                    'type' => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $imageData['mime_type'],
                        'data'       => $imageData['base64'],
                    ],
                ],
                ['type' => 'text', 'text' => $textContent],
            ];
        }
    }

    // Build system field with split-cache logic — same shape as callClaude.
    if ($splitCache) {
        $markerPos = strpos($systemPrompt, CACHE_BREAKPOINT_MARKER);
        if ($markerPos !== false) {
            $staticPrefix  = substr($systemPrompt, 0, $markerPos);
            $dynamicSuffix = substr($systemPrompt, $markerPos + strlen(CACHE_BREAKPOINT_MARKER));
            $systemField = [
                [
                    'type'          => 'text',
                    'text'          => $staticPrefix,
                    // 1h TTL — see callClaude split-cache site for rationale.
                    'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
                ],
                ['type' => 'text', 'text' => $dynamicSuffix],
            ];
        } else {
            $systemField = $systemPrompt;
        }
    } else {
        $systemField = $systemPrompt;
    }

    $requestBody = json_encode([
        'model'       => $model,
        'max_tokens'  => $maxTokens,
        'system'      => $systemField,
        'messages'    => $messages,
        'stream'      => true,
    ]);

    // SSE accumulators — captured by reference inside the curl write callback.
    $accumulated   = '';
    $sseBuffer     = '';
    $inputTokens   = null;
    $outputTokens  = null;
    $cacheCreate   = null;
    $cacheRead     = null;
    $errorSeen     = null;
    // stop_reason arrives on the message_delta event ('end_turn', 'max_tokens',
    // 'stop_sequence', 'tool_use'). Worker uses 'max_tokens' to append a
    // truncation marker so the practitioner knows the response was cut off
    // mid-thought rather than ended naturally.
    $stopReason    = null;
    // Diagnostics — populated on every chunk so we can post-mortem when
    // accumulated stays empty: total bytes received, last raw sample, count
    // of complete SSE events parsed.
    $totalBytes    = 0;
    $rawHeadSample = '';
    $eventCount    = 0;

    $ch = llm_curl_init();
    llm_curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $requestBody,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'Accept: text/event-stream',
        ],
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (
            &$accumulated, &$sseBuffer,
            &$inputTokens, &$outputTokens, &$cacheCreate, &$cacheRead,
            &$errorSeen, &$stopReason, &$totalBytes, &$rawHeadSample, &$eventCount,
            $onChunk
        ) {
            $totalBytes += strlen($chunk);
            if ($rawHeadSample === '' && $chunk !== '') {
                // Capture the first ~512 bytes verbatim so a parse-fail
                // post-mortem can see exactly what arrived from Anthropic.
                $rawHeadSample = substr($chunk, 0, 512);
            }
            $sseBuffer .= $chunk;

            // SSE events terminate on a blank line. Per RFC, that's "\n\n"
            // for plain SSE, but some HTTP intermediaries normalize to
            // "\r\n\r\n". Probe both — whichever appears first wins for
            // this iteration of the buffer.
            while (true) {
                $lfPos = strpos($sseBuffer, "\n\n");
                $crLfPos = strpos($sseBuffer, "\r\n\r\n");
                if ($lfPos === false && $crLfPos === false) break;
                if ($lfPos !== false && ($crLfPos === false || $lfPos < $crLfPos)) {
                    $eolPos = $lfPos;
                    $sepLen = 2;
                } else {
                    $eolPos = $crLfPos;
                    $sepLen = 4;
                }
                $rawEvent  = substr($sseBuffer, 0, $eolPos);
                $sseBuffer = substr($sseBuffer, $eolPos + $sepLen);
                $eventCount++;

                // Each event is one or more "field: value" lines. We only
                // care about data; concatenate multi-line data folds (rare).
                $dataParts = [];
                foreach (explode("\n", $rawEvent) as $line) {
                    if (strncmp($line, 'data: ', 6) === 0) {
                        $dataParts[] = substr($line, 6);
                    } elseif (strncmp($line, 'data:', 5) === 0) {
                        $dataParts[] = substr($line, 5);
                    }
                }
                if (empty($dataParts)) continue;
                $jsonStr = implode('', $dataParts);
                $data = json_decode($jsonStr, true);
                if (!is_array($data)) continue;

                $type = $data['type'] ?? '';
                if ($type === 'content_block_delta') {
                    $delta = $data['delta'] ?? [];
                    if (($delta['type'] ?? '') === 'text_delta') {
                        $accumulated .= $delta['text'] ?? '';
                        $onChunk($accumulated, false);
                    }
                } elseif ($type === 'message_start') {
                    $usage = $data['message']['usage'] ?? [];
                    $inputTokens = $usage['input_tokens']                ?? null;
                    $cacheCreate = $usage['cache_creation_input_tokens'] ?? null;
                    $cacheRead   = $usage['cache_read_input_tokens']     ?? null;
                } elseif ($type === 'message_delta') {
                    $usage = $data['usage'] ?? [];
                    if (isset($usage['output_tokens'])) {
                        $outputTokens = $usage['output_tokens'];
                    }
                    // Anthropic emits stop_reason on the message_delta event near
                    // the end of the stream. Capture it so the caller can detect
                    // max_tokens cap-hit and surface a continuation marker.
                    $delta = $data['delta'] ?? [];
                    if (isset($delta['stop_reason'])) {
                        $stopReason = $delta['stop_reason'];
                    }
                } elseif ($type === 'error') {
                    $errorSeen = $data['error']['message'] ?? 'unknown stream error';
                    error_log("[worker] Claude stream error event: {$jsonStr}");
                }
            }
            return strlen($chunk);
        },
    ]);

    llm_curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Diagnostic post-mortem — fires whenever the stream didn't produce
    // usable text. Captures everything we'd need to figure out why from
    // the worker error log: total bytes received from Anthropic, count of
    // SSE events parsed, first 512 bytes verbatim (with control chars
    // visible), and any model-side error event we saw.
    $shouldDiagnose = ($curlErrno !== 0)
        || ($httpCode !== 200)
        || ($errorSeen !== null)
        || ($accumulated === '');
    if ($shouldDiagnose) {
        $diagSample = strtr($rawHeadSample, ["\r" => '\\r', "\n" => '\\n']);
        $diagSample = mb_substr($diagSample, 0, 256);
        error_log(sprintf(
            "[worker] STREAM_DIAG http=%d errno=%d events=%d bytes=%d acc_len=%d errSeen=%s sample=%s curlError=%s",
            $httpCode,
            $curlErrno,
            $eventCount,
            $totalBytes,
            mb_strlen($accumulated),
            $errorSeen === null ? '-' : substr($errorSeen, 0, 80),
            $diagSample === '' ? '-' : $diagSample,
            $curlError === '' ? '-' : $curlError
        ));
    }

    if ($curlErrno) {
        return null;
    }
    if ($httpCode !== 200) {
        return null;
    }
    if ($errorSeen !== null) {
        return null;
    }
    if ($accumulated === '') {
        return null;
    }

    // Final flush — let the caller render the complete response.
    $onChunk($accumulated, true);

    return [
        'text'                        => $accumulated,
        'input_tokens'                => $inputTokens,
        'output_tokens'               => $outputTokens,
        'cache_creation_input_tokens' => $cacheCreate,
        'cache_read_input_tokens'     => $cacheRead,
        'stop_reason'                 => $stopReason,
    ];
}


/**
 * Parse a JSON object from a Claude response, tolerating model preambles.
 * Returns the decoded array or null. Used by profile-learning callers where
 * the model occasionally preambles ("Looking at the conversation...") before
 * emitting the JSON — json_decode fails, and the profile silently doesn't
 * update. Salvage regex extracts the largest {...} block.
 */
function parseJsonFromClaude(?string $text): ?array {
    if ($text === null) return null;
    $t = trim($text);
    $t = preg_replace('/^```(?:json)?\s*/i', '', $t);
    $t = preg_replace('/\s*```$/', '', $t);
    $decoded = json_decode($t, true);
    if (is_array($decoded)) return $decoded;
    // Salvage: extract the largest balanced {...} block.
    if (preg_match('/\{.*\}/s', $t, $m)) {
        $decoded = json_decode($m[0], true);
        if (is_array($decoded)) return $decoded;
    }
    return null;
}


/**
 * Send a message via Telegram Bot API.
 * Handles the 4096 character limit by splitting long messages.
 */
function sendTelegramMessage(int $chatId, string $text): void {
    $maxLen = 4096;

    if (mb_strlen($text) <= $maxLen) {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ]);
        return;
    }

    // Split at paragraph boundaries
    $chunks = splitMessage($text, $maxLen);
    foreach ($chunks as $chunk) {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $chunk,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ]);
        usleep(300000); // 300ms between messages to maintain order
    }
}


/**
 * Split a long message at paragraph boundaries.
 */
function splitMessage(string $text, int $maxLen): array {
    $chunks = [];
    while (mb_strlen($text) > $maxLen) {
        $cutPoint = mb_strrpos(mb_substr($text, 0, $maxLen), "\n\n");
        if ($cutPoint === false || $cutPoint < $maxLen / 2) {
            $cutPoint = mb_strrpos(mb_substr($text, 0, $maxLen), "\n");
        }
        if ($cutPoint === false || $cutPoint < $maxLen / 2) {
            $cutPoint = mb_strrpos(mb_substr($text, 0, $maxLen), " ");
        }
        if ($cutPoint === false) {
            $cutPoint = $maxLen;
        }

        $chunks[] = mb_substr($text, 0, $cutPoint);
        $text = trim(mb_substr($text, $cutPoint));
    }
    if ($text) {
        $chunks[] = $text;
    }
    return $chunks;
}


/**
 * Send typing indicator.
 */
function sendTelegramAction(int $chatId, string $action = 'typing'): void {
    sendTelegramRequest('sendChatAction', [
        'chat_id' => $chatId,
        'action' => $action,
    ]);
}


/**
 * Make a request to the Telegram Bot API.
 */
function sendTelegramRequest(string $method, array $params): ?array {
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("[worker] Telegram API error: " . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    $data = json_decode($result, true);
    if (!$data || !($data['ok'] ?? false)) {
        $description = is_array($data) ? ($data['description'] ?? '') : '';

        // "message is not modified" — Telegram's response when an edit
        // produces identical text to what's already displayed. Common
        // during streaming when the throttle gate fires before any new
        // delta arrived. Treat as success — no retry.
        if (stripos($description, 'message is not modified') !== false) {
            return $data;
        }

        error_log("[worker] Telegram API error: {$result}");

        // Markdown parse-mode fallback. Telegram is strict about Markdown
        // syntax (unbalanced asterisks, stray underscores, etc.); retry
        // without parse_mode if parsing failed. Applies to both fresh
        // sends and edits.
        $isEdit = ($method === 'editMessageText' || $method === 'editMessageCaption');
        if (($method === 'sendMessage' || $isEdit) && isset($params['parse_mode'])) {
            unset($params['parse_mode']);
            return sendTelegramRequest($method, $params);
        }
    }

    return $data;
}


/**
 * Update user's free-text profile summary via Claude.
 * Always uses Opus — profile accuracy is critical.
 */
function updateUserProfile(CompanionMemory $memory, int $chatId): void {
    // Window widened 30 → 60 (Decision #617). Earlier 30-message window
    // occasionally missed arcs that spanned prior learning-cycle boundaries;
    // 60 covers ~3 learning cycles of context at ~$0.01/call with caching.
    $recentText = $memory->getRecentMessagesText($chatId, 60);
    $currentProfile = $memory->getProfileSummary($chatId);

    $systemPrompt = <<<'PROFILE'
You are updating your understanding of a person based on recent conversations.
Review the conversation and produce a concise profile summary (max 500 words) covering:

1. Where they seem to stand on the Wheel of Harmony (which pillars are strong, weak, in crisis)
2. Their apparent life phase and primary concerns
3. Practical details they've shared (health conditions, family situation, work, goals)
4. Their orientation (philosophical vs practical, which traditions resonate)
5. Patterns you notice (recurring themes, growth areas, resistance points)

Write in second person ("You tend to...", "Your health focus is...") as if writing notes-to-self about this person.
Be specific and concrete. This profile will be injected into future conversations to maintain continuity.

CRITICAL: Only include information that was EXPLICITLY stated by the person in the conversation text provided below. Never infer, assume, or fabricate biographical details, business information, dates, professions, family details, or any specifics not directly present in the messages. If the conversation is thin, write a thin profile — a short accurate profile is infinitely better than a detailed hallucinated one. When updating an existing profile, if the previous profile contains claims you cannot verify from the conversation history, note them as "unverified from prior profile" rather than treating them as established fact.
PROFILE;

    $messages = [];
    if ($currentProfile) {
        $messages[] = [
            'role' => 'user',
            'content' => "Previous profile:\n{$currentProfile}\n\nRecent conversation:\n{$recentText}\n\nUpdate the profile based on the new conversation. Preserve what's still accurate, update what's changed, add new observations."
        ];
    } else {
        $messages[] = [
            'role' => 'user',
            'content' => "Conversation with a new person:\n{$recentText}\n\nCreate an initial profile."
        ];
    }

    // Profile learning always uses Opus for accuracy. System prompt is static
    // across users — opt into caching (Decision #617). Below-minimum blocks
    // (Opus requires ~2048 tokens) silently no-op; no risk.
    // max_tokens=4096 to clear truncation under Opus 4.7's verbosity.
    $result = callClaude($systemPrompt, $messages, COMPANION_MODEL, null, true, false, 4096);
    if ($result) {
        $memory->updateProfileSummary($chatId, $result['text']);
    }
}


/**
 * Update structured Wheel profile via Claude.
 * Always uses Opus — structural accuracy matters.
 */
function updateWheelProfile(CompanionMemory $memory, int $chatId): void {
    // Window widened 30 → 60, plus 3 prior conversation summaries for
    // medium-term arc (Decision #617). 60 recent messages cover ~3 learning
    // cycles; summaries reach back further without token blowup. The
    // retroactive-wheel-recalibration run surfaced how narrow the 30-window
    // was — regressions distributed across a wider arc got missed.
    $recentText  = $memory->getRecentMessagesText($chatId, 60);
    $summaries   = $memory->getConversationSummaries($chatId, 3);
    $wheelProfile = $memory->getWheelProfile($chatId);

    // Build current wheel state for Claude
    $currentState = [];
    foreach ($wheelProfile as $pillar => $data) {
        $currentState[] = "{$pillar}: {$data['engagement_level']}"
            . ($data['primary_concerns'] ? " (concerns: {$data['primary_concerns']})" : '')
            . ($data['strengths'] ? " (strengths: {$data['strengths']})" : '')
            . ($data['growth_edges'] ? " (edges: {$data['growth_edges']})" : '');
    }
    $currentWheelStr = implode("\n", $currentState);

    $systemPrompt = <<<'WHEEL'
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

FIELD LENGTH BOUNDS — keep prose fields tight:
- "primary_concerns": maximum 200 characters
- "strengths": maximum 200 characters
- "growth_edges": maximum 200 characters

Compress without losing precision. The fields are observations, not essays. A long entry that does not fit the bound should be cut, not expanded across multiple sentences.

Omit level_change_confidence if engagement_level is unchanged from the recorded level.

Set resistance_noted to true ONLY if you observe the person actively avoiding, minimizing, or deflecting from a pillar.
WHEEL;

    // Build summaries block for medium-term arc
    $summaryLines = [];
    foreach ($summaries as $s) {
        $summaryLines[] = "[{$s['period_start']}→{$s['period_end']}] "
            . ($s['pillars_discussed'] ? "pillars: {$s['pillars_discussed']}. " : '')
            . $s['summary'];
    }
    $summaryBlock = empty($summaryLines) ? "(none)" : implode("\n\n", $summaryLines);

    $messages = [[
        'role' => 'user',
        'content' =>
            "Current Wheel profile:\n{$currentWheelStr}\n\n"
            . "Prior conversation summaries (oldest→newest):\n{$summaryBlock}\n\n"
            . "Recent conversation:\n{$recentText}\n\n"
            . "Extract structured Wheel updates. Only include pillars where new information emerged or where contradiction with the recorded level is present. Omit pillars where the evidence simply confirms the current level.\n\n"
            . "CRITICAL: Respond with ONLY the JSON object. No preamble, no reasoning aloud. The first character of your response must be `{` and the last must be `}`. If no updates are warranted, respond with `{}`."
    ]];

    // Opus + caching on the static system prompt (Decision #617).
    // max_tokens=4096 to clear truncation under Opus 4.7's verbosity.
    $apiResult = callClaude($systemPrompt, $messages, COMPANION_MODEL, null, true, false, 4096);
    if (!$apiResult) return;

    // Parse with salvage regex — tolerates preambles (Decision #617). Prior
    // behaviour silently dropped updates whenever Opus emitted any prose before
    // the JSON; that failure mode is now recovered, not lost.
    $updates = parseJsonFromClaude($apiResult['text']);
    if ($updates === null) {
        error_log("[worker] Wheel profile update: failed to parse JSON after salvage: " . mb_substr((string)$apiResult['text'], 0, 200));
        return;
    }

    $memory->updateWheelProfileBatch($chatId, $updates);
}


/**
 * Phase B §6.4 + §6.8 — extract conversation-context signals via Opus.
 *
 * Three outputs:
 *   (1) dominant_emotion — one of a 16-emotion taxonomy. Purpose: condition tone
 *       and pacing of Lodestone's next turn. Cheaper than re-deriving emotional
 *       register from scratch every message; surfaces drift to the prompt.
 *   (2) situation_capsule — ≤60-char free-text capsule naming the current life
 *       context ("caring for elderly mother, 4 months post-diagnosis"). Stable
 *       across many turns; changes only when the practitioner's frame shifts.
 *   (3) conversation_state — Emora-STDM style externalised state: current_thread,
 *       pending_threads, open_commitments, last_arc_reading_at. Gives the model
 *       structural memory of what's been set down mid-conversation rather than
 *       relying on it to reconstruct from the last-20 window.
 *
 * Always uses Opus — taxonomy fidelity and capsule precision both matter.
 * The call is best-effort: any decode/schema failure is logged and dropped,
 * prior stored values preserved.
 */
function updateConversationContext(CompanionMemory $memory, int $chatId): void {
    $recentText = $memory->getRecentMessagesText($chatId, 30);
    $current    = method_exists($memory, 'getConversationContext')
        ? $memory->getConversationContext($chatId)
        : null;

    $currentEmotion  = $current['dominant_emotion']  ?? '';
    $currentCapsule  = $current['situation_capsule'] ?? '';
    $currentState    = $current['conversation_state'] ?? [
        'current_thread'      => '',
        'pending_threads'     => [],
        'open_commitments'    => [],
        'last_arc_reading_at' => 0,
    ];
    $priorStateJson = json_encode($currentState, JSON_UNESCAPED_UNICODE);

    $systemPrompt = <<<'CONTEXT'
You are extracting conversation-context signals from a practitioner's recent exchange with Lodestone.
Produce three fields as a strict JSON object. Do not explain. Do not hedge in prose — either a signal is present or the field carries its empty default.

Schema:
{
  "dominant_emotion": one of ["lonely","afraid","grateful","ashamed","hopeful","frustrated","grieving","overwhelmed","peaceful","curious","resentful","tender","defiant","numb","reverent","longing",""],
  "situation_capsule": string ≤ 60 chars — the current life context the practitioner is inside. Stable across turns; examples: "caring for mother, 4 months post-diagnosis", "preparing to move country in 6 weeks", "first trimester, first pregnancy". Empty string if no stable context is visible yet.
  "conversation_state": {
    "current_thread": ≤120 chars — what this exchange is actively about (the present topic, not a recap of the relationship).
    "pending_threads": array of ≤3 short strings — topics the practitioner opened but hasn't closed, that Lodestone should be ready to return to.
    "open_commitments": array of ≤3 short strings — practices, protocols, or experiments the practitioner committed to trying. Strictly what *they* committed to — not what Lodestone recommended.
    "last_arc_reading_at": the integer unix timestamp passed in "prior_last_arc_reading_at" — copy it forward unchanged. Set to the current unix timestamp only if a visibly arc-level reflection just occurred in this exchange.
  }
}

Rules:
- If the conversation gives no clear signal for a field, use the empty default ("" or []).
- dominant_emotion is one token from the taxonomy — never a new word, never a phrase. "" if no emotion is visible.
- situation_capsule must be a *context* (what's true about their life right now), not an emotion or a topic.
- Never fabricate commitments. If nothing was committed to, open_commitments is [].
- Only the last_arc_reading_at rule permits you to emit a non-copied integer — and only when an arc-level reflection is visible in *this* exchange.

Respond with ONLY valid JSON, no other text, no code fences.
CONTEXT;

    $userContent = "Prior signals (for stability — carry forward unchanged unless this exchange clearly updates them):\n"
        . "- dominant_emotion: " . ($currentEmotion !== '' ? $currentEmotion : '(none)') . "\n"
        . "- situation_capsule: " . ($currentCapsule !== '' ? $currentCapsule : '(none)') . "\n"
        . "- prior_last_arc_reading_at: " . (int)($currentState['last_arc_reading_at'] ?? 0) . "\n"
        . "- prior_conversation_state: " . ($priorStateJson !== false ? $priorStateJson : '{}') . "\n\n"
        . "Recent conversation:\n" . $recentText . "\n\n"
        . "Extract the three-field JSON per schema.";

    $messages = [[ 'role' => 'user', 'content' => $userContent ]];

    // Caching + salvage regex (Decision #617).
    // max_tokens=4096 to clear truncation under Opus 4.7's verbosity.
    $apiResult = callClaude($systemPrompt, $messages, COMPANION_MODEL, null, true, false, 4096);
    if (!$apiResult) return;

    $data = parseJsonFromClaude($apiResult['text']);
    if ($data === null) {
        error_log("[worker] Conversation context update: failed to parse JSON after salvage: " . mb_substr((string)$apiResult['text'], 0, 200));
        return;
    }

    $validEmotions = [
        'lonely','afraid','grateful','ashamed','hopeful','frustrated','grieving',
        'overwhelmed','peaceful','curious','resentful','tender','defiant','numb',
        'reverent','longing',''
    ];
    $emotion = isset($data['dominant_emotion']) ? (string)$data['dominant_emotion'] : '';
    if (!in_array($emotion, $validEmotions, true)) $emotion = '';

    $capsule = isset($data['situation_capsule']) ? (string)$data['situation_capsule'] : '';
    $capsule = trim($capsule);

    $stateIn = is_array($data['conversation_state'] ?? null) ? $data['conversation_state'] : [];
    $state = [
        'current_thread'      => (string)($stateIn['current_thread'] ?? ''),
        'pending_threads'     => is_array($stateIn['pending_threads']  ?? null) ? array_values(array_slice(array_map('strval', $stateIn['pending_threads']), 0, 5))  : [],
        'open_commitments'    => is_array($stateIn['open_commitments'] ?? null) ? array_values(array_slice(array_map('strval', $stateIn['open_commitments']), 0, 5)) : [],
        'last_arc_reading_at' => (int)($stateIn['last_arc_reading_at'] ?? 0),
    ];

    if (method_exists($memory, 'updateConversationContext')) {
        $memory->updateConversationContext($chatId, $emotion, $capsule, $state);
    }
}


/**
 * Consolidate older conversation messages into a summary.
 * Always uses Opus — summary accuracy matters.
 */
function consolidateConversation(CompanionMemory $memory, int $chatId): void {
    $messages = $memory->getUnconsolidatedMessages($chatId, 50);
    if (count($messages) < 50) return;

    $firstMsg = $messages[0];
    $lastMsg = $messages[count($messages) - 1];

    $conversationText = $memory->getMessageRangeText(
        $chatId, (int)$firstMsg['id'], (int)$lastMsg['id']
    );

    $systemPrompt = <<<'CONSOLIDATE'
You are consolidating a conversation batch into a memory summary.
This summary will be injected into future conversations as medium-term memory.

Produce a JSON response with:
{
  "summary": "2-4 sentence summary of what was discussed and what emerged. Write as notes-to-self about this person. Be specific — names, conditions, decisions, not vague generalities. IMPORTANT: Only record what was actually said. Never infer or assume topics that were not explicitly discussed. If the person asked about health, say 'asked about health' — do not say 'has been exploring health topics' if this is the first mention.",
  "pillars_discussed": "comma-separated list of Wheel pillars that came up (from: presence, health, matter, service, relationships, learning, nature, recreation)",
  "key_developments": "any significant shifts, decisions, breakthroughs, or new information that future conversations should know about. Empty string if nothing significant."
}

Respond with ONLY valid JSON, no other text.
CONSOLIDATE;

    $claudeMessages = [[
        'role' => 'user',
        'content' => "Consolidate this conversation batch ({$firstMsg['timestamp']} to {$lastMsg['timestamp']}):\n\n{$conversationText}"
    ]];

    // Caching + salvage regex (Decision #617).
    // max_tokens=4096 to clear truncation under Opus 4.7's verbosity.
    $apiResult = callClaude($systemPrompt, $claudeMessages, COMPANION_MODEL, null, true, false, 4096);
    if (!$apiResult) return;

    $data = parseJsonFromClaude($apiResult['text']);
    if (!$data || !isset($data['summary'])) {
        error_log("[worker] Consolidation: failed to parse JSON after salvage: " . mb_substr((string)$apiResult['text'], 0, 200));
        return;
    }

    $memory->addConversationSummary(
        $chatId,
        (int)$firstMsg['timestamp'],
        (int)$lastMsg['timestamp'],
        count($messages),
        (int)$firstMsg['id'],
        (int)$lastMsg['id'],
        $data['summary'],
        $data['pillars_discussed'] ?? '',
        $data['key_developments'] ?? ''
    );
}
