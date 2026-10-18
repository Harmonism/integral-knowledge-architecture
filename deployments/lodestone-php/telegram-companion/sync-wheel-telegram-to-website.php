<?php
/**
 * Sync Wheel Profile: Telegram → Website
 *
 * For every linked account (verified = 1 in linked_accounts), copy the
 * wheel_profile state from the Telegram companion DB into the Website
 * companion DB. Resolves or creates the website client row by email,
 * following the Decision #466 pattern used by propagateAssessmentToWebsite().
 *
 * Rationale: the Telegram surface tends to hold the richer conversation
 * and is where retroactive-wheel-recalibration runs authoritatively.
 * This script mirrors the result into the website DB so the member
 * dashboard reflects the same state.
 *
 * One-shot by default. Safe to run repeatedly — UPSERT semantics.
 *
 * USAGE
 *   php sync-wheel-telegram-to-website.php
 *       [--dry-run | --apply]    (default: dry-run)
 *       [--user=<telegram_id>]   (restrict to one linked account)
 *       [--verbose]              (dump per-pillar before/after)
 *
 * OUTPUT
 *   JSON-line event stream on stdout:
 *     - start        : run parameters
 *     - skip         : link skipped (no wheel data, no email, etc.)
 *     - pillar       : single pillar mirrored (before/after level)
 *     - user_synced  : all 8 pillars processed for one user
 *     - error        : exception on a single user
 *     - complete     : end-of-run counters
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
// Decision #1372: the website keeps no memory to mirror into.
exit("Retired by Decision #1372: website memory is held by the practitioner.\n");

// ===== ARGS =====
$mode     = 'dry-run';
$userArg  = null;
$verbose  = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run')  { $mode = 'dry-run'; continue; }
    if ($a === '--apply')    { $mode = 'apply';   continue; }
    if ($a === '--verbose')  { $verbose = true;   continue; }
    if (preg_match('/^--user=(\d+)$/', $a, $m)) { $userArg = (int) $m[1]; continue; }
    fwrite(STDERR, "Unknown arg: {$a}\n"); exit(1);
}

// ===== EMIT =====
function emit(array $p): void {
    $p['ts'] = time();
    echo json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    @ob_flush(); @flush();
}

// ===== OPEN DBs =====
$tgDbPath = __DIR__ . '/companion-data.db';
if (!file_exists($tgDbPath)) { fwrite(STDERR, "Telegram DB not found: {$tgDbPath}\n"); exit(1); }

$wDbCandidates = [
    __DIR__ . '/../website-companion-data.db',
    ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/website-companion-data.db',
];
$wDbPath = null;
foreach ($wDbCandidates as $c) {
    if ($c && file_exists($c)) { $wDbPath = realpath($c); break; }
}
if (!$wDbPath) { fwrite(STDERR, "Website DB not found in any candidate location\n"); exit(1); }

$tgDb = new PDO("sqlite:{$tgDbPath}");
$tgDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$wDb = new PDO("sqlite:{$wDbPath}");
$wDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure schema columns exist on the website side.
try { $wDb->exec('ALTER TABLE clients ADD COLUMN email TEXT DEFAULT ""'); } catch (PDOException $e) {}
try { $wDb->exec('ALTER TABLE wheel_profile ADD COLUMN signal_accumulator INTEGER DEFAULT 0'); } catch (PDOException $e) {}
$wDb->exec('CREATE INDEX IF NOT EXISTS idx_clients_email ON clients(email) WHERE email != ""');

// ===== UUID v4 =====
function uuid4(): string {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// ===== RESOLVE OR CREATE WEBSITE CLIENT BY EMAIL =====
function resolveOrCreateWebsiteClient(PDO $wDb, string $emailLower): string {
    $stmt = $wDb->prepare('SELECT client_id FROM clients WHERE email = ? ORDER BY message_count DESC LIMIT 1');
    $stmt->execute([$emailLower]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row['client_id'];

    $newId = uuid4();
    $now = time();
    $wDb->prepare('
        INSERT INTO clients (client_id, display_name, first_seen, last_seen, source, email)
        VALUES (?, "", ?, ?, "telegram-link", ?)
    ')->execute([$newId, $now, $now, $emailLower]);

    $pillars = ['presence', 'health', 'matter', 'service', 'relationships', 'learning', 'nature', 'recreation'];
    $insWheel = $wDb->prepare('
        INSERT OR IGNORE INTO wheel_profile (client_id, pillar, updated_at)
        VALUES (?, ?, ?)
    ');
    foreach ($pillars as $p) $insWheel->execute([$newId, $p, $now]);
    return $newId;
}

// ===== LIST LINKED ACCOUNTS =====
if ($userArg) {
    $stmt = $tgDb->prepare('
        SELECT telegram_id, member_email FROM linked_accounts
        WHERE verified = 1 AND telegram_id = ?
    ');
    $stmt->execute([$userArg]);
} else {
    $stmt = $tgDb->query('
        SELECT telegram_id, member_email FROM linked_accounts
        WHERE verified = 1 ORDER BY telegram_id
    ');
}
$links = $stmt->fetchAll(PDO::FETCH_ASSOC);

emit([
    'type'         => 'start',
    'mode'         => $mode,
    'tg_db'        => $tgDbPath,
    'web_db'       => $wDbPath,
    'linked_count' => count($links),
    'user_filter'  => $userArg,
]);

$counters = [
    'users_synced'    => 0,
    'users_skipped'   => 0,
    'users_errored'   => 0,
    'pillars_updated' => 0,
    'pillars_unchanged' => 0,
];

$tgWheelStmt = $tgDb->prepare('
    SELECT pillar, engagement_level, primary_concerns, strengths, growth_edges,
           resistance_noted, signal_accumulator, notes
    FROM wheel_profile WHERE telegram_id = ?
');

$wUpdateStmt = $wDb->prepare('
    UPDATE wheel_profile
    SET engagement_level = ?, primary_concerns = ?, strengths = ?,
        growth_edges = ?, resistance_noted = ?, signal_accumulator = ?,
        updated_at = ?
    WHERE client_id = ? AND pillar = ?
');

$wSelectStmt = $wDb->prepare('
    SELECT pillar, engagement_level, signal_accumulator
    FROM wheel_profile WHERE client_id = ?
');

foreach ($links as $link) {
    $telegramId = (int) $link['telegram_id'];
    $email      = strtolower(trim((string) $link['member_email']));

    try {
        if ($email === '') {
            $counters['users_skipped']++;
            emit(['type' => 'skip', 'telegram_id' => $telegramId, 'reason' => 'no_email']);
            continue;
        }

        $tgWheelStmt->execute([$telegramId]);
        $tgRows = $tgWheelStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($tgRows)) {
            $counters['users_skipped']++;
            emit(['type' => 'skip', 'telegram_id' => $telegramId, 'reason' => 'no_tg_wheel']);
            continue;
        }

        // Resolve or create (in apply mode only — dry-run reports intent without mutation)
        if ($mode === 'apply') {
            $clientId = resolveOrCreateWebsiteClient($wDb, $email);
        } else {
            // Peek-only for logging. If client doesn't exist, use a placeholder.
            $peek = $wDb->prepare('SELECT client_id FROM clients WHERE email = ? ORDER BY message_count DESC LIMIT 1');
            $peek->execute([$email]);
            $row = $peek->fetch(PDO::FETCH_ASSOC);
            $clientId = $row ? $row['client_id'] : '(would_create_new)';
        }

        // Gather website-side current state for before/after diff
        $wCurrent = [];
        if ($clientId !== '(would_create_new)') {
            $wSelectStmt->execute([$clientId]);
            foreach ($wSelectStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $wCurrent[$r['pillar']] = $r;
            }
        }

        $now = time();
        $updated = 0;
        $unchanged = 0;

        foreach ($tgRows as $t) {
            $pillar    = $t['pillar'];
            $wBefore   = $wCurrent[$pillar]['engagement_level']    ?? 'unknown';
            $wBeforeAc = (int) ($wCurrent[$pillar]['signal_accumulator'] ?? 0);
            $tLevel    = $t['engagement_level'];
            $tAccum    = (int) ($t['signal_accumulator'] ?? 0);

            $isChange = ($wBefore !== $tLevel) || ($wBeforeAc !== $tAccum);
            if ($isChange) $updated++; else $unchanged++;

            if ($verbose || $isChange) {
                emit([
                    'type'            => 'pillar',
                    'telegram_id'     => $telegramId,
                    'client_id'       => $clientId,
                    'pillar'          => $pillar,
                    'website_before'  => $wBefore,
                    'website_before_accum' => $wBeforeAc,
                    'telegram_level'  => $tLevel,
                    'telegram_accum'  => $tAccum,
                    'will_change'     => $isChange,
                ]);
            }

            if ($mode === 'apply' && $clientId !== '(would_create_new)') {
                $wUpdateStmt->execute([
                    $tLevel,
                    (string) $t['primary_concerns'],
                    (string) $t['strengths'],
                    (string) $t['growth_edges'],
                    (int) $t['resistance_noted'],
                    $tAccum,
                    $now,
                    $clientId,
                    $pillar,
                ]);
            }
        }

        $counters['users_synced']++;
        $counters['pillars_updated']   += $updated;
        $counters['pillars_unchanged'] += $unchanged;

        emit([
            'type'         => 'user_synced',
            'telegram_id'  => $telegramId,
            'email'        => $email,
            'client_id'    => $clientId,
            'pillars_changed'   => $updated,
            'pillars_unchanged' => $unchanged,
        ]);
    } catch (\Throwable $e) {
        $counters['users_errored']++;
        emit(['type' => 'error', 'telegram_id' => $telegramId, 'error' => $e->getMessage()]);
    }
}

emit(array_merge(['type' => 'complete'], $counters));
