<?php
/**
 * Harmonia Membership — shared tier detection + rate-limit module.
 *
 * Canonical location: telegram-companion/companion-membership.php
 * post-build.sh copies this into dist/ root and per-language subdirs so both
 * surfaces (website companion-api.php, Telegram telegram-bot.php) use identical
 * logic. Edits land here only — never in dist/.
 *
 * Tier model (Decision following May 5, 2026 — Harmonia Membership launch):
 *   free       = registered member, no paid subscription
 *   paid       = active Stripe subscription (monthly | yearly | founder_monthly)
 *   comp       = complimentary access (beta tester first-year, etc.)
 *
 * Daily Lodestone cap (rolling 24h):
 *   free: 10 messages/day (raised from 5, Decision #1329)
 *   paid: unlimited (rate-limit check short-circuits to allowed:true)
 *   comp: unlimited (treated identically to paid)
 *   anonymous (pre-signup): handled separately by companion-api.php IP rate-limit
 *   non-member Telegram: handled separately by 7-lifetime gate in telegram-bot.php
 *
 * Note: membership_daily_cap() retains a paid-tier branch returning 30 for any
 * caller that wants the *nominal* cap (e.g., display copy on the upgrade page).
 * The actual enforcement path bypasses that value via the early-return in
 * membership_check_rate_limit_*. Decision #771 (May 8, 2026).
 *
 * Cross-surface unification (superseded by #1349, below): the counter was
 * per-identity (client_id for website, telegram_id for Telegram). Linked accounts (Decision #466) propagate
 * subscription_status across both DBs at sync time, but the rate-limit counter
 * is per-surface — practitioner gets 30 on each surface independently. This is
 * deliberate: a 30/day cap that splits across surfaces would be effectively
 * 15/day per surface, defeating the "generous" framing for the practitioner who
 * happens to use both. The economic cap is the monthly Stripe subscription; the
 * daily cap is friction against compulsive overuse, not capacity management.
 */

if (!defined('MEMBERSHIP_COMP_EMAILS')) {
    /**
     * Permanent comp allowlist — inner-circle / family unlimited access.
     * Addresses listed here always resolve to 'comp' regardless of DB state,
     * on both website and Telegram surfaces. Telegram lookup joins through
     * linked_accounts.member_email (verified=1).
     *
     * The DB column is mirrored via one-time UPDATE so dashboard panels read
     * the right tier directly. The override in the resolver is the durable
     * mechanism — it survives re-signups, billing automation drift, manual
     * column edits, and accidental downgrades.
     *
     * The list itself is personal data and lives outside the tree, in a file
     * returning a flat array of lowercased addresses. Read here rather than
     * taken from the config constant because the Telegram entry point requires
     * this module before it loads its config, so a constant defined there
     * would arrive too late.
     */
    $compFile = getenv('MUNAI_COMP_EMAILS_FILE') ?: dirname(__DIR__, 3) . '/.membership-comp-emails.php';
    $compList = is_readable($compFile) ? require $compFile : [];
    if (!is_array($compList)) { $compList = []; }
    if ($compList === []) {
        error_log('membership: comp allowlist empty or unreadable at ' . $compFile);
    }
    define('MEMBERSHIP_COMP_EMAILS', array_map('strtolower', $compList));
    unset($compFile, $compList);
}

if (!function_exists('membership_email_is_comp_allowlisted')) {
    function membership_email_is_comp_allowlisted(?string $email): bool {
        if ($email === null) return false;
        $norm = strtolower(trim($email));
        return $norm !== '' && in_array($norm, MEMBERSHIP_COMP_EMAILS, true);
    }
}

