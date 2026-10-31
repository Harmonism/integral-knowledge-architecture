<?php
require_once __DIR__ . '/llm-gateway.php';  // model provider switch (LLM_PROVIDER in server config)
/**
 * The Wisdom Canon — check-in composer.
 *
 * Runs hourly via server cron. Reads the taper slots that have come due,
 * decides which of the four kinds each one is from live arc state, composes
 * one message, and queues it into outreach_events. Delivery is
 * outreach-deliver.php's job, on its own hourly cron — this script never
 * sends.
 *
 * Cron (Hostinger cPanel, interpreter must be php per Decision #797):
 *   at minute 20 of every hour, run this file with php
 *   php /home/SERVER_USER/domains/harmonism.io/public_html/telegram-companion/reading-checkin.php
 *
 * Deliberately offset from outreach-deliver.php's hour mark so a slot composed
 * this hour is delivered within the same hour rather than the next.
 *
 * WHAT THIS SCRIPT WILL NOT DO
 *   - It never adds a slot. readingBuildTaper() is the only writer of
 *     reading_checkins and it runs once, at covenant time. A check-in that is
 *     skipped is spent, not rescheduled.
 *   - It never escalates on silence. A gap is a gap. Only a stalled unit —
 *     opened three or more times across more than a day and still unfinished —
 *     converts a slot into a Friction message, and that message offers to
 *     change the plan rather than to push the reader.
 *   - It never composes a reading of the text. See companion-reading.php's
 *     header, and Decisions #633 and #1109.
 *
 * Interaction with the general outreach engine, stated because it is a real
 * behavioural change rather than an accident: reading check-ins land in the
 * same outreach_events table, so outreach-engine.php's G1 frequency cap sees
 * them and holds its own composition back for seven days. During an active arc
 * the reading check-in is the contact. As the taper falls to silence, general
 * outreach resumes on its own.
 */

// Lodestone on Telegram is closed (Decision #1560): nothing scheduled is sent.
require_once __DIR__ . '/telegram-closed.php';
if (LODESTONE_TELEGRAM_CLOSED) { fwrite(STDERR, "Lodestone on Telegram is closed; nothing sent.\n"); exit(0); }


error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/outreach-errors.log');

require_once __DIR__ . '/telegram-memory.php';
require_once __DIR__ . '/companion-reading.php';

$configPath = '/home/SERVER_USER/.telegram-config.php';
if (!file_exists($configPath)) {
    error_log('reading-checkin: config not found at ' . $configPath);
    exit(1);
}
require_once $configPath;

if (!defined('ANTHROPIC_API_KEY') || !defined('COMPANION_MODEL')) {
    error_log('reading-checkin: config incomplete (ANTHROPIC_API_KEY / COMPANION_MODEL)');
    exit(1);
}

$dbPath = __DIR__ . '/companion-data.db';
$memory = new CompanionMemory($dbPath);
$db     = $memory->getDb();
readingEnsureSchema($db);

$runner = new ReadingCheckIn($memory, $db);
$runner->run();


// ==========================================================================

class ReadingCheckIn {

    private $memory;
    private PDO $db;

    /**
     * Composition runs on COMPANION_MODEL — the same config-defined model the
     * two chat surfaces use. Model ids are never written literally here:
     * Decision #703 requires every bare id be verified against Anthropic's
     * /v1/models before use, and #704 requires a rotation to reach both
     * Tier-A configs. Reusing the constant inherits both disciplines.
     */
    private const MAX_TOKENS = 2048;  // covers Opus 5.5 adaptive thinking + reply

    /** A slot more than this far past due is spent, not delivered late. */
    private const STALE_AFTER_SEC = 172800; // 48h

    public function __construct($memory, PDO $db) {
        $this->memory = $memory;
        $this->db = $db;
    }

    public function run(): void {
        $due = readingDueCheckins($this->db, time());
        if (!$due) return;

        $queued = 0; $skipped = 0;

        foreach ($due as $slot) {
            $checkinId = (int)$slot['id'];
            $arcId     = (int)$slot['arc_id'];

            if ((string)$slot['subject_kind'] !== 'telegram') continue;
            $chatId = (int)$slot['subject_id'];
            if ($chatId === 0) { readingMarkCheckin($this->db, $checkinId, 'skipped'); continue; }

            if (time() - (int)$slot['due_at'] > self::STALE_AFTER_SEC) {
                readingMarkCheckin($this->db, $checkinId, 'skipped');
                $skipped++;
                continue;
            }

            $arc = readingGetArc($this->db, $arcId);
            if (!$arc || (string)$arc['state'] !== 'active') {
                readingMarkCheckin($this->db, $checkinId, 'cancelled');
                continue;
            }

            // Outreach opt-out is honoured for every kind except the close,
            // which is the handoff and is the one message a reader who opted
            // out of everything still receives.
            if ($this->optedOut($chatId) && (string)$slot['kind'] !== 'close') {
                readingMarkCheckin($this->db, $checkinId, 'skipped');
                $skipped++;
                continue;
            }

            $kind = $this->decideKind($arc, (string)$slot['kind']);

            // discuss=no: only the close survives.
            if ((string)$arc['discuss'] === 'no' && $kind !== 'close') {
                readingMarkCheckin($this->db, $checkinId, 'skipped');
                $skipped++;
                continue;
            }

            $message = $this->compose($arc, $kind);
            if ($message === null || trim($message) === '') {
                readingMarkCheckin($this->db, $checkinId, 'skipped');
                $skipped++;
                error_log("reading-checkin: composition failed for arc {$arcId}, slot {$checkinId}");
                continue;
            }

            $outreachId = $this->memory->queueOutreach(
                $chatId,
                'reading_' . $kind,
                json_encode(['arc_id' => $arcId, 'title' => $arc['title'], 'checkin_id' => $checkinId]),
                1.0,
                $message,
                time()
            );

            readingMarkCheckin($this->db, $checkinId, 'queued', (int)$outreachId, $kind);
            $queued++;

            // The close is the last thing the arc does.
            if ($kind === 'close') readingCloseArc($this->db, $arcId, 'completed');

            usleep(200000);
        }

        if ($queued || $skipped) {
            error_log("reading-checkin: {$queued} queued, {$skipped} skipped.");
        }
    }

