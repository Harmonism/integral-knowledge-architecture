<?php
/**
 * Companion Memory — Persistent per-user conversation history, profile learning,
 * Wheel-structured engagement tracking, and conversation consolidation.
 *
 * Uses SQLite to store:
 * - User records (Telegram ID, name, first/last seen)
 * - Full conversation history (role + content + timestamp) — permanent archive
 * - Profile notes (what the Companion learns about each person over time)
 * - Wheel profile (structured per-pillar engagement data)
 * - Conversation summaries (consolidated medium-term memory)
 *
 * Memory architecture (three temporal layers):
 * 1. RECENT — Last 20 messages sent to Claude directly (short-term)
 * 2. SUMMARIES — Consolidated per-50-message batches (medium-term)
 * 3. WHEEL PROFILE — Structured pillar-level engagement data (persistent)
 *
 * The raw messages table is the permanent archive — never pruned.
 * Summaries compress older conversations into Claude-readable context.
 * The Wheel profile extracts structured data from free-text observations.
 */

class CompanionMemory {

    private PDO $db;
    private string $dbPath;

    /** @var int Number of messages between conversation consolidations */
    private const CONSOLIDATION_INTERVAL = 50;

    /** @var int Number of recent messages to keep outside consolidation */
    private const RECENT_WINDOW = 20;

    /** @var array The eight Wheel pillars */
    private const WHEEL_PILLARS = [
        'presence', 'health', 'matter', 'service',
        'relationships', 'learning', 'nature', 'recreation'
    ];

    /** @var array Valid engagement levels (developmental scale) */
    private const ENGAGEMENT_LEVELS = [
        'unknown',       // No data yet
        'absent',        // Pillar is not engaged at all — blind spot or avoidance
        'nascent',       // Awareness exists but no real practice or structure
        'functional',    // Basic engagement — meets needs but not examined
        'developing',    // Active work, growing sophistication
        'sophisticated', // Deep engagement, integrated practice
        'sovereign',     // Mastery — this pillar is a source of strength for others
    ];

    public function __construct(?string $dbPath = null) {
        $this->dbPath = $dbPath ?? __DIR__ . '/companion-data.db';
        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->initialize();
    }

    /**
     * Expose the underlying PDO connection for shared modules
     * (companion-membership.php, etc.) that need direct query access.
     */
    public function getDb(): PDO {
        return $this->db;
    }

    /**
     * Create tables if they don't exist.
     */
    private function initialize(): void {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS users (
                telegram_id INTEGER PRIMARY KEY,
                username TEXT DEFAULT "",
                first_name TEXT DEFAULT "",
                first_seen INTEGER NOT NULL,
                last_seen INTEGER NOT NULL,
                message_count INTEGER DEFAULT 0,
                profile_summary TEXT DEFAULT "",
                wheel_position TEXT DEFAULT "",
                notes TEXT DEFAULT "",
                last_consolidation_id INTEGER DEFAULT 0
            )
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id INTEGER NOT NULL,
                role TEXT NOT NULL CHECK(role IN ("user", "assistant")),
                content TEXT NOT NULL,
                timestamp INTEGER NOT NULL,
                telegram_message_id INTEGER DEFAULT NULL,
                FOREIGN KEY (telegram_id) REFERENCES users(telegram_id)
            )
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_messages_user
            ON messages(telegram_id, timestamp DESC)
        ');

        // Migration: add telegram_message_id column to existing databases
        // (CREATE TABLE IF NOT EXISTS won't alter an existing table)
        try {
            $this->db->exec('ALTER TABLE messages ADD COLUMN telegram_message_id INTEGER DEFAULT NULL');
        } catch (PDOException $e) {
            // Column already exists — safe to ignore
        }

        // Migration: MunAI Core version stamping (August 2026 corpus audit).
        // Every assistant row records which MunAI produced it — the declared
        // Core semver, the resolved model ID, and an 8-char hash of the static
        // system-prompt prefix (persona + doctrinal backbone: everything before
        // CACHE_BREAKPOINT_MARKER). The semver is the label; the hash is the
        // ground truth, and it makes drift between the two surfaces visible in
        // the data rather than only in the deploy log. Without this, no change
        // to MunAI can be evaluated against the corpus afterwards — the model
        // moved from opus-4-6 to opus-4-7 mid-corpus with nothing recording it.
        try { $this->db->exec('ALTER TABLE messages ADD COLUMN core_version TEXT DEFAULT NULL'); } catch (PDOException $e) {}
        try { $this->db->exec('ALTER TABLE messages ADD COLUMN model TEXT DEFAULT NULL'); } catch (PDOException $e) {}
        try { $this->db->exec('ALTER TABLE messages ADD COLUMN prompt_hash TEXT DEFAULT NULL'); } catch (PDOException $e) {}

        // Unique index to prevent duplicate storage from Telegram webhook retries
        $this->db->exec('
            CREATE UNIQUE INDEX IF NOT EXISTS idx_messages_dedup
            ON messages(telegram_id, telegram_message_id)
            WHERE telegram_message_id IS NOT NULL
        ');

        // Profile learning — structured observations the Companion accumulates
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS profile_notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id INTEGER NOT NULL,
                category TEXT NOT NULL,
                observation TEXT NOT NULL,
                confidence TEXT DEFAULT "observed",
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (telegram_id) REFERENCES users(telegram_id)
            )
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_profile_user
            ON profile_notes(telegram_id)
        ');

        // Wheel profile — structured per-pillar engagement data
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS wheel_profile (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id INTEGER NOT NULL,
                pillar TEXT NOT NULL,
                engagement_level TEXT DEFAULT "unknown",
                primary_concerns TEXT DEFAULT "",
                strengths TEXT DEFAULT "",
                growth_edges TEXT DEFAULT "",
                resistance_noted INTEGER DEFAULT 0,
                last_discussed INTEGER DEFAULT 0,
                discussion_count INTEGER DEFAULT 0,
                notes TEXT DEFAULT "",
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (telegram_id) REFERENCES users(telegram_id),
                UNIQUE(telegram_id, pillar)
            )
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_wheel_user
            ON wheel_profile(telegram_id)
        ');

        // Migration: add regression tracking columns if absent (idempotent)
        try { $this->db->exec('ALTER TABLE wheel_profile ADD COLUMN regression_signals INTEGER DEFAULT 0'); } catch (\Exception $e) {}
        try { $this->db->exec('ALTER TABLE wheel_profile ADD COLUMN regression_evidence TEXT DEFAULT ""'); } catch (\Exception $e) {}
        // Migration: bidirectional signed signal accumulator (positive = pending upward, negative = pending downward)
        try { $this->db->exec('ALTER TABLE wheel_profile ADD COLUMN signal_accumulator INTEGER DEFAULT 0'); } catch (\Exception $e) {}