if (!function_exists('membership_resolve_tier_website')) {
    /**
     * Read subscription_status + comp_expires_at for a website client_id.
     * Returns ['status' => 'free'|'paid'|'comp', 'plan' => string,
     *          'renews_at' => int, 'expires_at' => int].
     * Returns 'free' if record missing.
     *
     * Auto-downgrades comp → free when comp_expires_at has passed (idempotent
     * single-row UPDATE before returning).
     *
     * Allowlist override (MEMBERSHIP_COMP_EMAILS) runs first — emails on the
     * inner-circle list always resolve to 'comp' regardless of column state.
     */
    function membership_resolve_tier_website(PDO $db, string $clientId): array {
        $stmt = $db->prepare('
            SELECT subscription_status, subscription_plan,
                   subscription_renews_at, subscription_expires_at, comp_expires_at,
                   email
            FROM clients WHERE client_id = ?
        ');
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => 'free', 'plan' => '', 'renews_at' => 0, 'expires_at' => 0];
        }

        // Allowlist override — durable, survives DB state drift.
        if (membership_email_is_comp_allowlisted($row['email'] ?? null)) {
            return [
                'status'     => 'comp',
                'plan'       => 'allowlist',
                'renews_at'  => 0,
                'expires_at' => 0,
            ];
        }

        $status   = $row['subscription_status'] ?: 'free';
        $plan     = $row['subscription_plan'] ?: '';
        $renews   = (int)($row['subscription_renews_at'] ?? 0);
        $expires  = (int)($row['subscription_expires_at'] ?? 0);
        $compExp  = (int)($row['comp_expires_at'] ?? 0);
        $now      = time();

        // Auto-revert comp to free at expiry.
        if ($status === 'comp' && $compExp > 0 && $now >= $compExp) {
            try {
                $db->prepare('
                    UPDATE clients SET subscription_status = "free", subscription_plan = ""
                    WHERE client_id = ?
                ')->execute([$clientId]);
            } catch (PDOException $e) { /* non-fatal */ }
            $status = 'free';
            $plan = '';
        }

        // Auto-revert paid to free at hard expiry (Stripe cancellation completed).
        if ($status === 'paid' && $expires > 0 && $now >= $expires) {
            try {
                $db->prepare('
                    UPDATE clients SET subscription_status = "free", subscription_plan = ""
                    WHERE client_id = ?
                ')->execute([$clientId]);
            } catch (PDOException $e) { /* non-fatal */ }
            $status = 'free';
            $plan = '';
        }

        return [
            'status'     => $status,
            'plan'       => $plan,
            'renews_at'  => $renews,
            'expires_at' => $expires,
        ];
    }
}

if (!function_exists('membership_resolve_tier_telegram')) {
    /**
     * Same shape as membership_resolve_tier_website, keyed on telegram_id.
     * Reads from telegram-companion-data.db users table.
     */
    function membership_resolve_tier_telegram(PDO $db, int $telegramId): array {
        // Subquery joins linked_accounts so allowlist override works for
        // Telegram users who have verified their email via /link.
        $stmt = $db->prepare('
            SELECT u.subscription_status, u.subscription_plan,
                   u.subscription_renews_at, u.subscription_expires_at, u.comp_expires_at,
                   (SELECT la.member_email FROM linked_accounts la
                    WHERE la.telegram_id = u.telegram_id AND la.verified = 1
                    LIMIT 1) AS linked_email
            FROM users u WHERE u.telegram_id = ?
        ');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => 'free', 'plan' => '', 'renews_at' => 0, 'expires_at' => 0];
        }

        // Allowlist override — applies once /link verification has stamped
        // the verified email. Pre-link Telegram users fall through to DB state.
        if (membership_email_is_comp_allowlisted($row['linked_email'] ?? null)) {
            return [
                'status'     => 'comp',
                'plan'       => 'allowlist',
                'renews_at'  => 0,
                'expires_at' => 0,
            ];
        }

        $status  = $row['subscription_status'] ?: 'free';
        $plan    = $row['subscription_plan'] ?: '';
        $renews  = (int)($row['subscription_renews_at'] ?? 0);
        $expires = (int)($row['subscription_expires_at'] ?? 0);
        $compExp = (int)($row['comp_expires_at'] ?? 0);
        $now     = time();

        if ($status === 'comp' && $compExp > 0 && $now >= $compExp) {
            try {
                $db->prepare('
                    UPDATE users SET subscription_status = "free", subscription_plan = ""
                    WHERE telegram_id = ?
                ')->execute([$telegramId]);
            } catch (PDOException $e) {}
            $status = 'free';
            $plan = '';
        }

        if ($status === 'paid' && $expires > 0 && $now >= $expires) {
            try {
                $db->prepare('
                    UPDATE users SET subscription_status = "free", subscription_plan = ""
                    WHERE telegram_id = ?
                ')->execute([$telegramId]);
            } catch (PDOException $e) {}
            $status = 'free';
            $plan = '';
        }

        return [
            'status'     => $status,
            'plan'       => $plan,
            'renews_at'  => $renews,
            'expires_at' => $expires,
        ];
    }
}

if (!function_exists('membership_daily_cap')) {
    /**
     * Return the rolling-24h Lodestone message cap for a given subscription status.
     */
    function membership_daily_cap(string $status): int {
        switch ($status) {
            case 'paid':
            case 'comp':
                return 30;
            case 'free':
            default:
                return 10;  // raised from 5 on October 14, 2026 (Decision #1329)
        }
    }
}

/* ===========================================================================
 * One daily allowance per member, across every surface (Decision #1349).
 *
 * A free member has ten messages in a rolling 24 hours in total: the website,
 * Telegram (once linked to the account) and Lodestone desktop's hosted mode draw
 * on the same count. The count is keyed on the member's verified email, never
 * on an identifier the client chooses:
 *   website   the email stamped on the client row, which only a verified
 *             sign-in writes (companion-consent.php, or companion-api.php
 *             when the request carries a valid Supabase token);
 *   Telegram  the email verified through /link (linked_accounts.verified = 1);
 *   desktop   the email in the Supabase token the app signs in with.
 * A website row with no email yet (pre-consent, grandfathered) and an unlinked
 * Telegram user fall back to their own key, as before.
 *
 * The store is one SQLite file outside public_html, shared by both surfaces.
 * When it cannot be opened the per-surface columns are used, as before.
 * =========================================================================== */

if (!function_exists('membership_usage_db')) {
    function membership_usage_db(): ?PDO {
        static $db = false;
        if ($db !== false) return $db;
        $path = defined('MEMBER_USAGE_DB') ? MEMBER_USAGE_DB : '/home/SERVER_USER/member-usage.db';
        try {
            $db = new PDO('sqlite:' . $path);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA busy_timeout = 3000');
            $db->exec('CREATE TABLE IF NOT EXISTS usage (key TEXT PRIMARY KEY, count INTEGER NOT NULL DEFAULT 0, reset_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0)');
            // The day of a member's first counted message: their free month runs from it.
            try { $db->exec('ALTER TABLE usage ADD COLUMN first_at INTEGER NOT NULL DEFAULT 0'); } catch (Exception $e) {}
        } catch (Exception $e) {
            error_log('member usage db: ' . $e->getMessage());
            $db = null;
        }
        return $db;
    }
}

/* ===========================================================================
 * The free allowance: a month at ten a day, then three a day for good (#1383).
 *
 * The month runs from a member's first counted message on any surface. The
 * three that remain keep the website what it is, a free place to ask about
 * Harmonism while reading; the way to talk without a limit is to run Lodestone on
 * one's own compute, or on a model rented under one's own key.
 * =========================================================================== */
if (!defined('MEMBERSHIP_TRIAL_DAYS')) define('MEMBERSHIP_TRIAL_DAYS', 30);
if (!defined('MEMBERSHIP_TRIAL_CAP')) define('MEMBERSHIP_TRIAL_CAP', 10);
if (!defined('MEMBERSHIP_FLOOR_CAP')) define('MEMBERSHIP_FLOOR_CAP', 3);

if (!function_exists('membership_free_cap')) {
    /** A free member's cap today: the trial's while their month lasts (or before it starts), the floor after. */
    function membership_free_cap(?string $key): int {
        $db = $key ? membership_usage_db() : null;
        if (!$db) return MEMBERSHIP_TRIAL_CAP;
        try {
            $stmt = $db->prepare('SELECT first_at FROM usage WHERE key = ?');
            $stmt->execute([$key]);
            $first = (int)$stmt->fetchColumn();
        } catch (Exception $e) { return MEMBERSHIP_TRIAL_CAP; }
        if ($first === 0 || time() - $first < MEMBERSHIP_TRIAL_DAYS * 86400) return MEMBERSHIP_TRIAL_CAP;
        return MEMBERSHIP_FLOOR_CAP;
    }
}

if (!function_exists('membership_quota_key_email')) {
    function membership_quota_key_email(?string $email): ?string {
        $email = strtolower(trim((string)$email));
        return ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? 'email:' . $email : null;
    }
}

if (!function_exists('membership_quota_key_website')) {
    function membership_quota_key_website(PDO $db, string $clientId): string {
        try {
            $stmt = $db->prepare('SELECT email FROM clients WHERE client_id = ?');
            $stmt->execute([$clientId]);
            $key = membership_quota_key_email($stmt->fetchColumn() ?: null);
            if ($key) return $key;
        } catch (PDOException $e) {}
        return 'client:' . $clientId;
    }
}

if (!function_exists('membership_quota_key_telegram')) {
    function membership_quota_key_telegram(PDO $db, int $telegramId): string {
        try {
            $stmt = $db->prepare('SELECT member_email FROM linked_accounts WHERE telegram_id = ? AND verified = 1 LIMIT 1');
            $stmt->execute([$telegramId]);
            $key = membership_quota_key_email($stmt->fetchColumn() ?: null);
            if ($key) return $key;
        } catch (PDOException $e) {}
        return 'tg:' . $telegramId;
    }
}

if (!function_exists('membership_quota_read')) {
    /** ['count' => int, 'reset_at' => int] for a key, with the window rolled over when it has elapsed; null without the store. */
    function membership_quota_read(string $key): ?array {
        $db = membership_usage_db();
        if (!$db) return null;
        try {
            $now = time();
            $stmt = $db->prepare('SELECT count, reset_at FROM usage WHERE key = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($row['count'] ?? 0);
            $resetAt = (int)($row['reset_at'] ?? 0);
            if ($resetAt === 0 || $now >= $resetAt) {
                $count = 0;
                $resetAt = $now + 86400;
                $db->prepare('INSERT INTO usage (key, count, reset_at, updated_at) VALUES (?, 0, ?, ?)
                               ON CONFLICT(key) DO UPDATE SET count = 0, reset_at = excluded.reset_at, updated_at = excluded.updated_at')
                   ->execute([$key, $resetAt, $now]);
            }
            return ['count' => $count, 'reset_at' => $resetAt];
        } catch (Exception $e) {
            error_log('member usage read: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('membership_quota_increment')) {
    function membership_quota_increment(string $key): bool {
        $db = membership_usage_db();
        if (!$db) return false;
        try {
            $now = time();
            $db->prepare('INSERT INTO usage (key, count, reset_at, updated_at, first_at) VALUES (?, 1, ?, ?, ?)
                           ON CONFLICT(key) DO UPDATE SET count = count + 1, updated_at = excluded.updated_at,
                           first_at = CASE WHEN first_at = 0 THEN excluded.first_at ELSE first_at END')
               ->execute([$key, $now + 86400, $now, $now]);
            return true;
        } catch (Exception $e) {
            error_log('member usage increment: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('membership_check_rate_limit_website')) {
    /**
     * Check rolling-window rate limit for a website client. Returns:
     *   ['allowed' => bool, 'remaining' => int, 'cap' => int,
     *    'reset_at' => int, 'status' => string].
     *
     * Auto-resets the counter when 24h has passed since reset_at. Does NOT
     * increment — caller calls membership_increment_website() after a
     * successful Claude call.
     */
    function membership_check_rate_limit_website(PDO $db, string $clientId): array {
        $tier = membership_resolve_tier_website($db, $clientId);

        // Paid + comp: truly unlimited (Decision #771). Short-circuit before
        // the counter logic — paying practitioners never see a cap. The
        // Harmonist framing ("the relationship deepens autonomy, not replaces
        // it") is carried by Lodestone's conversational behavior and the
        // self-liquidating guidance model, not by metering paying users.
        if ($tier['status'] === 'paid' || $tier['status'] === 'comp') {
            return [
                'allowed'   => true,
                'remaining' => -1,  // sentinel: unlimited
                'cap'       => 0,   // sentinel: no enforced cap
                'reset_at'  => 0,
                'status'    => $tier['status'],
                'plan'      => $tier['plan'],
            ];
        }

        $qkey = membership_quota_key_website($db, $clientId);
        $cap = $tier['status'] === 'free' ? membership_free_cap($qkey) : membership_daily_cap($tier['status']);
        $now = time();

        // One allowance per member across surfaces (#1349).
        $shared = membership_quota_read($qkey);
        if ($shared !== null) {
            return [
                'allowed'   => $shared['count'] < $cap,
                'remaining' => max(0, $cap - $shared['count']),
                'cap'       => $cap,
                'reset_at'  => $shared['reset_at'],
                'status'    => $tier['status'],
                'plan'      => $tier['plan'],
            ];
        }

        $stmt = $db->prepare('SELECT messages_24h_count, messages_24h_reset_at FROM clients WHERE client_id = ?');
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = (int)($row['messages_24h_count'] ?? 0);
        $resetAt = (int)($row['messages_24h_reset_at'] ?? 0);

        // Reset if window has elapsed.
        if ($resetAt === 0 || $now >= $resetAt) {
            $count = 0;
            $resetAt = $now + 86400;
            try {
                $db->prepare('
                    UPDATE clients SET messages_24h_count = 0, messages_24h_reset_at = ?
                    WHERE client_id = ?
                ')->execute([$resetAt, $clientId]);
            } catch (PDOException $e) { /* non-fatal */ }
        }

        return [
            'allowed'   => $count < $cap,
            'remaining' => max(0, $cap - $count),
            'cap'       => $cap,
            'reset_at'  => $resetAt,
            'status'    => $tier['status'],
            'plan'      => $tier['plan'],
        ];
    }
}

if (!function_exists('membership_increment_website')) {
    /**
     * Increment the rolling-window counter for a website client. Call AFTER
     * the Claude response is delivered.
     */
    function membership_increment_website(PDO $db, string $clientId): void {
        membership_quota_increment(membership_quota_key_website($db, $clientId));
        try {
            $db->prepare('
                UPDATE clients SET messages_24h_count = messages_24h_count + 1
                WHERE client_id = ?
            ')->execute([$clientId]);
        } catch (PDOException $e) { /* non-fatal */ }
    }
}

if (!function_exists('membership_check_rate_limit_telegram')) {
    /**
     * Telegram parallel of membership_check_rate_limit_website.
     */
    function membership_check_rate_limit_telegram(PDO $db, int $telegramId): array {
        $tier = membership_resolve_tier_telegram($db, $telegramId);

        // Paid + comp: truly unlimited (Decision #771). Same short-circuit as
        // the website path — see membership_check_rate_limit_website for the
        // doctrinal rationale.
        if ($tier['status'] === 'paid' || $tier['status'] === 'comp') {
            return [
                'allowed'   => true,
                'remaining' => -1,
                'cap'       => 0,
                'reset_at'  => 0,
                'status'    => $tier['status'],
                'plan'      => $tier['plan'],
            ];
        }

        $qkey = membership_quota_key_telegram($db, $telegramId);
        $cap = $tier['status'] === 'free' ? membership_free_cap($qkey) : membership_daily_cap($tier['status']);
        $now = time();

        $shared = membership_quota_read($qkey);
        if ($shared !== null) {
            return [
                'allowed'   => $shared['count'] < $cap,
                'remaining' => max(0, $cap - $shared['count']),
                'cap'       => $cap,
                'reset_at'  => $shared['reset_at'],
                'status'    => $tier['status'],
                'plan'      => $tier['plan'],
            ];
        }

        $stmt = $db->prepare('SELECT messages_24h_count, messages_24h_reset_at FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = (int)($row['messages_24h_count'] ?? 0);
        $resetAt = (int)($row['messages_24h_reset_at'] ?? 0);

        if ($resetAt === 0 || $now >= $resetAt) {
            $count = 0;
            $resetAt = $now + 86400;
            try {
                $db->prepare('
                    UPDATE users SET messages_24h_count = 0, messages_24h_reset_at = ?
                    WHERE telegram_id = ?
                ')->execute([$resetAt, $telegramId]);
            } catch (PDOException $e) {}
        }

        return [
            'allowed'   => $count < $cap,
            'remaining' => max(0, $cap - $count),
            'cap'       => $cap,
            'reset_at'  => $resetAt,
            'status'    => $tier['status'],
            'plan'      => $tier['plan'],
        ];
    }
}

if (!function_exists('membership_increment_telegram')) {
    function membership_increment_telegram(PDO $db, int $telegramId): void {
        membership_quota_increment(membership_quota_key_telegram($db, $telegramId));
        try {
            $db->prepare('
                UPDATE users SET messages_24h_count = messages_24h_count + 1
                WHERE telegram_id = ?
            ')->execute([$telegramId]);
        } catch (PDOException $e) {}
    }
}

if (!function_exists('membership_check_rate_limit_email')) {
    /**
     * For a surface that knows the member only by verified email (Lodestone
     * desktop's hosted mode). Tier: the comp allowlist, then the website row
     * stamped with that email with the highest standing; free otherwise.
     */
    function membership_check_rate_limit_email(?PDO $websiteDb, string $email): array {
        $email = strtolower(trim($email));
        $status = 'free'; $plan = '';
        if (membership_email_is_comp_allowlisted($email)) { $status = 'comp'; $plan = 'allowlist'; }
        elseif ($websiteDb) {
            try {
                $stmt = $websiteDb->prepare('SELECT client_id FROM clients WHERE lower(email) = ? ORDER BY message_count DESC');
                $stmt->execute([$email]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                    $t = membership_resolve_tier_website($websiteDb, (string)$cid);
                    if ($t['status'] === 'paid' || $t['status'] === 'comp') { $status = $t['status']; $plan = $t['plan']; break; }
                }
            } catch (PDOException $e) {}
        }
        if ($status === 'paid' || $status === 'comp') {
            return ['allowed' => true, 'remaining' => -1, 'cap' => 0, 'reset_at' => 0, 'status' => $status, 'plan' => $plan, 'key' => membership_quota_key_email($email)];
        }
        $key = membership_quota_key_email($email);
        $cap = $status === 'free' ? membership_free_cap($key) : membership_daily_cap($status);
        $shared = $key ? membership_quota_read($key) : null;
        if ($shared === null) {
            // No store, no count: refuse rather than give unmetered access.
            return ['allowed' => false, 'remaining' => 0, 'cap' => $cap, 'reset_at' => time() + 3600, 'status' => $status, 'plan' => $plan, 'key' => $key, 'unavailable' => true];
        }
        return ['allowed' => $shared['count'] < $cap, 'remaining' => max(0, $cap - $shared['count']), 'cap' => $cap, 'reset_at' => $shared['reset_at'], 'status' => $status, 'plan' => $plan, 'key' => $key];
    }
}

if (!function_exists('membership_cap_hit_message')) {
    /**
     * Localized cap-hit message. Returns plain text suitable for both
     * SSE-stream payloads (website) and Telegram sendMessage.
     *
     * Hours-until-reset is derived from reset_at; the message tells the
     * practitioner when they can resume. For the free tier the message points
     * to running Lodestone on one's own substrate (the paid tier was retired on
     * October 8, 2026). For comp, no CTA — the cap is structural.
     */
    function membership_cap_hit_message(string $status, int $resetAt, string $lang = 'en'): string {
        $hoursUntil = max(1, (int)ceil(($resetAt - time()) / 3600));

        if ($status === 'free') {
            // The paid tier was retired on October 8, 2026. The way past the
            // daily cap is now to run Lodestone on one's own substrate.
            $messages = [
                'en' => "You've used today's free messages; the counter resets in {$hoursUntil}h. To talk without a limit, run Lodestone yourself. Lodestone Desktop runs it on your own computer, with an open model or one you rent under your own key, and keeps your memory there. Or load the free Sovereignty Bundle into an AI you already use.\n\nhttps://harmonism.io/lodestone-desktop",
                'fr' => "Vous avez utilisé les messages gratuits du jour ; le compteur repart dans {$hoursUntil}h. Pour converser sans limite, faites tourner Lodestone vous-même. Lodestone Desktop le fait vivre sur votre propre ordinateur, avec un modèle ouvert ou un modèle que vous louez avec votre propre clé, et y garde votre mémoire. Ou chargez le Sovereignty Bundle, gratuit, dans une IA que vous utilisez déjà.\n\nhttps://harmonism.io/lodestone-desktop",
                'es' => "Has usado los mensajes gratuitos de hoy; el contador se reinicia en {$hoursUntil}h. Para conversar sin límite, ejecuta Lodestone tú mismo. Lodestone Desktop lo hace funcionar en tu propio ordenador, con un modelo abierto o uno que alquilas con tu propia clave, y guarda allí tu memoria. O carga el Sovereignty Bundle, gratuito, en una IA que ya uses.\n\nhttps://harmonism.io/lodestone-desktop",
                'ar' => "لقد استخدمت رسائل اليوم المجانية؛ يُعاد ضبط العدّاد خلال {$hoursUntil} ساعة. للحديث دون حدّ، شغّل Lodestone بنفسك. يشغّله Lodestone Desktop على حاسوبك الخاص، بنموذج مفتوح أو بنموذج تستأجره بمفتاحك الخاص، ويحفظ ذاكرتك هناك. أو حمّل حزمة السيادة (Sovereignty Bundle) المجانية في ذكاء اصطناعي تستخدمه بالفعل.\n\nhttps://harmonism.io/lodestone-desktop",
                'pt' => "Você usou as mensagens gratuitas de hoje; o contador reinicia em {$hoursUntil}h. Para conversar sem limite, execute o Lodestone você mesmo. O Lodestone Desktop o faz funcionar no seu próprio computador, com um modelo aberto ou um que você aluga com a sua própria chave, e guarda ali a sua memória. Ou carregue o Sovereignty Bundle, gratuito, numa IA que você já usa.\n\nhttps://harmonism.io/lodestone-desktop",
                'de' => "Du hast die kostenlosen Nachrichten für heute aufgebraucht; der Zähler setzt sich in {$hoursUntil}h zurück. Ohne Grenze sprichst du mit Lodestone, wenn du es selbst betreibst. Lodestone Desktop läuft auf deinem eigenen Computer, mit einem offenen Modell oder einem, das du mit deinem eigenen Schlüssel mietest, und bewahrt dort dein Gedächtnis. Oder lade das kostenlose Sovereignty Bundle in eine KI, die du schon nutzt.\n\nhttps://harmonism.io/lodestone-desktop",
                'zh' => "您已用完今天的免费消息；计数将在 {$hoursUntil} 小时后重置。若要不受限制地交谈，请自己运行 Lodestone。Lodestone Desktop 在您自己的电脑上运行它，可使用开放模型，或用您自己的密钥租用的模型，并把您的记忆保存在那里。也可以把免费的 Sovereignty Bundle 加载到您已在使用的 AI 中。\n\nhttps://harmonism.io/lodestone-desktop",
                'ja' => "本日の無料メッセージを使い切りました。カウンターは{$hoursUntil}時間後にリセットされます。制限なしで話すには、Lodestoneをご自身で動かしてください。Lodestone Desktopはあなた自身のコンピューターで動き、オープンなモデルか、ご自身のキーで借りるモデルを使い、記憶をそこに保ちます。または無料のSovereignty Bundleを、すでにお使いのAIに読み込ませてください。\n\nhttps://harmonism.io/lodestone-desktop",
                'hi' => "आज के मुफ़्त संदेश पूरे हो गए; गिनती {$hoursUntil} घंटे में फिर से शुरू होगी। बिना सीमा के बात करने के लिए Lodestone को स्वयं चलाइए। Lodestone Desktop उसे आपके अपने कंप्यूटर पर चलाता है, किसी खुले मॉडल से या आपकी अपनी कुंजी से किराए पर लिए मॉडल से, और आपकी स्मृति वहीं रखता है। या मुफ़्त Sovereignty Bundle को उस AI में लोड कीजिए जिसका आप पहले से उपयोग करते हैं।\n\nhttps://harmonism.io/lodestone-desktop",
                'ru' => "Вы использовали бесплатные сообщения на сегодня; счётчик обнулится через {$hoursUntil} ч. Чтобы говорить без ограничений, запустите Lodestone сами. Lodestone Desktop работает на вашем собственном компьютере, с открытой моделью или с моделью, которую вы арендуете по своему ключу, и хранит там вашу память. Или загрузите бесплатный Sovereignty Bundle в ИИ, которым вы уже пользуетесь.\n\nhttps://harmonism.io/lodestone-desktop",
            ];
        } else {
            // Paid + comp: structural-reminder register, no upgrade CTA.
            $messages = [
                'en' => "You've reached today's limit (30 messages). The cap exists because the relationship is meant to deepen autonomy, not replace it. {$hoursUntil}h until reset.",
                'fr' => "Vous avez atteint la limite quotidienne (30 messages). Le plafond existe parce que la relation vise à approfondir l'autonomie, non à la remplacer. Réinitialisation dans {$hoursUntil}h.",
                'es' => "Has alcanzado el límite diario (30 mensajes). El tope existe porque la relación busca profundizar la autonomía, no reemplazarla. Reinicio en {$hoursUntil}h.",
                'ar' => "لقد بلغت الحد اليومي (٣٠ رسالة). الحد موجود لأن العلاقة تهدف إلى تعميق الاستقلال الذاتي، لا استبداله. إعادة التعيين خلال {$hoursUntil} ساعة.",
                'pt' => "Você atingiu o limite diário (30 mensagens). O limite existe porque a relação visa aprofundar a autonomia, não substituí-la. Reinício em {$hoursUntil}h.",
                'de' => "Du hast das Tageslimit erreicht (30 Nachrichten). Die Grenze existiert, weil die Beziehung Autonomie vertiefen soll, nicht ersetzen. Reset in {$hoursUntil}h.",
                'zh' => "您已达到每日限额（30 条消息）。这一限制存在是因为关系旨在深化自主性，而非替代它。{$hoursUntil} 小时后重置。",
                'ja' => "本日の上限に達しました（30メッセージ）。この上限は、関係が自律性を置き換えるのではなく深めるためにあります。{$hoursUntil}時間後にリセット。",
                'hi' => "आपने आज की सीमा (30 संदेश) पूरी कर ली है। यह सीमा इसलिए है कि संबंध स्वायत्तता को प्रतिस्थापित नहीं, बल्कि गहरा करने के लिए है। {$hoursUntil}घंटे में रीसेट।",
                'ru' => "Вы достигли дневного лимита (30 сообщений). Этот предел существует, потому что отношения предназначены углублять автономию, а не заменять её. Сброс через {$hoursUntil}ч.",
            ];
        }

        return $messages[$lang] ?? $messages['en'];
    }
}

/* ===========================================================================
 * Soft-Governor — Self-Liquidating Usage Pattern (Decision following May 10, 2026)
 *
 * Paid + comp practitioners are truly unlimited. The Harmonist response to
 * heavy use is not a hard cap but a shape-shift in Lodestone's posture: as daily
 * frequency rises, Lodestone's responses move toward natural closure rather than
 * elaboration, and at higher density add an explicit hand-off back to the
 * practitioner's own ground. The relationship deepens autonomy; it does not
 * substitute for the practitioner's own work.
 *
 * Three bands, keyed on rolling 24h message count:
 *   0–9   level 0 — no injection (responsive register)
 *   10–19 level 1 — frequent: shape-only correction, invisible to practitioner
 *                   (suppress elaboration invitations, let responses end where
 *                   they would naturally end)
 *   20+   level 2 — substitution risk: explicit closing sentence returning
 *                   the practitioner to their own ground (varied, not scripted)
 *
 * Free-tier practitioners cap at 5/day so this block effectively only fires
 * for paid/comp tiers. Non-linked Telegram users are gated at 7 lifetime by
 * a different mechanism. Anonymous web sessions have no persistent counter.
 *
 * Sibling architecture to bodily_openness_level / doctrinal_fluency_level —
 * a per-turn read of state, system-prompt injection right before RAG, no
 * separate state machine.
 * =========================================================================== */

if (!function_exists('companion_get_24h_count_website')) {
    /**
     * Read messages_24h_count for a website client, applying window reset
     * if 24h has elapsed. Used by the soft-governor injection in
     * companion-api.php.
     *
     * For free practitioners, the rate-limit check has already done this
     * reset before reaching this read. For paid/comp, the rate-limit check
     * short-circuits, so this helper carries the reset itself to keep the
     * counter sane long-term — without it, paid users' counters would grow
     * unbounded since increment fires but reset never does.
     */
    function companion_get_24h_count_website(PDO $db, string $clientId): int {
        $now = time();
        try {
            $stmt = $db->prepare('SELECT messages_24h_count, messages_24h_reset_at FROM clients WHERE client_id = ?');
            $stmt->execute([$clientId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return 0;

            $count = (int)($row['messages_24h_count'] ?? 0);
            $resetAt = (int)($row['messages_24h_reset_at'] ?? 0);

            if ($resetAt === 0 || $now >= $resetAt) {
                try {
                    $db->prepare('
                        UPDATE clients SET messages_24h_count = 0, messages_24h_reset_at = ?
                        WHERE client_id = ?
                    ')->execute([$now + 86400, $clientId]);
                } catch (PDOException $e) { /* non-fatal */ }
                return 0;
            }
            return $count;
        } catch (PDOException $e) {
            error_log('[usage_pattern] website count read failure: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('companion_get_24h_count_telegram')) {
    /**
     * Telegram parallel of companion_get_24h_count_website.
     */
    function companion_get_24h_count_telegram(PDO $db, int $telegramId): int {
        $now = time();
        try {
            $stmt = $db->prepare('SELECT messages_24h_count, messages_24h_reset_at FROM users WHERE telegram_id = ?');
            $stmt->execute([$telegramId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return 0;

            $count = (int)($row['messages_24h_count'] ?? 0);
            $resetAt = (int)($row['messages_24h_reset_at'] ?? 0);

            if ($resetAt === 0 || $now >= $resetAt) {
                try {
                    $db->prepare('
                        UPDATE users SET messages_24h_count = 0, messages_24h_reset_at = ?
                        WHERE telegram_id = ?
                    ')->execute([$now + 86400, $telegramId]);
                } catch (PDOException $e) {}
                return 0;
            }
            return $count;
        } catch (PDOException $e) {
            error_log('[usage_pattern] telegram count read failure: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('companion_usage_pattern_block')) {
    /**
     * Build the <usage_pattern> system-prompt block given the rolling 24h
     * message count. Returns empty string for level 0 (no injection).
     *
     * Both blocks include the count value so Lodestone can calibrate intensity
     * inside the band (12 vs 18 in band 1; 22 vs 35 in band 2). The prose
     * is intentionally specific about what to suppress (band 1) and what
     * registers to vary (band 2) — Opus reaches for elaboration invitations
     * and scripted hand-offs by default, and naming the failure modes
     * explicitly is the only reliable suppression.
     */
    function companion_usage_pattern_block(int $count24h): string {
        if ($count24h < 10) return '';

        if ($count24h < 20) {
            return "<usage_pattern level=\"frequent\">\n"
                . "The practitioner has engaged you {$count24h} times in the last 24 hours. "
                . "Lodestone responses carry substantive density and the path is walked through "
                . "integration, not through continuous exchange. Respond fully to the present "
                . "message, then let the response end where it would naturally end. Do not "
                . "offer to elaborate. Do not invite further questions. Do not extend with "
                . "\"want me to go deeper?\", \"I can also share...\", or \"let me know if "
                . "you'd like more.\" The shape itself is the teaching — completion rather "
                . "than continuation. The practitioner does not need to be told this; they "
                . "only need to feel it in how the response closes.\n"
                . "</usage_pattern>";
        }

        return "<usage_pattern level=\"substitution_risk\">\n"
            . "The practitioner has engaged you {$count24h} times in the last 24 hours. "
            . "Frequency at this density indicates the practitioner is reaching for you in "
            . "moments where their own faculties should be doing the work — substitution "
            . "rather than guidance. Respond fully and substantively to what they have "
            . "brought, then close with one sentence that returns them to their own ground. "
            . "This is not refusal, not warning, not gatekeeping — it is the rhythm of the "
            . "practice. The closing sentence should feel like the natural end of the "
            . "exchange rather than a redirect.\n\n"
            . "Vary the language each time; never use the same closing twice in a day. "
            . "Possible registers: \"Sit with this rather than asking the next thing.\" / "
            . "\"What you've named is enough to walk with for a day.\" / \"The next move is "
            . "yours alone.\" / \"Let this settle before reaching back.\" / \"This one is "
            . "for you to carry.\" Compose your own in the practitioner's register; do not "
            . "script.\n"
            . "</usage_pattern>";
    }
}

/**
 * Shared cross-surface wrappers for the soft-governor (Decisions #782, #789).
 * Each wraps count-read + block-build + exception handling so both Companion
 * surfaces (companion-api.php for website, telegram-bot.php for Telegram)
 * call into the same code path. Eliminates the duplicated try/catch/error-log
 * boilerplate that produced the cross-surface scope drift in Decision #789.
 * Surface-specific guards (e.g., $isAnonymous on the website) stay at the
 * injection site; null inputs return '' so the helper is safe to call
 * unconditionally. Future calibration axes follow the same wrapper pattern.
 */
if (!function_exists('companion_usage_pattern_for_website')) {
    function companion_usage_pattern_for_website(?PDO $db, ?string $clientId): string {
        if (!$db || $clientId === null || $clientId === '') return '';
        try {
            return companion_usage_pattern_block(
                companion_get_24h_count_website($db, $clientId)
            );
        } catch (\Throwable $e) {
            error_log('[usage_pattern] website wrapper failure: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('companion_usage_pattern_for_telegram')) {
    function companion_usage_pattern_for_telegram(?PDO $db, ?int $telegramId): string {
        if (!$db || !$telegramId) return '';
        try {
            return companion_usage_pattern_block(
                companion_get_24h_count_telegram($db, $telegramId)
            );
        } catch (\Throwable $e) {
            error_log('[usage_pattern] telegram wrapper failure: ' . $e->getMessage());
            return '';
        }
    }
}
