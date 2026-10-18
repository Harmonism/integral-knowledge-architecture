<?php
/**
 * One-shot migration: grandfather active beta testers into Harmonia Membership
 * complimentary tier for 12 months.
 *
 * Usage:
 *   php migrate-beta-comp.php --dry-run    (show who would be marked, no writes)
 *   php migrate-beta-comp.php --apply      (actually write subscription_status=comp)
 *
 * Criteria for "active beta tester":
 *   - first_seen before 2026-04-30 (pre-Phase-0 beta cohort cutoff)
 *   - message_count >= 5 (genuine engagement, not a hit-and-run)
 *   - chat_consent = 1 (already consented under prior regime)
 *
 * Effect on each matched user:
 *   - subscription_status = 'comp'
 *   - subscription_plan = 'beta_comp'
 *   - subscription_started_at = now
 *   - comp_expires_at = now + 365 days (≈2027-05-22)
 *   - mirrored to website client via verified-email link if account is linked
 *
 * At month 11, an automated email surfaces a discount offer to convert at
 * $9/month founder pricing if any founder slots remain at that point.
 */

require_once __DIR__ . '/telegram-memory.php';
require_once dirname(__DIR__) . '/companion-memory.php';

$dryRun = !in_array('--apply', $argv, true);

$cutoff = strtotime('2026-04-30');  // Pre-Phase-0 beta cohort cutoff
$minMsgs = 5;
$compDuration = 365 * 86400;  // 1 year in seconds
$now = time();
$compExpiresAt = $now + $compDuration;

$tg = new CompanionMemory();
$web = new WebCompanionMemory();
$tgDb = $tg->getDb();
$webDb = $web->getDb();

echo "═══════════════════════════════════════════════════════════════\n";
echo "  Beta Tester Comp Migration — Harmonia Membership\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Mode:           " . ($dryRun ? 'DRY-RUN (no writes)' : 'APPLY') . "\n";
echo "  Cutoff:         first_seen < " . date('Y-m-d', $cutoff) . "\n";
echo "  Min messages:   {$minMsgs}\n";
echo "  Comp expires:   " . date('Y-m-d', $compExpiresAt) . "\n";
echo "\n";

// Find candidate Telegram users
$stmt = $tgDb->prepare('
    SELECT telegram_id, first_name, username, message_count, first_seen, chat_consent
    FROM users
    WHERE first_seen < ?
      AND message_count >= ?
      AND chat_consent = 1
      AND (subscription_status IS NULL OR subscription_status = "" OR subscription_status = "free")
    ORDER BY message_count DESC
');
$stmt->execute([$cutoff, $minMsgs]);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($candidates) . " Telegram beta tester candidates:\n\n";
echo sprintf("  %-5s  %-20s  %-15s  %-7s  %s\n", '', 'Name', 'Username', 'Msgs', 'First Seen');
echo "  " . str_repeat('─', 65) . "\n";

$tgMarked = 0;
$webMarked = 0;
$linkStmt = $tgDb->prepare('SELECT member_email FROM linked_accounts WHERE telegram_id = ? AND verified = 1');

foreach ($candidates as $row) {
    $tid = (int)$row['telegram_id'];
    $name = mb_substr($row['first_name'] ?? '', 0, 18);
    $uname = '@' . mb_substr($row['username'] ?? '', 0, 13);
    $count = (int)$row['message_count'];
    $seen = date('Y-m-d', (int)$row['first_seen']);
    echo sprintf("  %-5d  %-20s  %-15s  %-7d  %s", $tid, $name, $uname, $count, $seen);

    // Check linked email for cross-surface propagation
    $linkStmt->execute([$tid]);
    $email = $linkStmt->fetchColumn();
    if ($email) echo "  [linked: {$email}]";
    echo "\n";

    if (!$dryRun) {
        // Telegram side
        try {
            $upd = $tgDb->prepare('
                UPDATE users
                SET subscription_status = "comp",
                    subscription_plan = "beta_comp",
                    subscription_started_at = ?,
                    comp_expires_at = ?
                WHERE telegram_id = ?
            ');
            $upd->execute([$now, $compExpiresAt, $tid]);
            $tgMarked++;
        } catch (PDOException $e) {
            echo "    ERROR (telegram): " . $e->getMessage() . "\n";
        }

        // Website side via verified-email link
        if ($email) {
            try {
                $webStmt = $webDb->prepare('
                    UPDATE clients
                    SET subscription_status = "comp",
                        subscription_plan = "beta_comp",
                        subscription_started_at = ?,
                        comp_expires_at = ?
                    WHERE email = ?
                ');
                $webStmt->execute([$now, $compExpiresAt, $email]);
                $webMarked += $webStmt->rowCount();
            } catch (PDOException $e) {
                echo "    ERROR (website): " . $e->getMessage() . "\n";
            }
        }
    }
}

// Also include unlinked website clients with similar engagement (rare, but possible
// for early visitors who chatted with the site sprite without linking Telegram).
echo "\n";
echo "Checking website-only beta clients (unlinked, pre-cutoff, msg≥{$minMsgs}):\n";
$webStmt = $webDb->prepare('
    SELECT client_id, email, message_count, first_seen
    FROM clients
    WHERE first_seen < ?
      AND message_count >= ?
      AND chat_consent = 1
      AND email != ""
      AND (subscription_status IS NULL OR subscription_status = "" OR subscription_status = "free")
    ORDER BY message_count DESC
');
$webStmt->execute([$cutoff, $minMsgs]);
$webOnly = $webStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($webOnly as $row) {
    $cid = $row['client_id'];
    $email = $row['email'] ?? '';
    $count = (int)$row['message_count'];
    $seen = date('Y-m-d', (int)$row['first_seen']);

    // Skip if already covered by Telegram link
    $alreadyMarked = false;
    if ($email) {
        $check = $webDb->prepare('SELECT subscription_status FROM clients WHERE client_id = ?');
        $check->execute([$cid]);
        $r = $check->fetch();
        if ($r && $r['subscription_status'] === 'comp') {
            $alreadyMarked = true;
        }
    }
    if ($alreadyMarked) continue;

    echo sprintf("  %s  %-30s  msgs=%d  first_seen=%s\n",
        substr($cid, 0, 8), $email, $count, $seen);

    if (!$dryRun) {
        try {
            $upd = $webDb->prepare('
                UPDATE clients
                SET subscription_status = "comp",
                    subscription_plan = "beta_comp",
                    subscription_started_at = ?,
                    comp_expires_at = ?
                WHERE client_id = ?
            ');
            $upd->execute([$now, $compExpiresAt, $cid]);
            $webMarked++;
        } catch (PDOException $e) {
            echo "    ERROR: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  Summary\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Telegram users marked comp: {$tgMarked}\n";
echo "  Website clients marked comp: {$webMarked}\n";
echo "  Mode: " . ($dryRun ? 'DRY-RUN' : 'APPLIED') . "\n";
if ($dryRun) {
    echo "\n  Re-run with --apply to commit.\n";
}
echo "═══════════════════════════════════════════════════════════════\n";