        // Conversation summaries — consolidated medium-term memory
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS conversation_summaries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id INTEGER NOT NULL,
                period_start INTEGER NOT NULL,
                period_end INTEGER NOT NULL,
                message_count INTEGER NOT NULL,
                first_message_id INTEGER NOT NULL,
                last_message_id INTEGER NOT NULL,
                summary TEXT NOT NULL,
                pillars_discussed TEXT DEFAULT "",
                key_developments TEXT DEFAULT "",
                created_at INTEGER NOT NULL,
                FOREIGN KEY (telegram_id) REFERENCES users(telegram_id)
            )
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_summaries_user
            ON conversation_summaries(telegram_id, period_end DESC)
        ');

        // Migration: add last_consolidation_id to existing users table if missing
        try {
            $this->db->exec('ALTER TABLE users ADD COLUMN last_consolidation_id INTEGER DEFAULT 0');
        } catch (PDOException $e) {
            // Column already exists — expected on subsequent runs
        }

        // Migration: proactive outreach columns on users
        $chatConsentFreshlyAdded = false;
        $bodilyOpennessFreshlyAdded = false;
        foreach ([
            'outreach_opt_out INTEGER DEFAULT 0',
            'timezone TEXT DEFAULT ""',
            'consecutive_no_response INTEGER DEFAULT 0',
            // Decision #507: Art. 9 explicit consent for special-category data.
            // chat_consent: 0 = not yet consented, 1 = consented.
            // chat_consent_at: unix timestamp of consent (or grandfather stamp).
            // chat_consent_version: identifies which consent language was shown.
            'chat_consent INTEGER DEFAULT 0',
            'chat_consent_at INTEGER DEFAULT 0',
            'chat_consent_version TEXT DEFAULT ""',
            // Decision — Phase A §6.3: graduated doctrinal terminology.
            // 0 = new, 1 = oriented, 2 = fluent, 3 = native.
            // Advances +1 (cap 3) when practitioner uses Harmonist vocabulary unprompted.
            'doctrinal_fluency INTEGER DEFAULT 0',
            // Graduated bodily-register openness — sibling of doctrinal_fluency.
            // 0 = closed, 1 = abstract, 2 = direct, 3 = domain.
            // Set to floor returned by detectBodilyOpenness() when the floor exceeds
            // the current value (monotonic — never regresses). See
            // companion-bodily-openness.php for the four-level architecture and
            // detection tiers.
            'bodily_openness INTEGER DEFAULT 0',
            // Decision — Phase B §6.4 + §6.8: conversation-context signals.
            // dominant_emotion: one of the 16-emotion taxonomy, updated every 10 messages.
            // situation_capsule: ≤60-char free-text capsule of current life context.
            // conversation_state: JSON blob — current_thread, pending_threads,
            //                     open_commitments, last_arc_reading_at.
            // *_updated_at: unix timestamp of last learning-call write; 0 = never.
            'dominant_emotion TEXT DEFAULT ""',
            'situation_capsule TEXT DEFAULT ""',
            'conversation_state TEXT DEFAULT ""',
            'conversation_context_updated_at INTEGER DEFAULT 0',
            // Harmonia Membership — subscription tier columns (parallel to companion-memory.php).
            // subscription_status: 'free' | 'paid' | 'comp'.
            // subscription_plan: 'monthly' | 'yearly' | 'founder_monthly' | empty.
            // *_at: unix timestamps. comp_expires_at: when complimentary access reverts to free.
            // lemonsqueezy_*_id: linkage to website client's LS records via verified-email bridge.
            // messages_24h_count / messages_24h_reset_at: rolling-window MunAI rate-limit.
            'subscription_status TEXT DEFAULT "free"',
            'subscription_plan TEXT DEFAULT ""',
            'subscription_started_at INTEGER DEFAULT 0',
            'subscription_renews_at INTEGER DEFAULT 0',
            'subscription_expires_at INTEGER DEFAULT 0',
            'comp_expires_at INTEGER DEFAULT 0',
            'lemonsqueezy_customer_id TEXT DEFAULT ""',
            'lemonsqueezy_subscription_id TEXT DEFAULT ""',
            'messages_24h_count INTEGER DEFAULT 0',
            'messages_24h_reset_at INTEGER DEFAULT 0',
        ] as $col) {
            try {
                $this->db->exec("ALTER TABLE users ADD COLUMN {$col}");
                if (str_starts_with($col, 'chat_consent ')) {
                    $chatConsentFreshlyAdded = true;
                }
                if (str_starts_with($col, 'bodily_openness ')) {
                    $bodilyOpennessFreshlyAdded = true;
                }
            } catch (PDOException $e) {
                // Column already exists — expected on subsequent runs
            }
        }

        // Grandfather existing active users. Decision #507: any user who has
        // exchanged messages before this migration ran was already using the
        // companion under the old implicit-consent regime; we stamp them as
        // consented with a dedicated version string so the audit trail shows
        // this was a migration event, not a fresh inline-keyboard tap.
        // Runs only once — the flag is set when the chat_consent column is
        // freshly added by this process.
        if ($chatConsentFreshlyAdded) {
            try {
                $stmt = $this->db->prepare('
                    UPDATE users
                    SET chat_consent = 1,
                        chat_consent_at = ?,
                        chat_consent_version = ?
                    WHERE message_count > 0 AND chat_consent = 0
                ');
                $stmt->execute([time(), 'grandfathered-2026-04-18']);
            } catch (PDOException $e) {
                // Non-fatal; the gate will simply prompt old users to consent again.
            }
        }

        // Retroactive bodily-openness scan. When the bodily_openness column
        // is freshly added, walk each user's stored user-role messages and
        // stamp the highest floor returned by detectBodilyOpenness() across
        // their history. This produces the same level the live read-detect-set
        // pipeline would have produced if the system had been live all along.
        // Idempotent: subsequent runs are no-ops because the flag is only set
        // when the column itself is freshly added.
        //
        // Self-includes the bodily-openness module so the migration runs
        // regardless of whether the caller (telegram-bot.php / process-queue.php /
        // notify-account-linking.php / outreach-engine.php / etc.) loaded it
        // before instantiating memory. The flag latches to the column-add event,
        // so missing the scan window once would mean it never runs.
        if ($bodilyOpennessFreshlyAdded) {
            $bodilyModule = __DIR__ . '/companion-bodily-openness.php';
            if (file_exists($bodilyModule)) {
                require_once $bodilyModule;
            }
        }
        if ($bodilyOpennessFreshlyAdded && function_exists('detectBodilyOpenness')) {
            try {
                $users = $this->db->query('
                    SELECT telegram_id FROM users WHERE message_count > 0
                ')->fetchAll(PDO::FETCH_COLUMN);
                $stmt = $this->db->prepare('
                    SELECT content FROM messages
                    WHERE telegram_id = ? AND role = "user"
                ');
                $upd = $this->db->prepare('
                    UPDATE users SET bodily_openness = ? WHERE telegram_id = ?
                ');
                foreach ($users as $tid) {
                    $stmt->execute([(int)$tid]);
                    $highest = 0;
                    while (($content = $stmt->fetchColumn()) !== false) {
                        $floor = detectBodilyOpenness((string)$content);
                        if ($floor > $highest) {
                            $highest = $floor;
                            if ($highest >= 3) break; // can't get higher
                        }
                    }
                    if ($highest > 0) {
                        $upd->execute([$highest, (int)$tid]);
                    }
                }
            } catch (\Throwable $e) {
                error_log('[bodily_openness] retroactive scan failed: ' . $e->getMessage());
                // Non-fatal — affected users start at 0 and advance forward
                // through normal live detection on their next message.
            }
        }

        // Outreach events — full audit trail for every trigger, gate decision, and delivery
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS outreach_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id INTEGER NOT NULL,
                trigger_type TEXT NOT NULL,
                trigger_detail TEXT DEFAULT "",
                confidence REAL NOT NULL,
                gated_out INTEGER DEFAULT 0,
                gate_reason TEXT DEFAULT "",
                message_content TEXT DEFAULT "",
                scheduled_at INTEGER DEFAULT 0,
                sent_at INTEGER DEFAULT 0,
                response_received INTEGER DEFAULT 0,
                response_at INTEGER DEFAULT 0,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (telegram_id) REFERENCES users(telegram_id)
            )
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_outreach_user
            ON outreach_events(telegram_id, created_at DESC)
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_outreach_pending
            ON outreach_events(scheduled_at, sent_at, gated_out)
        ');

        // Response queue — async processing of Claude API calls (Decision #277)
        // The webhook handler queues jobs here; a CLI cron script processes them.
        // This decouples the slow Claude API call from the webhook lifecycle,
        // bypassing LiteSpeed's process-level timeout entirely.
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS response_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                system_prompt TEXT NOT NULL,
                history TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "pending" CHECK(status IN ("pending", "processing", "completed", "failed")),
                attempts INTEGER DEFAULT 0,
                response TEXT DEFAULT NULL,
                error TEXT DEFAULT NULL,
                do_profile_learning INTEGER DEFAULT 0,
                do_consolidation INTEGER DEFAULT 0,
                model TEXT DEFAULT NULL,
                created_at INTEGER NOT NULL,
                started_at INTEGER DEFAULT NULL,
                completed_at INTEGER DEFAULT NULL,
                FOREIGN KEY (chat_id) REFERENCES users(telegram_id)
            )
        ');

        // Migration: add model column to existing response_queue table
        try {
            $this->db->exec('ALTER TABLE response_queue ADD COLUMN model TEXT DEFAULT NULL');
        } catch (PDOException $e) {
            // Column already exists — expected on subsequent runs
        }

        // Migration: add image_data column for multimodal support (Decision #399)
        try {
            $this->db->exec('ALTER TABLE response_queue ADD COLUMN image_data TEXT DEFAULT NULL');
        } catch (PDOException $e) {
            // Column already exists — expected on subsequent runs
        }

        // Migration: burst coalescing (August 2026 corpus audit).
        //
        // The audit measured 123 places where MunAI sent two or more answers
        // with no practitioner turn between them. 113 of those followed a
        // multi-message burst: the webhook enqueued one job per inbound Telegram
        // message, and each job answered its own fragment from a history
        // snapshot that did not contain its siblings' answers. The practitioner
        // sent one thought in three messages and received three replies,
        // frequently contradicting each other.
        //
        // not_before holds a job out of the claimable set until the burst window
        // has elapsed. Each new message in a burst supersedes the pending job
        // and restarts the window, so the burst collapses into one job whose
        // history contains every message in it.
        try {
            $this->db->exec('ALTER TABLE response_queue ADD COLUMN not_before INTEGER DEFAULT 0');
        } catch (PDOException $e) {
            // Column already exists — expected on subsequent runs
        }

        // Migration: add token tracking columns for per-user cost analytics (Decision #XXX)
        foreach ([
            'input_tokens INTEGER DEFAULT NULL',
            'output_tokens INTEGER DEFAULT NULL',
            'cost_usd REAL DEFAULT NULL',
        ] as $col) {
            try {
                $this->db->exec("ALTER TABLE response_queue ADD COLUMN {$col}");
            } catch (PDOException $e) {
                // Column already exists — expected on subsequent runs
            }
        }

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_queue_status
            ON response_queue(status, created_at ASC)
        ');

        // Permanent usage archive — survives queue pruning (Decision #XXX)
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS usage_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                model TEXT DEFAULT NULL,
                input_tokens INTEGER NOT NULL,
                output_tokens INTEGER NOT NULL,
                cost_usd REAL NOT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (chat_id) REFERENCES users(telegram_id)
            )
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_usage_user
            ON usage_log(chat_id, created_at DESC)
        ');

        // Share codes — client-initiated profile sharing with human guides
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS share_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL UNIQUE,
                telegram_id INTEGER NOT NULL,
                source TEXT DEFAULT "telegram",
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                accessed_at INTEGER DEFAULT NULL,
                access_count INTEGER DEFAULT 0,
                max_accesses INTEGER DEFAULT 3,
                revoked INTEGER DEFAULT 0,
                FOREIGN KEY (telegram_id) REFERENCES users(telegram_id)
            )
        ');

        $this->db->exec('
            CREATE UNIQUE INDEX IF NOT EXISTS idx_share_code
            ON share_codes(code)
        ');

        // Linked accounts — Telegram users who have connected a Harmonia member account.
        // Verification flow: Telegram user requests /link → gets a 6-digit code →
        // enters code on the website dashboard (where they're authenticated via Supabase) →
        // only then does the link activate. This prevents anyone from claiming someone else's
        // Telegram identity by knowing their email.
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS linked_accounts (
                telegram_id INTEGER PRIMARY KEY,
                member_email TEXT NOT NULL,
                verification_code TEXT DEFAULT NULL,
                verification_expires INTEGER DEFAULT 0,
                verified INTEGER DEFAULT 0,
                website_client_id TEXT DEFAULT NULL,
                last_sync INTEGER DEFAULT 0,
                linked_at INTEGER NOT NULL,
                FOREIGN KEY (telegram_id) REFERENCES users(telegram_id)
            )
        ');

        // Migration: add verification columns to existing linked_accounts
        foreach ([
            'verification_code TEXT DEFAULT NULL',
            'verification_expires INTEGER DEFAULT 0',
            'verified INTEGER DEFAULT 0',
            'website_client_id TEXT DEFAULT NULL',
            'last_sync INTEGER DEFAULT 0',
        ] as $col) {
            try {
                $this->db->exec("ALTER TABLE linked_accounts ADD COLUMN {$col}");
            } catch (PDOException $e) {
                // Column already exists — expected on subsequent runs
            }
        }

        // Crisis events log (Phase A §6.1). Audit row per detection event:
        // redacted trigger only (no verbatim crisis content), severity,
        // category, and a follow-up flag so the next message from the user
        // can begin with a gentle check-in before resuming business-as-usual.
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS crisis_events (
                event_id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                category TEXT NOT NULL,
                severity TEXT NOT NULL,
                redacted_trigger TEXT DEFAULT "",
                country TEXT DEFAULT "",
                language TEXT DEFAULT "en",
                followup_pending INTEGER DEFAULT 1,
                followup_sent_at INTEGER DEFAULT 0,
                FOREIGN KEY (telegram_id) REFERENCES users(telegram_id)
            )
        ');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_crisis_pending ON crisis_events(telegram_id, followup_pending)');
    }

    /**
     * Ensure a user record exists; update last_seen.
     */
    public function ensureUser(int $telegramId, string $username = '', string $firstName = ''): void {
        $now = time();

        $stmt = $this->db->prepare('SELECT telegram_id FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);

        if ($stmt->fetch()) {
            $update = $this->db->prepare('
                UPDATE users SET last_seen = ?, username = COALESCE(NULLIF(?, ""), username),
                first_name = COALESCE(NULLIF(?, ""), first_name) WHERE telegram_id = ?
            ');
            $update->execute([$now, $username, $firstName, $telegramId]);
        } else {
            $insert = $this->db->prepare('
                INSERT INTO users (telegram_id, username, first_name, first_seen, last_seen)
                VALUES (?, ?, ?, ?, ?)
            ');
            $insert->execute([$telegramId, $username, $firstName, $now, $now]);

            // Initialize wheel profile rows for the new user
            $this->initializeWheelProfile($telegramId);
        }
    }

    /**
     * Art. 9 explicit consent — Decision #507.
     * hasChatConsent: has this user clicked the inline-keyboard consent button
     * (or been grandfathered as a pre-consent active user)?
     */
    public function hasChatConsent(int $telegramId): bool {
        $stmt = $this->db->prepare('SELECT chat_consent FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && (int)$row['chat_consent'] === 1;
    }

    /**
     * Record that a user has explicitly consented to MunAI processing their
     * health / psychological / spiritual conversation content. $version
     * identifies which consent language was shown (e.g. 'telegram-2026-04-18')
     * or 'grandfathered-2026-04-18' for pre-existing active users auto-stamped
     * at migration time.
     */
    public function recordChatConsent(int $telegramId, string $version): void {
        $stmt = $this->db->prepare('
            UPDATE users
            SET chat_consent = 1,
                chat_consent_at = ?,
                chat_consent_version = ?
            WHERE telegram_id = ?
        ');
        $stmt->execute([time(), $version, $telegramId]);
    }

    /**
     * Read chat_consent_at timestamp. 0 means not yet consented.
     */
    public function getChatConsentAt(int $telegramId): int {
        $stmt = $this->db->prepare('SELECT chat_consent_at FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['chat_consent_at'] : 0;
    }

    /**
     * Graduated doctrinal terminology — Phase A §6.3.
     * Returns 0 (new) | 1 (oriented) | 2 (fluent) | 3 (native).
     * Missing user returns 0.
     */
    public function getDoctrinalFluency(int $telegramId): int {
        $stmt = $this->db->prepare('SELECT doctrinal_fluency FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['doctrinal_fluency'] : 0;
    }

    /**
     * Advance doctrinal fluency by +1, capped at $cap (default 3 = native).
     * Called when a practitioner uses Harmonist vocabulary unprompted.
     * Idempotent at the cap. One call = one advance regardless of how many
     * terms the caller detected in a single message (debounce at call site).
     */
    public function advanceDoctrinalFluency(int $telegramId, int $cap = 3): void {
        $cap = max(0, min(3, $cap));
        $stmt = $this->db->prepare('
            UPDATE users
            SET doctrinal_fluency = MIN(doctrinal_fluency + 1, ?)
            WHERE telegram_id = ?
        ');
        $stmt->execute([$cap, $telegramId]);
    }

    /**
     * Graduated bodily-register openness.
     * Returns 0 (closed) | 1 (abstract) | 2 (direct) | 3 (domain).
     * Missing user returns 0.
     */
    public function getBodilyOpenness(int $telegramId): int {
        $stmt = $this->db->prepare('SELECT bodily_openness FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['bodily_openness'] : 0;
    }

    /**
     * Set bodily-register openness to the given floor, monotonic.
     *
     * The detector returns a FLOOR (the highest level a single message
     * warrants), not an increment. This method writes the floor only if it
     * exceeds the current value. Level never regresses — a practitioner
     * who once opened the bodily register stays open; a quiet stretch is
     * not closure.
     *
     * @param int $telegramId
     * @param int $floor      0–3, suggested level from detectBodilyOpenness()
     */
    public function setBodilyOpennessFloor(int $telegramId, int $floor): void {
        $floor = max(0, min(3, $floor));
        if ($floor === 0) return; // 0 floor never advances anything
        $stmt = $this->db->prepare('
            UPDATE users
            SET bodily_openness = MAX(bodily_openness, ?)
            WHERE telegram_id = ?
        ');
        $stmt->execute([$floor, $telegramId]);
    }

    /**
     * Conversation context — Phase B §6.4 + §6.8.
     *
     * Returns the practitioner's most recently assessed emotional register,
     * situation capsule, and structured conversation state. These signals are
     * written by the third learning call (every 10 messages) alongside the
     * free-text profile summary and structured Wheel updates.
     *
     * Return shape:
     *   [
     *     'dominant_emotion'   => 'grateful' | 'overwhelmed' | ... | '',
     *     'situation_capsule'  => '≤60-char capsule' | '',
     *     'conversation_state' => [
     *       'current_thread'       => 'sleep_reset' | '',
     *       'pending_threads'      => [...],
     *       'open_commitments'     => [ ['action' => '...', 'set_at' => ts, 'checked_at' => ts|null], ... ],
     *       'last_arc_reading_at'  => unix_ts | 0,
     *     ],
     *     'updated_at' => unix_ts | 0,
     *   ]
     *
     * Missing user or never-written context returns the empty shape with all
     * defaults — never null. Callers can rely on the array structure being
     * present even when individual fields are empty strings or arrays.
     */
    public function getConversationContext(int $telegramId): array {
        $empty = [
            'dominant_emotion'   => '',
            'situation_capsule'  => '',
            'conversation_state' => [
                'current_thread'       => '',
                'pending_threads'      => [],
                'open_commitments'     => [],
                'last_arc_reading_at'  => 0,
            ],
            'updated_at' => 0,
        ];

        $stmt = $this->db->prepare('
            SELECT dominant_emotion, situation_capsule, conversation_state,
                   conversation_context_updated_at
            FROM users WHERE telegram_id = ?
        ');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $empty;

        $state = $empty['conversation_state'];
        $rawState = $row['conversation_state'] ?? '';
        if ($rawState !== '' && $rawState !== null) {
            $decoded = json_decode($rawState, true);
            if (is_array($decoded)) {
                // Shallow merge — preserve empty-shape defaults for missing keys.
                $state = array_merge($state, $decoded);
            }
        }

        return [
            'dominant_emotion'   => (string)($row['dominant_emotion'] ?? ''),
            'situation_capsule'  => (string)($row['situation_capsule'] ?? ''),
            'conversation_state' => $state,
            'updated_at'         => (int)($row['conversation_context_updated_at'] ?? 0),
        ];
    }

    /**
     * Store the three conversation-context signals together.
     *
     * Single write; atomic at the row level. Called by the conversation-context
     * learning function in process-queue.php after the Opus JSON call returns.
     * The $state array is JSON-encoded at the storage boundary; callers pass it
     * as a structured array matching the shape documented in getConversationContext.
     *
     * Empty strings / empty arrays are valid writes and clear prior values —
     * the learning call may explicitly want to wipe a stale thread. Callers
     * that want a no-op on missing fields should skip the call entirely.
     */
    public function updateConversationContext(
        int $telegramId,
        string $dominantEmotion,
        string $situationCapsule,
        array $conversationState
    ): void {
        // Clip situation_capsule defensively — the prompt asks for ≤60 chars
        // but we don't trust upstream. Cap at a generous 200 to avoid unbounded
        // growth from a misbehaving learning call.
        $situationCapsule = mb_substr($situationCapsule, 0, 200);

        $stmt = $this->db->prepare('
            UPDATE users
            SET dominant_emotion = ?,
                situation_capsule = ?,
                conversation_state = ?,
                conversation_context_updated_at = ?
            WHERE telegram_id = ?
        ');
        $stmt->execute([
            $dominantEmotion,
            $situationCapsule,
            json_encode($conversationState, JSON_UNESCAPED_UNICODE),
            time(),
            $telegramId,
        ]);
    }

    /**
     * Initialize empty wheel profile rows for all 8 pillars.
     */
    private function initializeWheelProfile(int $telegramId): void {
        $now = time();
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO wheel_profile (telegram_id, pillar, updated_at)
            VALUES (?, ?, ?)
        ');
        foreach (self::WHEEL_PILLARS as $pillar) {
            $stmt->execute([$telegramId, $pillar, $now]);
        }
    }

    /**
     * Store a message in conversation history.
     * For user messages, pass the Telegram message_id to prevent duplicate storage
     * from webhook retries. Returns false if the message was already stored (duplicate).
     */
    public function addMessage(int $telegramId, string $role, string $content, ?int $telegramMessageId = null, ?array $stamp = null): bool {
        // Defensive scrub for assistant messages: strip any leading metadata
        // prefix the model might mirror despite system-prompt instruction.
        // Two patterns:
        //   (a) "[YYYY-MM-DD HH:MM UTC]" — Decision #386 legacy timestamp leak.
        //   (b) "[ago: X]" — per-message timing tag from getHistory; asymmetric
        //       to user-only by design, but defense-in-depth scrub here in case
        //       the model ever produces it.
        // Canonical scrub lives in process-queue.php (stripLeadingTimestamp);
        // this is a second line of defense at the storage layer.
        if ($role === 'assistant') {
            $patterns = [
                '/^\s*(?:\*\*)?\[\s*\d{4}-\d{2}-\d{2}(?:[T\s]+\d{1,2}:\d{2}(?::\d{2})?)?\s*(?:UTC|Z)?\s*\](?:\*\*)?\s*/u',
                '/^\s*(?:\*\*)?\[\s*ago\s*:\s*[^\]]{1,40}\](?:\*\*)?\s*/iu',
            ];
            for ($i = 0; $i < 4; $i++) {
                $changed = false;
                foreach ($patterns as $p) {
                    $new = preg_replace($p, '', $content);
                    if ($new !== null && $new !== $content) {
                        $content = $new;
                        $changed = true;
                    }
                }
                if (!$changed) break;
            }
            $content = ltrim($content);
        }

        // Deduplication: if this Telegram message_id was already stored, skip it
        if ($telegramMessageId !== null) {
            $check = $this->db->prepare('
                SELECT 1 FROM messages WHERE telegram_id = ? AND telegram_message_id = ?
            ');
            $check->execute([$telegramId, $telegramMessageId]);
            if ($check->fetch()) {
                return false; // Already stored — webhook retry, skip silently
            }
        }

        // INSERT OR IGNORE handles the race condition: if two concurrent webhook
        // retries both pass the SELECT check above, the unique index on
        // (telegram_id, telegram_message_id) causes the second INSERT to silently fail.
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO messages
                (telegram_id, role, content, timestamp, telegram_message_id,
                 core_version, model, prompt_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $telegramId, $role, $content, time(), $telegramMessageId,
            $stamp['core_version'] ?? null,
            $stamp['model']        ?? null,
            $stamp['prompt_hash']  ?? null,
        ]);

        // If INSERT OR IGNORE was suppressed by the unique constraint, no row was inserted
        if ($this->db->lastInsertId() == 0 && $telegramMessageId !== null) {
            return false;
        }

        $this->db->prepare('
            UPDATE users SET message_count = message_count + 1, last_seen = ? WHERE telegram_id = ?
        ')->execute([time(), $telegramId]);

        return true;
    }

    /**
     * Clear all conversation messages for a user and cancel any pending queue
     * jobs. Preserves all long-term relational state — profile_summary,
     * wheel_profile, conversation_summaries, doctrinal_fluency, bodily_openness,
     * conversation_state, linked_accounts, etc.
     *
     * Used by the /reset command when a conversation has become poisoned by
     * accumulated wrong patterns (e.g., the model committed to base-LLM "I
     * don't have RAG retrieval" disclaimers across many turns and won't
     * recover under system-prompt updates alone — conversation history is
     * stickier than system-prompt revisions).
     *
     * Returns an array with counts for the user-facing confirmation message:
     *   ['messages_deleted' => int, 'pending_cancelled' => int]
     */
    public function clearConversation(int $telegramId): array {
        $msgStmt = $this->db->prepare('DELETE FROM messages WHERE telegram_id = ?');
        $msgStmt->execute([$telegramId]);
        $messagesDeleted = $msgStmt->rowCount();

        // Cancel any pending or in-flight queue items so a stale Claude call
        // doesn't land post-reset and immediately re-poison the conversation
        // by replying to a message the user no longer sees in their history.
        $queueStmt = $this->db->prepare('
            UPDATE response_queue
            SET status = "failed", error = "cancelled by /reset", completed_at = ?
            WHERE chat_id = ? AND status IN ("pending", "processing")
        ');
        $queueStmt->execute([time(), $telegramId]);
        $pendingCancelled = $queueStmt->rowCount();

        return [
            'messages_deleted' => $messagesDeleted,
            'pending_cancelled' => $pendingCancelled,
        ];
    }

    /**
     * Get recent conversation history for Claude's context window.
     * Returns messages in chronological order, limited to the last $limit messages.
     *
     * Each USER message (except the most recent — that one is "now", no tag
     * needed) is prefixed with a relative-time tag like `[ago: 8 hours] `
     * indicating when the message was sent relative to the moment the model
     * is generating its reply. Assistant messages stay clean.
     *
     * The asymmetry is deliberate: Decision #386's leak fired because BOTH
     * roles got timestamped, so the model saw its own past replies starting
     * with bracketed timestamps and learned the pattern as a stylistic
     * feature of MunAI. Stamping only user messages removes that pattern
     * from anything the model produced, so there's no template to mirror.
     * Format [ago: X] is also mechanically distinct from the
     * [YYYY-MM-DD HH:MM UTC] pattern that leaked — no chat-client trains
     * the model to produce "[ago: 8 hours]" as a reply opener.
     *
     * @param int $referenceTime  Unix timestamp to compute "ago" against.
     *                            Defaults to now. Pass the actual current
     *                            message timestamp (Telegram's message.date)
     *                            for queue-processed jobs so "ago" reflects
     *                            the moment the practitioner sent, not the
     *                            moment the worker happens to run.
     */
    public function getHistory(int $telegramId, int $limit = 20, ?int $referenceTime = null): array {
        $referenceTime = $referenceTime ?? time();

        $stmt = $this->db->prepare('
            SELECT role, content, timestamp FROM (
                SELECT role, content, timestamp FROM messages
                WHERE telegram_id = ? ORDER BY timestamp DESC LIMIT ?
            ) sub ORDER BY timestamp ASC
        ');
        $stmt->execute([$telegramId, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ===== RETRIEVAL-TIME SCRUB (guarantees no leak from historical contamination) =====
        // The storage-time scrub in addMessage() only protects messages written
        // *after* the scrub existed — pre-scrub assistant messages stored during
        // the Decision #386 era (and any user messages that slipped through)
        // still carry "[YYYY-MM-DD HH:MM UTC]" prefixes on disk. getHistory is
        // the single choke point for every conversation turn the model sees, so
        // scrubbing here makes the contamination invisible regardless of what
        // lives in the table. Applied to BOTH roles because the model's
        // pattern-matching does not distinguish speaker when seeding its own
        // output format; one prefixed assistant message is enough to re-seed
        // the leak.
        $tsPattern = '/^\s*(?:\*\*)?\[\s*\d{4}-\d{2}-\d{2}(?:[T\s]+\d{1,2}:\d{2}(?::\d{2})?)?\s*(?:UTC|Z)?\s*\](?:\*\*)?\s*/u';
        // Also scrub any [ago: X] prefix that might have leaked into stored
        // messages from the model's own outputs — same belt-and-suspenders
        // discipline as the legacy timestamp scrub.
        $agoPattern = '/^\s*\[\s*ago\s*:\s*[^\]]{1,40}\]\s*/iu';

        // Find the index of the LAST user message in the window — that's
        // the current turn and shouldn't carry an [ago: ...] tag.
        $lastUserIdx = -1;
        foreach ($rows as $i => $row) {
            if ($row['role'] === 'user') $lastUserIdx = $i;
        }

        $out = [];
        foreach ($rows as $i => $row) {
            $content = $row['content'];
            for ($k = 0; $k < 3; $k++) {
                $new = preg_replace($tsPattern, '', $content);
                if ($new === null || $new === $content) break;
                $content = $new;
            }
            $new = preg_replace($agoPattern, '', $content);
            if ($new !== null) $content = $new;
            $content = ltrim($content);

            // Per-message timing on user messages (except the current turn).
            // The current turn is "now"; tagging it would be noise and would
            // give the model a template to mirror. Assistant messages get
            // no tag — that's the asymmetry that prevents the leak.
            if ($row['role'] === 'user' && $i !== $lastUserIdx) {
                $age = max(0, $referenceTime - (int) $row['timestamp']);
                $human = self::humanizeAge($age);
                if ($human !== '') {
                    $content = "[ago: {$human}] " . $content;
                }
            }

            $out[] = [
                'role'    => $row['role'],
                'content' => $content,
            ];
        }
        return $out;
    }

    /**
     * Format a summary's time scope relative to "now" as a human phrase.
     * Returns short anchors the model reasons over directly: "earlier today",
     * "yesterday", "~3 days ago", "~2 weeks ago", "~6 months ago", or
     * "around {Mon YYYY}" for older spans. Both endpoints are considered —
     * if start and end fall in the same bucket, one phrase; if they span
     * buckets, the phrase is rooted at the END of the summary (most recent
     * moment in the consolidated window) since that's the temporal anchor
     * most useful for "when did this happen relative to now?".
     */
    public static function relativeSummaryScope(int $startTs, int $endTs, ?int $now = null): string {
        $now = $now ?? time();
        $endAge = $now - $endTs;
        if ($endAge < 0) {
            return 'just now';
        }

        // Compute calendar-day boundaries from "now" so "yesterday" means
        // strictly the prior calendar day, not "24 hours ago".
        $today     = strtotime('today', $now);
        $yesterday = $today - 86400;

        if ($endTs >= $today) {
            return 'earlier today';
        }
        if ($endTs >= $yesterday && $endTs < $today) {
            return 'yesterday';
        }
        if ($endAge < 86400 * 7) {
            $d = max(1, (int) round($endAge / 86400));
            return "~{$d} day" . ($d === 1 ? '' : 's') . ' ago';
        }
        if ($endAge < 86400 * 30) {
            $w = max(1, (int) round($endAge / (86400 * 7)));
            return "~{$w} week" . ($w === 1 ? '' : 's') . ' ago';
        }
        if ($endAge < 86400 * 365) {
            $mo = max(1, (int) round($endAge / (86400 * 30)));
            return "~{$mo} month" . ($mo === 1 ? '' : 's') . ' ago';
        }
        // Older than a year — relative phrasing loses precision; fall back
        // to "around {Month YYYY}".
        return 'around ' . date('M Y', $endTs);
    }

    /**
     * Format an age in seconds as the compact phrase used in [ago: X] tags
     * on user messages in getHistory. Returns empty string for ages under
     * 30 seconds — those are essentially "now" and tagging them would be
     * noise. Buckets aligned with humanizeDuration, but tighter at the
     * short end where per-message resolution matters most.
     */
    public static function humanizeAge(int $sec): string {
        if ($sec < 30) return '';
        if ($sec < 60) return 'less than a minute';
        if ($sec < 3600) {
            $m = (int) round($sec / 60);
            return $m . ' minute' . ($m === 1 ? '' : 's');
        }
        if ($sec < 86400) {
            $h = (int) round($sec / 3600);
            return $h . ' hour' . ($h === 1 ? '' : 's');
        }
        if ($sec < 86400 * 14) {
            $d = (int) round($sec / 86400);
            return $d . ' day' . ($d === 1 ? '' : 's');
        }
        if ($sec < 86400 * 60) {
            $w = (int) round($sec / (86400 * 7));
            return $w . ' week' . ($w === 1 ? '' : 's');
        }
        $mo = (int) round($sec / (86400 * 30));
        return $mo . ' month' . ($mo === 1 ? '' : 's');
    }

    /**
     * Format a duration in seconds as a coarse human phrase. Used by
     * getHistory's session-boundary marker injection. Same buckets as the
     * temporal_context gap line in buildSystemPrompt — keep them aligned so
     * the two surfaces (system prompt's "time since previous message" and
     * the in-history boundary markers) speak the same language.
     */
    public static function humanizeDuration(int $sec): string {
        if ($sec < 60) {
            return 'less than a minute';
        }
        if ($sec < 3600) {
            $m = (int) round($sec / 60);
            return $m . ' minute' . ($m === 1 ? '' : 's');
        }
        if ($sec < 86400) {
            $h = (int) round($sec / 3600);
            return $h . ' hour' . ($h === 1 ? '' : 's');
        }
        if ($sec < 86400 * 14) {
            $d = (int) round($sec / 86400);
            return $d . ' day' . ($d === 1 ? '' : 's');
        }
        if ($sec < 86400 * 60) {
            $w = (int) round($sec / (86400 * 7));
            return $w . ' week' . ($w === 1 ? '' : 's');
        }
        $mo = (int) round($sec / (86400 * 30));
        return $mo . ' month' . ($mo === 1 ? '' : 's');
    }

    /**
     * Returns the unix timestamp of the most recent user message strictly
     * before the current one (i.e. the "previous exchange" in time). Used
     * to compute the gap between the current turn and the prior turn for
     * temporal context injection. Returns null if there is no prior user
     * message.
     */
    public function getPreviousUserMessageTime(int $telegramId): ?int {
        $stmt = $this->db->prepare("
            SELECT timestamp FROM messages
            WHERE telegram_id = ? AND role = 'user'
            ORDER BY timestamp DESC
            LIMIT 1 OFFSET 1
        ");
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['timestamp'] : null;
    }


    // =====================================================================
    // PROFILE SUMMARY (free-text, backward-compatible)
    // =====================================================================

    public function getProfileSummary(int $telegramId): string {
        $stmt = $this->db->prepare('SELECT profile_summary FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ($row['profile_summary'] ?? '') : '';
    }

    public function updateProfileSummary(int $telegramId, string $summary): void {
        $stmt = $this->db->prepare('UPDATE users SET profile_summary = ? WHERE telegram_id = ?');
        $stmt->execute([$summary, $telegramId]);
    }


    // =====================================================================
    // PROFILE NOTES (structured observations)
    // =====================================================================

    public function getProfileNotes(int $telegramId): array {
        $stmt = $this->db->prepare('
            SELECT category, observation, confidence, updated_at
            FROM profile_notes WHERE telegram_id = ? ORDER BY updated_at DESC
        ');
        $stmt->execute([$telegramId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addProfileNote(int $telegramId, string $category, string $observation, string $confidence = 'observed'): void {
        $now = time();

        $stmt = $this->db->prepare('
            SELECT id FROM profile_notes WHERE telegram_id = ? AND category = ? LIMIT 1
        ');
        $stmt->execute([$telegramId, $category]);
        $existing = $stmt->fetch();

        if ($existing) {
            $update = $this->db->prepare('
                UPDATE profile_notes SET observation = ?, confidence = ?, updated_at = ? WHERE id = ?
            ');
            $update->execute([$observation, $confidence, $now, $existing['id']]);
        } else {
            $insert = $this->db->prepare('
                INSERT INTO profile_notes (telegram_id, category, observation, confidence, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $insert->execute([$telegramId, $category, $observation, $confidence, $now, $now]);
        }
    }


    // =====================================================================
    // WHEEL PROFILE (structured per-pillar engagement data)
    // =====================================================================

    /**
     * Get the full Wheel profile for a user.
     * Returns associative array keyed by pillar name.
     */
    public function getWheelProfile(int $telegramId): array {
        // Ensure rows exist (handles users created before this feature)
        $this->initializeWheelProfile($telegramId);

        $stmt = $this->db->prepare('
            SELECT pillar, engagement_level, primary_concerns, strengths,
                   growth_edges, resistance_noted, last_discussed,
                   discussion_count, notes, updated_at
            FROM wheel_profile
            WHERE telegram_id = ?
            ORDER BY CASE pillar
                WHEN "presence" THEN 0
                WHEN "health" THEN 1
                WHEN "matter" THEN 2
                WHEN "service" THEN 3
                WHEN "relationships" THEN 4
                WHEN "learning" THEN 5
                WHEN "nature" THEN 6
                WHEN "recreation" THEN 7
            END
        ');
        $stmt->execute([$telegramId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $profile = [];
        foreach ($rows as $row) {
            $profile[$row['pillar']] = $row;
        }
        return $profile;
    }

    /**
     * Update a single pillar in the Wheel profile.
     * Only updates fields that are non-null in the $data array.
     */
    public function updateWheelPillar(int $telegramId, string $pillar, array $data): void {
        if (!in_array($pillar, self::WHEEL_PILLARS)) return;

        $this->initializeWheelProfile($telegramId);

        // Ordered engagement scale — used to detect direction of movement
        $levelOrder = [
            'unknown' => 0, 'absent' => 1, 'nascent' => 2,
            'functional' => 3, 'developing' => 4, 'sophisticated' => 5, 'sovereign' => 6,
        ];

        // If a level change is proposed, apply bidirectional confidence-graded threshold logic.
        //
        // The model tags its proposal with `level_change_confidence` ∈ {high, low} — that field
        // is prompt-level metadata, never persisted. High-confidence changes apply on a single
        // signal; low-confidence changes accumulate in a signed `signal_accumulator` until the
        // threshold (|accum| ≥ 2) is crossed. Opposite-direction evidence cancels pending state
        // — noise-cancelling rather than one-way-stickiness.
        if (isset($data['engagement_level'])) {
            $proposed   = strtolower($data['engagement_level']);
            $confidence = strtolower($data['level_change_confidence'] ?? 'low');
            unset($data['level_change_confidence']); // meta field, never a DB column

            $stmt = $this->db->prepare(
                'SELECT engagement_level, signal_accumulator FROM wheel_profile WHERE telegram_id = ? AND pillar = ?'
            );
            $stmt->execute([$telegramId, $pillar]);
            $current = $stmt->fetch(\PDO::FETCH_ASSOC);

            $currentLevel  = strtolower($current['engagement_level'] ?? 'unknown');
            $currentAccum  = (int)($current['signal_accumulator'] ?? 0);
            $currentOrder  = $levelOrder[$currentLevel] ?? 0;
            $proposedOrder = $levelOrder[$proposed]     ?? 0;

            if ($proposedOrder === $currentOrder) {
                // No movement — nothing to threshold, but do not alter accumulator state.
            } else if ($confidence === 'high') {
                // High-confidence change applies immediately, in either direction.
                $data['signal_accumulator']  = 0;
                $data['regression_evidence'] = '';
                // engagement_level passes through
            } else {
                // Low-confidence: bidirectional signed accumulator.
                $proposedDir = $proposedOrder > $currentOrder ? 1 : -1;
                $currentDir  = $currentAccum > 0 ? 1 : ($currentAccum < 0 ? -1 : 0);

                if ($currentDir !== 0 && $currentDir !== $proposedDir) {
                    // Opposite-direction evidence — cancel pending signal, do not apply either.
                    $data['signal_accumulator']  = 0;
                    $data['regression_evidence'] = '';
                    unset($data['engagement_level']);
                } else {
                    $newAccum = $currentAccum + $proposedDir;
                    if (abs($newAccum) >= 2) {
                        // Threshold crossed — apply the level change, reset accumulator.
                        $data['signal_accumulator']  = 0;
                        $data['regression_evidence'] = '';
                        // engagement_level passes through
                    } else {
                        // First signal in this direction — hold level, record pending state.
                        $data['signal_accumulator']  = $newAccum;
                        $data['regression_evidence'] = $data['primary_concerns'] ?? '';
                        unset($data['engagement_level']);
                    }
                }
            }
        }

        $now = time();
        $fields = [];
        $values = [];

        $allowedFields = [
            'engagement_level', 'primary_concerns', 'strengths',
            'growth_edges', 'resistance_noted', 'notes',
            'regression_signals', 'regression_evidence', 'signal_accumulator',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        // Always update discussion metadata
        $fields[] = 'last_discussed = ?';
        $values[] = $now;
        $fields[] = 'discussion_count = discussion_count + 1';
        $fields[] = 'updated_at = ?';
        $values[] = $now;

        $values[] = $telegramId;
        $values[] = $pillar;

        $sql = 'UPDATE wheel_profile SET ' . implode(', ', $fields)
             . ' WHERE telegram_id = ? AND pillar = ?';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
    }

    /**
     * Batch update multiple pillars from a structured profile update.
     * Expects array of: [ 'pillar' => [ 'field' => 'value', ... ], ... ]
     */
    public function updateWheelProfileBatch(int $telegramId, array $pillarUpdates): void {
        foreach ($pillarUpdates as $pillar => $data) {
            if (in_array($pillar, self::WHEEL_PILLARS)) {
                $this->updateWheelPillar($telegramId, $pillar, $data);
            }
        }
    }


    // =====================================================================
    // CONVERSATION SUMMARIES (medium-term memory)
    // =====================================================================

    /**
     * Get all conversation summaries for a user, newest first.
     */
    public function getConversationSummaries(int $telegramId, int $limit = 10): array {
        $stmt = $this->db->prepare('
            SELECT summary, pillars_discussed, key_developments,
                   period_start, period_end, message_count
            FROM conversation_summaries
            WHERE telegram_id = ?
            ORDER BY period_end DESC
            LIMIT ?
        ');
        $stmt->execute([$telegramId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Store a conversation summary.
     */
    public function addConversationSummary(
        int $telegramId,
        int $periodStart,
        int $periodEnd,
        int $messageCount,
        int $firstMessageId,
        int $lastMessageId,
        string $summary,
        string $pillarsDiscussed = '',
        string $keyDevelopments = ''
    ): void {
        $stmt = $this->db->prepare('
            INSERT INTO conversation_summaries
            (telegram_id, period_start, period_end, message_count,
             first_message_id, last_message_id, summary,
             pillars_discussed, key_developments, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $telegramId, $periodStart, $periodEnd, $messageCount,
            $firstMessageId, $lastMessageId, $summary,
            $pillarsDiscussed, $keyDevelopments, time()
        ]);

        // Update the user's last consolidation marker
        $this->db->prepare('
            UPDATE users SET last_consolidation_id = ? WHERE telegram_id = ?
        ')->execute([$lastMessageId, $telegramId]);
    }

    /**
     * Get messages eligible for consolidation — those beyond the recent window
     * that haven't been summarized yet.
     */
    public function getUnconsolidatedMessages(int $telegramId, int $batchSize = 50): array {
        // Get the user's last consolidation marker
        $stmt = $this->db->prepare('SELECT last_consolidation_id FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $lastConsolidated = $user ? (int)$user['last_consolidation_id'] : 0;

        // Get the ID threshold for the recent window (messages we don't consolidate yet)
        $recentStmt = $this->db->prepare('
            SELECT id FROM messages
            WHERE telegram_id = ?
            ORDER BY id DESC
            LIMIT 1 OFFSET ?
        ');
        $recentStmt->execute([$telegramId, self::RECENT_WINDOW]);
        $recentThreshold = $recentStmt->fetch(PDO::FETCH_ASSOC);
        $recentThresholdId = $recentThreshold ? (int)$recentThreshold['id'] : PHP_INT_MAX;

        // Get unconsolidated messages that are outside the recent window
        $stmt = $this->db->prepare('
            SELECT id, role, content, timestamp
            FROM messages
            WHERE telegram_id = ? AND id > ? AND id <= ?
            ORDER BY timestamp ASC
            LIMIT ?
        ');
        $stmt->execute([$telegramId, $lastConsolidated, $recentThresholdId, $batchSize]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if consolidation is due for a user.
     */
    public function needsConsolidation(int $telegramId): bool {
        $messages = $this->getUnconsolidatedMessages($telegramId, 1);
        // Only consolidate when there are at least CONSOLIDATION_INTERVAL messages waiting
        $stmt = $this->db->prepare('SELECT last_consolidation_id FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $lastConsolidated = $user ? (int)$user['last_consolidation_id'] : 0;

        $recentStmt = $this->db->prepare('
            SELECT id FROM messages WHERE telegram_id = ?
            ORDER BY id DESC LIMIT 1 OFFSET ?
        ');
        $recentStmt->execute([$telegramId, self::RECENT_WINDOW]);
        $recentThreshold = $recentStmt->fetch(PDO::FETCH_ASSOC);
        $recentThresholdId = $recentThreshold ? (int)$recentThreshold['id'] : PHP_INT_MAX;

        $countStmt = $this->db->prepare('
            SELECT COUNT(*) FROM messages
            WHERE telegram_id = ? AND id > ? AND id <= ?
        ');
        $countStmt->execute([$telegramId, $lastConsolidated, $recentThresholdId]);
        $count = (int)$countStmt->fetchColumn();

        return $count >= self::CONSOLIDATION_INTERVAL;
    }


    // =====================================================================
    // CONTEXT BUILDING (for system prompt injection)
    // =====================================================================

    /**
     * Build the full profile context string for injection into the system prompt.
     * Combines: user info + Wheel profile + conversation summaries + profile notes.
     */
    public function buildProfileContext(int $telegramId): string {
        $user = $this->getUser($telegramId);
        if (!$user) return '';

        $parts = [];

        // User identity and relationship
        $firstName = $user['first_name'] ?: $user['username'] ?: 'this person';
        $msgCount = $user['message_count'];
        $firstSeen = date('Y-m-d', $user['first_seen']);
        $parts[] = "You are continuing a relationship with {$firstName}. They first contacted you on {$firstSeen} and have exchanged {$msgCount} messages with you.";

        // Wheel profile (structured)
        $wheelContext = $this->buildWheelContext($telegramId);
        if ($wheelContext) {
            $parts[] = $wheelContext;
        }

        // Profile summary (free-text, backward-compatible)
        $summary = $user['profile_summary'];
        if ($summary) {
            $parts[] = "Your accumulated understanding of this person:\n{$summary}";
        }

        // Conversation summaries (medium-term memory).
        //
        // Strip the `[From Telegram]` / `[From Website]` origin prefix stamped in by
        // link-telegram.php's merge step (Decision #451). Provenance stays in the DB for
        // audit, but must never reach the model — the prefix causes platform confusion
        // in either direction (Decision #578 follow-up).
        //
        // Each summary is prefixed with BOTH a relative-time phrase ("~3 days ago"
        // / "yesterday" / "earlier today" / "~6 weeks ago") AND the calendar date
        // range. The relative phrase is what the model uses for temporal reasoning
        // — without it, summaries are timestamped only with absolute calendar
        // dates the model has to subtract from "today" to ground. The model
        // reasons more reliably when the temporal scope is named in the same
        // register as a human would name it. Calendar date kept for precision.
        $summaries = $this->getConversationSummaries($telegramId, 5);
        if ($summaries) {
            $summaryLines = [];
            $now = time();
            foreach ($summaries as $s) {
                $start = date('M j', $s['period_start']);
                $end = date('M j', $s['period_end']);
                $period = ($start === $end) ? $start : "{$start}–{$end}";
                $relative = self::relativeSummaryScope((int)$s['period_start'], (int)$s['period_end'], $now);
                $cleanSummary = preg_replace('/^\[From (?:Telegram|Website)\]\s*/', '', $s['summary']);
                $summaryLines[] = "[{$relative}, {$period}] {$cleanSummary}";
                if ($s['key_developments']) {
                    $summaryLines[] = "  Key developments: {$s['key_developments']}";
                }
            }
            $parts[] = "Conversation history (consolidated):\n" . implode("\n", $summaryLines);
        }

        // Structured notes
        $notes = $this->getProfileNotes($telegramId);
        if ($notes) {
            $noteLines = [];
            foreach ($notes as $note) {
                $noteLines[] = "- {$note['category']}: {$note['observation']}";
            }
            $parts[] = "Specific observations:\n" . implode("\n", $noteLines);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build Wheel-specific context from structured profile data.
     */
    private function buildWheelContext(int $telegramId): string {
        $wheel = $this->getWheelProfile($telegramId);
        if (empty($wheel)) return '';

        // Only include pillars with actual data (not all unknown)
        $hasData = false;
        foreach ($wheel as $pillar => $data) {
            if ($data['engagement_level'] !== 'unknown' || $data['discussion_count'] > 0) {
                $hasData = true;
                break;
            }
        }
        if (!$hasData) return '';

        $lines = ["Wheel of Harmony profile:"];
        foreach ($wheel as $pillar => $data) {
            $level = strtoupper($data['engagement_level']);
            $count = $data['discussion_count'];
            $pillarName = ucfirst($pillar);

            // Skip completely unknown pillars with no discussion history
            if ($data['engagement_level'] === 'unknown' && $count === 0) continue;

            $line = "  {$pillarName}: {$level}";
            if ($count > 0) {
                $lastDate = date('M j', $data['last_discussed']);
                $line .= " (discussed {$count}x, last {$lastDate})";
            }

            $details = [];
            if ($data['primary_concerns']) $details[] = "concerns: {$data['primary_concerns']}";
            if ($data['strengths']) $details[] = "strengths: {$data['strengths']}";
            if ($data['growth_edges']) $details[] = "edges: {$data['growth_edges']}";
            if ($data['resistance_noted']) $details[] = "RESISTANCE NOTED";

            if ($details) {
                $line .= "\n    " . implode(" | ", $details);
            }

            $lines[] = $line;
        }

        // Add Way of Harmony phase if determinable
        $phase = $this->determineWayPhase($wheel);
        if ($phase) {
            $lines[] = "\n  Current Way of Harmony phase: {$phase}";
        }

        return implode("\n", $lines);
    }

    /**
     * Determine the person's approximate phase on the Way of Harmony
     * based on their Wheel engagement pattern.
     */
    private function determineWayPhase(array $wheel): string {
        $levels = [];
        foreach ($wheel as $pillar => $data) {
            $levels[$pillar] = $data['engagement_level'];
        }

        // Tier 1 check: Presence + Health
        $t1 = ['presence', 'health'];
        $t1Engaged = true;
        foreach ($t1 as $p) {
            if (in_array($levels[$p], ['unknown', 'absent', 'nascent'])) {
                $t1Engaged = false;
                break;
            }
        }

        if (!$t1Engaged) {
            return 'Tier 1 — Foundation (Presence + Health). Vessel preparation phase.';
        }

        // Tier 2 check: Matter + Service
        $t2 = ['matter', 'service'];
        $t2Engaged = true;
        foreach ($t2 as $p) {
            if (in_array($levels[$p], ['unknown', 'absent', 'nascent'])) {
                $t2Engaged = false;
                break;
            }
        }

        if (!$t2Engaged) {
            return 'Tier 2 — Structure (Matter + Service). Building worldly foundations.';
        }

        // Tier 3 check: Relationships
        if (in_array($levels['relationships'], ['unknown', 'absent', 'nascent'])) {
            return 'Tier 3 — The Crucible (Relationships). Where practice meets reality.';
        }

        // All tiers functional
        return 'Tier 4 — The Flowering. Integration across all pillars.';
    }


    // =====================================================================
    // UTILITY METHODS
    // =====================================================================

    public function getMessageCount(int $telegramId): int {
        $stmt = $this->db->prepare('SELECT message_count FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['message_count'] : 0;
    }

    // ===== ACCOUNT LINKING GATE =====

    /**
     * Check if a Telegram user has a VERIFIED linked account.
     * Only verified links count for the gate — pending links are still gated.
     */
    public function isAccountLinked(int $telegramId): bool {
        $stmt = $this->db->prepare('SELECT 1 FROM linked_accounts WHERE telegram_id = ? AND verified = 1');
        $stmt->execute([$telegramId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Initiate a link request: store email + generate a 6-digit verification code.
     * The user must enter this code on the website dashboard to prove they own the email.
     * Returns the verification code.
     */
    public function requestAccountLink(int $telegramId, string $email): string {
        $email = strtolower(trim($email));
        $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $expires = time() + 900; // 15 minutes

        $stmt = $this->db->prepare('
            INSERT INTO linked_accounts (telegram_id, member_email, verification_code, verification_expires, verified, linked_at)
            VALUES (?, ?, ?, ?, 0, ?)
            ON CONFLICT(telegram_id) DO UPDATE SET
                member_email = excluded.member_email,
                verification_code = excluded.verification_code,
                verification_expires = excluded.verification_expires,
                verified = 0
        ');
        $stmt->execute([$telegramId, $email, $code, $expires, time()]);

        return $code;
    }

    /**
     * Verify a linking code. Called from the website dashboard (authenticated context).
     * The email must match the authenticated Supabase user, and the code must be valid.
     * Returns the telegram_id on success, null on failure.
     */
    public function verifyAccountLink(string $email, string $code): ?int {
        $email = strtolower(trim($email));
        $stmt = $this->db->prepare('
            SELECT telegram_id FROM linked_accounts
            WHERE member_email = ? AND verification_code = ? AND verification_expires > ? AND verified = 0
        ');
        $stmt->execute([$email, $code, time()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        $telegramId = (int)$row['telegram_id'];

        // Mark as verified, clear the code
        $update = $this->db->prepare('
            UPDATE linked_accounts
            SET verified = 1, verification_code = NULL, verification_expires = 0
            WHERE telegram_id = ?
        ');
        $update->execute([$telegramId]);

        return $telegramId;
    }

    /**
     * Store the website client_id once verified, for cross-platform sync.
     */
    public function setWebsiteClientId(int $telegramId, string $clientId): void {
        $stmt = $this->db->prepare('
            UPDATE linked_accounts SET website_client_id = ? WHERE telegram_id = ?
        ');
        $stmt->execute([$clientId, $telegramId]);
    }

    /**
     * Get the full linked account record (for sync operations).
     */
    public function getLinkedAccount(int $telegramId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM linked_accounts WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find a verified linked account by email (used by website-side verification).
     */
    public function findVerifiedByEmail(string $email): ?array {
        $email = strtolower(trim($email));
        $stmt = $this->db->prepare('SELECT * FROM linked_accounts WHERE member_email = ? AND verified = 1');
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get linked email for a Telegram user (null if not linked, even if pending).
     */
    public function getLinkedEmail(int $telegramId): ?string {
        $stmt = $this->db->prepare('SELECT member_email FROM linked_accounts WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['member_email'] : null;
    }

    /**
     * Check if there's a pending (unverified) link for this Telegram user.
     */
    public function hasPendingLink(int $telegramId): bool {
        $stmt = $this->db->prepare('
            SELECT 1 FROM linked_accounts
            WHERE telegram_id = ? AND verified = 0 AND verification_expires > ?
        ');
        $stmt->execute([$telegramId, time()]);
        return $stmt->fetch() !== false;
    }

    /**
     * Update last_sync timestamp after a successful profile merge.
     */
    public function markSynced(int $telegramId): void {
        $stmt = $this->db->prepare('UPDATE linked_accounts SET last_sync = ? WHERE telegram_id = ?');
        $stmt->execute([time(), $telegramId]);
    }

    public function getUser(int $telegramId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get the last N messages as raw text for profile update context.
     */
    public function getRecentMessagesText(int $telegramId, int $limit = 30): string {
        $history = $this->getHistory($telegramId, $limit);
        $lines = [];
        foreach ($history as $msg) {
            $role = $msg['role'] === 'user' ? 'Person' : 'Companion';
            $lines[] = "{$role}: {$msg['content']}";
        }
        return implode("\n\n", $lines);
    }

    /**
     * Get messages by ID range as formatted text (for consolidation).
     */
    public function getMessageRangeText(int $telegramId, int $fromId, int $toId): string {
        $stmt = $this->db->prepare('
            SELECT role, content FROM messages
            WHERE telegram_id = ? AND id >= ? AND id <= ?
            ORDER BY timestamp ASC
        ');
        $stmt->execute([$telegramId, $fromId, $toId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lines = [];
        foreach ($rows as $msg) {
            $role = $msg['role'] === 'user' ? 'Person' : 'Companion';
            $lines[] = "{$role}: {$msg['content']}";
        }
        return implode("\n\n", $lines);
    }


    // =====================================================================
    // PROACTIVE OUTREACH
    // =====================================================================

    /**
     * Return all users eligible for outreach evaluation.
     * Eligibility: sufficient relationship depth + not opted out.
     * Basic time-based gates (G4, G5) are checked here; per-trigger gates
     * are applied in the OutreachEngine.
     */
    public function getEligibleUsers(): array {
        $minMessages = 5;
        $minRelationshipSecs = 3 * 86400;
        $recentConvCutoff = time() - (48 * 3600); // G4: active within 48h → skip

        $stmt = $this->db->prepare('
            SELECT * FROM users
            WHERE message_count >= ?
              AND first_seen <= ?
              AND last_seen < ?
              AND outreach_opt_out = 0
            ORDER BY last_seen ASC
        ');
        $stmt->execute([
            $minMessages,
            time() - $minRelationshipSecs,
            $recentConvCutoff,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Queue a composed outreach message for delivery.
     * Returns the new event ID.
     */
    public function queueOutreach(
        int $telegramId,
        string $triggerType,
        string $triggerDetail,
        float $confidence,
        string $messageContent,
        int $scheduledAt
    ): int {
        $stmt = $this->db->prepare('
            INSERT INTO outreach_events
                (telegram_id, trigger_type, trigger_detail, confidence,
                 message_content, scheduled_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $telegramId, $triggerType, $triggerDetail, $confidence,
            $messageContent, $scheduledAt, time(),
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Log a trigger that was suppressed by gating (no message composed).
     * Keeps the audit trail complete.
     */
    public function logGatedOutreach(
        int $telegramId,
        string $triggerType,
        string $triggerDetail,
        float $confidence,
        string $gateReason
    ): void {
        $stmt = $this->db->prepare('
            INSERT INTO outreach_events
                (telegram_id, trigger_type, trigger_detail, confidence,
                 gated_out, gate_reason, created_at)
            VALUES (?, ?, ?, ?, 1, ?, ?)
        ');
        $stmt->execute([
            $telegramId, $triggerType, $triggerDetail, $confidence,
            $gateReason, time(),
        ]);
    }

    /**
     * Get the most recent sent outreach events within $days days (for frequency cap G1).
     */
    public function getRecentSentOutreach(int $telegramId, int $days = 7): array {
        $cutoff = time() - ($days * 86400);
        $stmt = $this->db->prepare('
            SELECT id, trigger_type, sent_at FROM outreach_events
            WHERE telegram_id = ? AND sent_at > 0 AND sent_at > ? AND gated_out = 0
            ORDER BY sent_at DESC
        ');
        $stmt->execute([$telegramId, $cutoff]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get messages due for delivery: scheduled, not yet sent, not gated.
     * Called by the hourly delivery script (Task B).
     */
    public function getDueOutreachMessages(): array {
        $stmt = $this->db->prepare('
            SELECT oe.*, u.first_name, u.username
            FROM outreach_events oe
            JOIN users u ON u.telegram_id = oe.telegram_id
            WHERE oe.sent_at = 0
              AND oe.gated_out = 0
              AND oe.scheduled_at > 0
              AND oe.scheduled_at <= ?
              AND oe.message_content != ""
            ORDER BY oe.scheduled_at ASC
        ');
        $stmt->execute([time()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark an outreach event as sent.
     */
    public function markOutreachSent(int $eventId): void {
        $this->db->prepare('
            UPDATE outreach_events SET sent_at = ? WHERE id = ?
        ')->execute([time(), $eventId]);
    }

    /**
     * Mark an outreach event as failed (requeue or abandon).
     */
    public function markOutreachFailed(int $eventId, string $reason): void {
        $this->db->prepare('
            UPDATE outreach_events SET gated_out = 1, gate_reason = ? WHERE id = ?
        ')->execute(["delivery_failed: {$reason}", $eventId]);
    }

    /**
     * Check for any pending (queued but not yet sent) outreach for a user,
     * cancel it, and return its content so the bot can inject the intent.
     * Called at the start of every incoming message — the practitioner reached
     * out first, so proactive delivery is no longer needed.
     */
    public function preemptPendingOutreach(int $telegramId): ?array {
        $stmt = $this->db->prepare('
            SELECT id, trigger_type, trigger_detail, message_content
            FROM outreach_events
            WHERE telegram_id = ? AND sent_at = 0 AND gated_out = 0 AND scheduled_at > 0
            ORDER BY scheduled_at ASC LIMIT 1
        ');
        $stmt->execute([$telegramId]);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pending) return null;

        $this->db->prepare('
            UPDATE outreach_events
            SET gated_out = 1, gate_reason = "preempted_by_practitioner"
            WHERE id = ?
        ')->execute([$pending['id']]);

        return $pending;
    }

    /**
     * When a practitioner sends a message, check if they're responding to a
     * recent sent outreach and mark it as responded.
     * Also resets consecutive_no_response counter.
     */
    public function trackOutreachResponse(int $telegramId): void {
        $cutoff = time() - (7 * 86400);
        $stmt = $this->db->prepare('
            SELECT id FROM outreach_events
            WHERE telegram_id = ?
              AND sent_at > 0
              AND sent_at > ?
              AND response_received = 0
              AND gated_out = 0
            ORDER BY sent_at DESC LIMIT 1
        ');
        $stmt->execute([$telegramId, $cutoff]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return;

        $this->db->prepare('
            UPDATE outreach_events
            SET response_received = 1, response_at = ?
            WHERE id = ?
        ')->execute([time(), $row['id']]);

        // Reset no-response streak
        $this->db->prepare('
            UPDATE users SET consecutive_no_response = 0 WHERE telegram_id = ?
        ')->execute([$telegramId]);
    }

    /**
     * Called by the delivery script after a successful send with no subsequent
     * response within 7 days. Increments streak; disables outreach at 2+.
     */
    public function recordNoResponse(int $telegramId): void {
        $this->db->prepare('
            UPDATE users
            SET consecutive_no_response = consecutive_no_response + 1
            WHERE telegram_id = ?
        ')->execute([$telegramId]);

        // Auto-disable per G3
        $stmt = $this->db->prepare('
            SELECT consecutive_no_response FROM users WHERE telegram_id = ?
        ');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['consecutive_no_response'] >= 2) {
            $this->db->prepare('
                UPDATE users SET outreach_opt_out = 1 WHERE telegram_id = ?
            ')->execute([$telegramId]);
        }
    }

    /**
     * Explicitly opt a user in or out of proactive outreach.
     */
    public function setOutreachOptOut(int $telegramId, bool $optOut): void {
        $this->db->prepare('
            UPDATE users SET outreach_opt_out = ?, consecutive_no_response = 0
            WHERE telegram_id = ?
        ')->execute([$optOut ? 1 : 0, $telegramId]);
    }

    /**
     * Store a timezone string for a user (IANA format, e.g. "Europe/London").
     */
    public function setTimezone(int $telegramId, string $timezone): void {
        $this->db->prepare('
            UPDATE users SET timezone = ? WHERE telegram_id = ?
        ')->execute([$timezone, $telegramId]);
    }

    /**
     * Fetch a user's stored IANA timezone. Returns empty string if unset.
     */
    public function getTimezone(int $telegramId): string {
        $stmt = $this->db->prepare('SELECT timezone FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $tz = $stmt->fetchColumn();
        return is_string($tz) ? $tz : '';
    }

    // ===== ASYNC RESPONSE QUEUE (Decision #277) =====

    /**
     * Queue a Claude API call for async processing.
     * The webhook handler builds the system prompt and history (fast),
     * then queues instead of calling Claude directly.
     * Returns the queue job ID.
     */
    public function queueResponse(int $chatId, string $systemPrompt, array $history, bool $doProfileLearning, bool $doConsolidation, ?string $model = null, ?array $imageData = null, int $burstWindowSec = 0): int {
        $stmt = $this->db->prepare('
            INSERT INTO response_queue (chat_id, system_prompt, history, do_profile_learning, do_consolidation, model, image_data, created_at, not_before)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $now = time();
        $stmt->execute([
            $chatId,
            $systemPrompt,
            json_encode($history),
            $doProfileLearning ? 1 : 0,
            $doConsolidation ? 1 : 0,
            $model,
            $imageData ? json_encode($imageData) : null,
            $now,
            $now + max(0, $burstWindowSec)
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Drop any not-yet-claimed job for this chat.
     *
     * Called immediately before enqueueing a new one. The incoming job's history
     * was rebuilt from the database after the new message was stored, so it
     * already contains every message the superseded job would have answered —
     * the older job is strictly redundant, and running it produces a second
     * reply to what the practitioner experienced as one thought.
     *
     * Only 'pending' rows are touched. A job already claimed by the worker is
     * mid-generation and will deliver; superseding it would leave the
     * practitioner staring at an orphaned placeholder.
     *
     * Returns the number of jobs dropped.
     */
    public function supersedePendingJobs(int $chatId): int {
        $stmt = $this->db->prepare('
            DELETE FROM response_queue
            WHERE chat_id = ? AND status = "pending"
        ');
        $stmt->execute([$chatId]);
        return $stmt->rowCount();
    }

    /**
     * Has this exact text already arrived from this chat inside the window?
     *
     * The audit found 52 cases of a practitioner's client resending an
     * identical message within two minutes — three copies of the same 300-char
     * paragraph one second apart, each with its own Telegram message_id, each
     * therefore passing the message_id dedup, each generating its own answer.
     * Five near-identical replies to one paragraph is the result.
     *
     * Short messages are exempt: "ok", "yes", "hi" are legitimately repeated
     * and carry no content worth deduplicating.
     */
    public function isDuplicateRecentMessage(int $chatId, string $content, int $windowSec = 120, int $minLength = 15): bool {
        if (mb_strlen(trim($content)) <= $minLength) {
            return false;
        }
        $stmt = $this->db->prepare('
            SELECT 1 FROM messages
            WHERE telegram_id = ? AND role = "user" AND content = ? AND timestamp >= ?
            LIMIT 1
        ');
        $stmt->execute([$chatId, $content, time() - $windowSec]);
        return (bool) $stmt->fetch();
    }

    /**
     * Is a job for this chat queued or in flight right now?
     *
     * Guards the resend suppression above. A practitioner resends identical
     * text for two very different reasons: a client double-send, where the
     * first copy is already queued and answering the second duplicates the
     * reply — and silence, where the first copy produced nothing and the
     * resend is a request to be heard. The audit found both: 52 double-sends,
     * and a 2026-05-10 outage in which a practitioner wrote three escalating
     * appeals into a dead bot over ninety minutes and received nothing.
     *
     * Suppressing the second case would make that failure worse. So suppression
     * requires an in-flight job: something is already working on it. If the
     * queue is empty for this chat, the resend goes through and gets answered.
     */
    public function hasActiveJob(int $chatId): bool {
        $stmt = $this->db->prepare('
            SELECT 1 FROM response_queue
            WHERE chat_id = ? AND status IN ("pending", "processing")
            LIMIT 1
        ');
        $stmt->execute([$chatId]);
        return (bool) $stmt->fetch();
    }

    /**
     * Claim the next pending job for processing.
     * Sets status to 'processing' atomically to prevent double-processing.
     * Returns the job row or null if queue is empty.
     */
    public function claimNextJob(): ?array {
        $this->db->beginTransaction();
        try {
            // not_before holds a job out of the claimable set until its burst
            // window has elapsed (see the not_before migration above). Rows
            // predating the migration carry 0 and are claimable immediately.
            $stmt = $this->db->prepare('
                SELECT * FROM response_queue
                WHERE status = "pending"
                  AND (not_before IS NULL OR not_before <= ?)
                ORDER BY created_at ASC
                LIMIT 1
            ');
            $stmt->execute([time()]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                $this->db->rollBack();
                return null;
            }

            // Status-guarded claim: if the row left 'pending' between the SELECT
            // and here, rowCount() is 0 and we return empty-handed rather than
            // re-claiming a job another worker already owns.
            $claim = $this->db->prepare('
                UPDATE response_queue
                SET status = "processing", attempts = attempts + 1, started_at = ?
                WHERE id = ? AND status = "pending"
            ');
            $claim->execute([time(), $job['id']]);

            if ($claim->rowCount() === 0) {
                $this->db->rollBack();
                error_log("[queue] Claim lost for job #{$job['id']} (no longer pending)");
                return null;
            }

            $this->db->commit();

            // Decode history JSON back to array
            $job['history'] = json_decode($job['history'], true);
            return $job;
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Queue claim failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Mark a job as completed after successful Claude call + Telegram send.
     */
    public function completeJob(int $jobId, string $response, ?int $inputTokens = null, ?int $outputTokens = null, ?float $costUsd = null): void {
        $this->db->prepare('
            UPDATE response_queue
            SET status = "completed", response = ?, completed_at = ?,
                input_tokens = ?, output_tokens = ?, cost_usd = ?
            WHERE id = ?
        ')->execute([$response, time(), $inputTokens, $outputTokens, $costUsd, $jobId]);
    }

    /**
     * Mark a job as failed after exhausting retries.
     */
    public function failJob(int $jobId, string $error): void {
        $this->db->prepare('
            UPDATE response_queue SET status = "failed", error = ?, completed_at = ? WHERE id = ?
        ')->execute([$error, time(), $jobId]);
    }

    /**
     * Reset a stuck 'processing' job back to 'pending' if it's been stuck too long.
     * Safety valve for jobs where the processor crashed mid-flight.
     */
    public function resetStuckJobs(int $stuckThresholdSeconds = 300): int {
        $cutoff = time() - $stuckThresholdSeconds;
        $stmt = $this->db->prepare('
            UPDATE response_queue
            SET status = "pending", started_at = NULL
            WHERE status = "processing" AND started_at < ?
        ');
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    /**
     * Clean completed/failed jobs older than the given age.
     * Preserves token data by archiving to usage_log before pruning.
     */
    public function pruneQueue(int $maxAgeSeconds = 86400): int {
        $cutoff = time() - $maxAgeSeconds;

        // Archive token data before pruning (cost tracking survives queue cleanup)
        $this->db->prepare('
            INSERT OR IGNORE INTO usage_log (chat_id, model, input_tokens, output_tokens, cost_usd, created_at)
            SELECT chat_id, model, input_tokens, output_tokens, cost_usd, created_at
            FROM response_queue
            WHERE status IN ("completed", "failed") AND completed_at < ?
              AND input_tokens IS NOT NULL
        ')->execute([$cutoff]);

        $stmt = $this->db->prepare('
            DELETE FROM response_queue WHERE status IN ("completed", "failed") AND completed_at < ?
        ');
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }


    // =====================================================================
    // USAGE / COST TRACKING
    // =====================================================================

    /**
     * Get per-user cost summary.
     * Combines live queue data with archived usage_log.
     * Returns array of rows: chat_id, username, first_name, total_input, total_output, total_cost, job_count
     */
    public function getUserCosts(?int $sinceDaysAgo = null): array {
        $timeFilter = '';
        $params = [];
        if ($sinceDaysAgo !== null) {
            $cutoff = time() - ($sinceDaysAgo * 86400);
            $timeFilter = 'AND created_at >= ?';
            $params = [$cutoff, $cutoff];
        }

        // Union live queue + archived usage_log, then aggregate per user
        $sql = "
            SELECT
                chat_id,
                u.username,
                u.first_name,
                SUM(input_tokens) as total_input,
                SUM(output_tokens) as total_output,
                SUM(cost_usd) as total_cost,
                COUNT(*) as job_count
            FROM (
                SELECT chat_id, input_tokens, output_tokens, cost_usd, created_at
                FROM response_queue WHERE input_tokens IS NOT NULL {$timeFilter}
                UNION ALL
                SELECT chat_id, input_tokens, output_tokens, cost_usd, created_at
                FROM usage_log WHERE 1=1 {$timeFilter}
            ) combined
            LEFT JOIN users u ON u.telegram_id = combined.chat_id
            GROUP BY chat_id
            ORDER BY total_cost DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    // =====================================================================
    // SHARE CODES — client-initiated profile sharing with human guides
    // =====================================================================

    /**
     * Generate a share code for a client. Returns the code string.
     * Code format: 8-char alphanumeric, prefixed by source (e.g., "T-A3B9K2M1")
     * Expires after $days days, max $maxAccesses views.
     */
    public function generateShareCode(int $telegramId, int $days = 7, int $maxAccesses = 3): string {
        // Revoke any existing active codes for this user (one active code at a time)
        $this->db->prepare('
            UPDATE share_codes SET revoked = 1
            WHERE telegram_id = ? AND revoked = 0 AND expires_at > ?
        ')->execute([$telegramId, time()]);

        $code = 'T-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $now = time();
        $expiresAt = $now + ($days * 86400);

        $this->db->prepare('
            INSERT INTO share_codes (code, telegram_id, source, created_at, expires_at, max_accesses)
            VALUES (?, ?, "telegram", ?, ?, ?)
        ')->execute([$code, $telegramId, $now, $expiresAt, $maxAccesses]);

        return $code;
    }

    /**
     * Validate and consume a share code. Returns client data or null.
     * Increments access_count; returns null if expired, revoked, or exhausted.
     */
    public function validateShareCode(string $code): ?array {
        $now = time();
        $stmt = $this->db->prepare('
            SELECT sc.*, u.username, u.first_name, u.message_count, u.profile_summary,
                   u.wheel_position, u.first_seen, u.last_seen
            FROM share_codes sc
            JOIN users u ON u.telegram_id = sc.telegram_id
            WHERE sc.code = ? AND sc.revoked = 0 AND sc.expires_at > ?
              AND sc.access_count < sc.max_accesses
        ');
        $stmt->execute([$code, $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        // Increment access count and record access time
        $this->db->prepare('
            UPDATE share_codes SET access_count = access_count + 1, accessed_at = ? WHERE code = ?
        ')->execute([$now, $code]);

        return $row;
    }

    /**
     * Revoke a share code (client can cancel sharing).
     */
    public function revokeShareCode(int $telegramId): bool {
        $stmt = $this->db->prepare('
            UPDATE share_codes SET revoked = 1
            WHERE telegram_id = ? AND revoked = 0 AND expires_at > ?
        ');
        $stmt->execute([$telegramId, time()]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get the active share code for a user (if any).
     */
    public function getActiveShareCode(int $telegramId): ?string {
        $stmt = $this->db->prepare('
            SELECT code FROM share_codes
            WHERE telegram_id = ? AND revoked = 0 AND expires_at > ? AND access_count < max_accesses
            ORDER BY created_at DESC LIMIT 1
        ');
        $stmt->execute([$telegramId, time()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['code'] : null;
    }

    // ---------------------------------------------------------------------
    // Crisis event logging & follow-up (Phase A §6.1)
    // ---------------------------------------------------------------------

    /**
     * Record a detected crisis event. Stores redacted trigger only — never
     * the verbatim user message. Sets followup_pending = 1 so the next
     * inbound message can trigger a gentle check-in via the system prompt
     * augmentation <crisis_followup_active>.
     */
    public function logCrisisEvent(
        int $telegramId,
        string $category,
        string $severity,
        string $redactedTrigger = '',
        string $country = '',
        string $language = 'en'
    ): int {
        $stmt = $this->db->prepare('
            INSERT INTO crisis_events
                (telegram_id, created_at, category, severity, redacted_trigger, country, language, followup_pending)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ');
        $stmt->execute([
            $telegramId, time(), $category, $severity,
            $redactedTrigger, $country, $language,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Returns the most recent pending-followup crisis event for this user,
     * or null if none pending. Used by telegram-bot.php to augment the next
     * system prompt with a gentle check-in block.
     */
    public function getPendingCrisisFollowup(int $telegramId): ?array {
        $stmt = $this->db->prepare('
            SELECT event_id, category, severity, created_at, country, language
            FROM crisis_events
            WHERE telegram_id = ? AND followup_pending = 1
            ORDER BY created_at DESC LIMIT 1
        ');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function hasPendingCrisisFollowup(int $telegramId): bool {
        return $this->getPendingCrisisFollowup($telegramId) !== null;
    }

    /**
     * Clear the follow-up flag. Called after the model has delivered its
     * gentle check-in on the subsequent turn.
     */
    public function markCrisisFollowupSent(int $telegramId): void {
        $stmt = $this->db->prepare('
            UPDATE crisis_events
            SET followup_pending = 0, followup_sent_at = ?
            WHERE telegram_id = ? AND followup_pending = 1
        ');
        $stmt->execute([time(), $telegramId]);
    }
}