    /**
     * Which of the four kinds this slot is. The taper fixed the slot; live
     * state decides what fills it.
     */
    private function decideKind(array $arc, string $slotKind): string {
        if ($slotKind === 'close') return 'close';

        $prog = readingProgress($this->db, $arc);
        if (!empty($prog['finished'])) return 'close';

        if (readingStalledUnit($this->db, (int)$arc['id']) !== null) return 'friction';

        $ret = readingReturnCandidate($this->db, (int)$arc['id'], (int)$prog['current']);
        if ($ret !== null && (int)$prog['completed'] >= 3) {
            // Alternate: a Return every third eligible slot, so the reader's
            // own words come back without becoming the only thing that does.
            $sent = readingTaperRemaining($this->db, (int)$arc['id'])['sent'];
            if ($sent > 0 && $sent % 3 === 0) return 'return';
        }

        return 'arrival';
    }

    private function compose(array $arc, string $kind): ?string {
        $prog    = readingProgress($this->db, $arc);
        $station = readingStation($arc, (int)$prog['current']);
        $ret     = $kind === 'return'
            ? readingReturnCandidate($this->db, (int)$arc['id'], (int)$prog['current'])
            : null;

        $system = $this->personaPreamble()
                . "\n\n" . readingSystemPromptBlock($this->db, $arc)
                . "\n\nYou are composing a single proactive message. It will be delivered as-is, "
                . "with no greeting from you and no reply from them yet. Write only the message body. "
                . "No preamble, no sign-off, no emoji, no markdown headings. "
                . "Two to four sentences, and fewer is better.";

        $user = readingCheckinPrompt($kind, $station, $ret);

        $text = $this->callClaude($system, [['role' => 'user', 'content' => $user]]);
        if ($text === null) return null;

        $text = trim($text);
        if (function_exists('mb_strlen') && mb_strlen($text) > 1200) {
            $text = mb_substr($text, 0, 1200);
        }
        return $text;
    }

    /**
     * The persona floor. The full doctrinal backbone is deliberately NOT
     * loaded here: this call composes one question about a book the
     * practitioner is reading, and the backbone's presence is what would tempt
     * the model toward doctrine — which is exactly the drift #633 forbids.
     */
    private function personaPreamble(): string {
        return "You are Lodestone, Harmonia's guide of alignment. You speak with warmth but not "
             . "softness, directness but not aggression. You never flatter, never pathologise, "
             . "never perform enthusiasm. You treat the practitioner as sovereign. "
             . "Your telos is their eventual sovereignty, not their continued attendance.";
    }

    /** protected so a test harness can substitute a stub composer. */
    protected function callClaude(string $system, array $messages): ?string {
        $payload = [
            'model' => COMPANION_MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'output_config' => ['effort' => 'low'],
            'system' => $system,
            'messages' => $messages,
        ];

        $ch = llm_curl_init();
        llm_curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $res  = llm_curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_errno($ch);
        curl_close($ch);

        if ($err || $code !== 200) {
            error_log("reading-checkin: Anthropic HTTP {$code}, curl {$err}: " . substr((string)$res, 0, 400));
            return null;
        }

        $data = json_decode((string)$res, true);
        // Opus 5.5: replies may open with thinking blocks — keep only text blocks so content[0] is the first text block.
        if (is_array($data) && isset($data['content']) && is_array($data['content'])) { $data['content'] = array_values(array_filter($data['content'], function ($b) { return ($b['type'] ?? '') === 'text'; })); }
        $out  = $data['content'][0]['text'] ?? null;
        return is_string($out) ? $out : null;
    }

    private function optedOut(int $chatId): bool {
        try {
            $stmt = $this->db->prepare('SELECT outreach_opt_out FROM users WHERE telegram_id = ?');
            $stmt->execute([$chatId]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (Exception $e) {
            return false;
        }
    }
}
