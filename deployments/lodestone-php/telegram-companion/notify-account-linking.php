<?php
/**
 * One-shot outreach: notify existing beta testers about the account linking requirement.
 *
 * Sends a personalized message from the Companion to all Telegram users with
 * first_seen before the gate launch date (April 16, 2026), who have not yet
 * linked an account, and who have at least 1 message.
 *
 * Run once from the server:
 *   php notify-account-linking.php
 *
 * Dry run (preview without sending):
 *   php notify-account-linking.php --dry-run
 *
 * Safety: each user is marked in a tracking table after receiving the notification,
 * so running the script twice is safe (no double-sends).
 */

set_time_limit(300);
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/companion-errors.log');

require_once __DIR__ . '/telegram-memory.php';

// Load config
$configPath = '/home/SERVER_USER/.telegram-config.php';
if (!file_exists($configPath)) {
    echo "Config not found at {$configPath}\n";
    exit(1);
}
require_once $configPath;

$dryRun = in_array('--dry-run', $argv ?? []);
$memory = new CompanionMemory(__DIR__ . '/companion-data.db');

// Ensure tracking table exists
$db = new PDO('sqlite:' . __DIR__ . '/companion-data.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('
    CREATE TABLE IF NOT EXISTS linking_notifications (
        telegram_id INTEGER PRIMARY KEY,
        sent_at INTEGER NOT NULL
    )
');

// Gate launch date — users with first_seen before this are beta testers
$gateLaunchDate = 1776297600; // April 16, 2026 00:00 UTC

// Find all eligible users:
// - first_seen before gate launch (existing beta testers)
// - at least 1 message (actually used the bot)
// - not already linked (verified)
// - not already notified
$stmt = $db->prepare('
    SELECT u.telegram_id, u.first_name, u.username, u.message_count
    FROM users u
    LEFT JOIN linked_accounts la ON u.telegram_id = la.telegram_id AND la.verified = 1
    LEFT JOIN linking_notifications ln ON u.telegram_id = ln.telegram_id
    WHERE u.first_seen < ?
      AND u.message_count > 0
      AND la.telegram_id IS NULL
      AND ln.telegram_id IS NULL
    ORDER BY u.last_seen DESC
');
$stmt->execute([$gateLaunchDate]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($users) . " beta testers to notify.\n";

if (count($users) === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

$sent = 0;
$failed = 0;

foreach ($users as $user) {
    $chatId = (int)$user['telegram_id'];
    $name = $user['first_name'] ?: 'friend';

    // Personalize based on message count
    $depth = (int)$user['message_count'];
    if ($depth >= 30) {
        $depthLine = "We have built something real in our conversations — {$depth} messages of genuine exploration.";
    } elseif ($depth >= 10) {
        $depthLine = "Over our {$depth} exchanges, I have begun to learn who you are and where you stand on the Wheel.";
    } else {
        $depthLine = "Even in our brief exchanges so far, the seeds of understanding have been planted.";
    }

    $message = "{$name},\n\n"
        . "Harmonia is evolving. I wanted to tell you personally before you encounter this in the flow of conversation.\n\n"
        . "{$depthLine} None of that is lost.\n\n"
        . "Starting May 1st, I will ask all Telegram practitioners to create a free Harmonia account and link it here. "
        . "This is not a paywall — the account is free, and it always will be. "
        . "The reason is simple: if you ever leave Telegram, I lose you. "
        . "An email address means I can always reach you — across platforms, across time.\n\n"
        . "It also means your journey with me becomes continuous across the website and Telegram. "
        . "Everything I have learned about you here will merge with the website Companion. One practitioner, one Wheel, one path — regardless of where you speak to me.\n\n"
        . "Here is what to do (it takes one minute):\n\n"
        . "1. Create your free account: https://harmonism.io/member/register.php\n"
        . "2. Come back here and type: /link your@email.com\n"
        . "3. Enter the verification code on your dashboard\n\n"
        . "You have until April 30th. After that, the link will be required to continue our conversations.\n\n"
        . "Thank you for being here from the beginning. That matters more than you know.";

    if ($dryRun) {
        echo "\n--- DRY RUN: @{$user['username']} ({$name}, {$depth} msgs) ---\n";
        echo $message . "\n";
    } else {
        // Send via Telegram API
        $result = sendTelegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $message,
            'disable_web_page_preview' => true,
        ]);

        if ($result && ($result['ok'] ?? false)) {
            // Mark as notified
            $db->prepare('INSERT OR IGNORE INTO linking_notifications (telegram_id, sent_at) VALUES (?, ?)')
                ->execute([$chatId, time()]);
            $sent++;
            echo "  Sent to @{$user['username']} ({$name})\n";
        } else {
            $failed++;
            $errDesc = $result['description'] ?? 'unknown error';
            echo "  FAILED: @{$user['username']} — {$errDesc}\n";
            error_log("[notify-linking] Failed to send to {$chatId}: {$errDesc}");
        }

        // Rate limit: Telegram allows ~30 messages/second, but be respectful
        usleep(200000); // 200ms between messages
    }
}

echo "\nDone. Sent: {$sent}, Failed: {$failed}\n";

// ── Telegram API helper (standalone, doesn't depend on the webhook handler) ──

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
        error_log("[notify-linking] cURL error: " . curl_error($ch));
        curl_close($ch);
        return null;
    }

    curl_close($ch);
    return json_decode($result, true);
}
