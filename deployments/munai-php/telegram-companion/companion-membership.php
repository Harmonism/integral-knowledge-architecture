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
 * Daily MunAI cap (rolling 24h):
 *   free: 5 messages/day
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
 * Cross-surface unification: counter is per-identity (client_id for website,
 * telegram_id for Telegram). Linked accounts (Decision #466) propagate
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
     * Return the rolling-24h MunAI message cap for a given subscription status.
     */
    function membership_daily_cap(string $status): int {
        switch ($status) {
            case 'paid':
            case 'comp':
                return 30;
            case 'free':
            default:
                return 5;
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
        // it") is carried by MunAI's conversational behavior and the
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

        $cap = membership_daily_cap($tier['status']);
        $now = time();

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

        $cap = membership_daily_cap($tier['status']);
        $now = time();

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
        try {
            $db->prepare('
                UPDATE users SET messages_24h_count = messages_24h_count + 1
                WHERE telegram_id = ?
            ')->execute([$telegramId]);
        } catch (PDOException $e) {}
    }
}

if (!function_exists('membership_cap_hit_message')) {
    /**
     * Localized cap-hit message. Returns plain text suitable for both
     * SSE-stream payloads (website) and Telegram sendMessage.
     *
     * Hours-until-reset is derived from reset_at; the message tells the
     * practitioner when they can resume. For free tier, the upgrade CTA
     * is always present. For paid tier, no CTA — the cap is structural.
     */
    function membership_cap_hit_message(string $status, int $resetAt, string $lang = 'en'): string {
        $hoursUntil = max(1, (int)ceil(($resetAt - time()) / 3600));

        if ($status === 'free') {
            $messages = [
                'en' => "You've reached today's limit (5 messages). The relationship continues with Harmonia Membership — unlimited daily MunAI access, the curated practitioner community, and priority for retreats and individual guidance. {$hoursUntil}h until the free counter resets.\n\nUpgrade: https://harmonism.io/member/dashboard.php#membership",
                'fr' => "Vous avez atteint la limite quotidienne (5 messages). La relation continue avec l'adhésion Harmonia — accès illimité à MunAI, la communauté curatée des praticiens et la priorité pour les retraites et la guidance individuelle. Réinitialisation dans {$hoursUntil}h.\n\nUpgrade : https://harmonism.io/fr/member/dashboard.php#membership",
                'es' => "Has alcanzado el límite diario (5 mensajes). La relación continúa con la Membresía Harmonia — acceso ilimitado a MunAI, la comunidad curada de practicantes y prioridad para retiros y guía individual. Reinicio en {$hoursUntil}h.\n\nUpgrade: https://harmonism.io/es/member/dashboard.php#membership",
                'ar' => "لقد بلغت الحد اليومي (٥ رسائل). تستمر العلاقة مع عضوية هارمونيا — وصول غير محدود إلى MunAI، ومجتمع الممارسين المختار، وأولوية المعتكفات والتوجيه الفردي. إعادة التعيين خلال {$hoursUntil} ساعة.\n\nترقية: https://harmonism.io/ar/member/dashboard.php#membership",
                'pt' => "Você atingiu o limite diário (5 mensagens). A relação continua com a Adesão Harmonia — acesso ilimitado a MunAI, a comunidade curada de praticantes e prioridade para retiros e orientação individual. Reinício em {$hoursUntil}h.\n\nUpgrade: https://harmonism.io/pt/member/dashboard.php#membership",
                'de' => "Du hast das Tageslimit erreicht (5 Nachrichten). Die Beziehung geht weiter mit der Harmonia-Mitgliedschaft — unbegrenzter täglicher MunAI-Zugang, die kuratierte Praktizierenden-Gemeinschaft und Priorität für Retreats und individuelle Anleitung. Reset in {$hoursUntil}h.\n\nUpgrade: https://harmonism.io/de/member/dashboard.php#membership",
                'zh' => "您已达到每日限额（5 条消息）。Harmonia 会员资格延续这段关系——MunAI 无限每日访问，精选实践者社区，以及静修与个人指导优先权。{$hoursUntil} 小时后重置。\n\n升级：https://harmonism.io/zh/member/dashboard.php#membership",
                'ja' => "本日の上限に達しました（5メッセージ）。Harmoniaメンバーシップで関係は続きます — MunAI無制限の日常アクセス、厳選された実践者コミュニティ、リトリートと個別ガイダンスの優先権。{$hoursUntil}時間後にリセットされます。\n\nアップグレード: https://harmonism.io/ja/member/dashboard.php#membership",
                'hi' => "आपने आज की सीमा (5 संदेश) पूरी कर ली है। हार्मोनिया सदस्यता के साथ संबंध जारी रहता है — MunAI तक असीमित दैनिक पहुँच, चयनित अभ्यासी समुदाय, और रिट्रीट तथा व्यक्तिगत मार्गदर्शन के लिए प्राथमिकता। {$hoursUntil}घंटे में रीसेट होगा।\n\nअपग्रेड: https://harmonism.io/hi/member/dashboard.php#membership",
                'ru' => "Вы достигли дневного лимита (5 сообщений). Отношения продолжаются с Членством Harmonia — неограниченный ежедневный доступ к MunAI, кураторское сообщество практикующих и приоритет на ретриты и индивидуальное руководство. Сброс через {$hoursUntil}ч.\n\nОбновление: https://harmonism.io/ru/member/dashboard.php#membership",
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
 * heavy use is not a hard cap but a shape-shift in MunAI's posture: as daily
 * frequency rises, MunAI's responses move toward natural closure rather than
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
     * Both blocks include the count value so MunAI can calibrate intensity
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
                . "MunAI responses carry substantive density and the path is walked through "
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
