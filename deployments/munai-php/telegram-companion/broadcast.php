<?php
/**
 * Broadcast — Send a message to all Telegram Companion users.
 *
 * Usage:
 *   php broadcast.php --fr message-fr.txt --en message-en.txt
 *   php broadcast.php --dry-run --fr message-fr.txt --en message-en.txt
 *
 * Detects each user's language from their conversation history and sends
 * the appropriate version. Falls back to French if detection is uncertain
 * (most beta testers are francophone).
 *
 * Supports Telegram Markdown formatting (*bold*, _italic_).
 * 50ms delay between sends to respect Telegram rate limits.
 */

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/companion-errors.log');

$configPath = '/home/SERVER_USER/.telegram-config.php';
if (!file_exists($configPath)) {
    echo "ERROR: config not found at {$configPath}\n";
    exit(1);
}
require_once $configPath;

if (!defined('TELEGRAM_BOT_TOKEN')) {
    echo "ERROR: TELEGRAM_BOT_TOKEN not defined in config\n";
    exit(1);
}

// Parse arguments
$dryRun = false;
$frFile = null;
$enFile = null;

$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    switch ($args[$i]) {
        case '--dry-run': $dryRun = true; break;
        case '--fr': $frFile = $args[++$i] ?? null; break;
        case '--en': $enFile = $args[++$i] ?? null; break;
    }
}

if (!$frFile || !$enFile) {
    echo "Usage: php broadcast.php [--dry-run] --fr message-fr.txt --en message-en.txt\n";
    exit(1);
}

foreach (['fr' => $frFile, 'en' => $enFile] as $lang => $path) {
    if (!file_exists($path)) {
        echo "ERROR: {$lang} file not found: {$path}\n";
        exit(1);
    }
}

$messageFr = trim(file_get_contents($frFile));
$messageEn = trim(file_get_contents($enFile));

// Database
$dbPath = __DIR__ . '/companion-data.db';
if (!file_exists($dbPath)) {
    echo "ERROR: database not found at {$dbPath}\n";
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$users = $pdo->query('SELECT telegram_id, first_name, username FROM users ORDER BY first_name')
    ->fetchAll(PDO::FETCH_ASSOC);

echo "Broadcast to " . count($users) . " users" . ($dryRun ? " (DRY RUN)" : "") . "\n";
echo "FR message: " . strlen($messageFr) . " chars\n";
echo "EN message: " . strlen($messageEn) . " chars\n";
echo "---\n\n";

$sent = 0;
$failed = 0;
$langCounts = ['fr' => 0, 'en' => 0];

foreach ($users as $user) {
    $chatId = (int)$user['telegram_id'];
    $name = $user['first_name'] ?: $user['username'] ?: (string)$chatId;
    $lang = detectLanguage($pdo, $chatId);
    $message = ($lang === 'en') ? $messageEn : $messageFr;
    $langCounts[$lang]++;

    if ($dryRun) {
        echo "  [{$lang}] {$name} ({$chatId})\n";
        continue;
    }

    $result = sendTelegramMessage($chatId, $message);
    if ($result) {
        echo "  [{$lang}] Sent to {$name} ({$chatId})\n";
        $sent++;
    } else {
        echo "  [{$lang}] FAILED: {$name} ({$chatId})\n";
        $failed++;
    }

    usleep(50000); // 50ms between sends
}

echo "\nLanguage split: {$langCounts['fr']} FR, {$langCounts['en']} EN\n";
if ($dryRun) {
    echo "Dry run complete. No messages sent.\n";
} else {
    echo "Broadcast complete: {$sent} sent, {$failed} failed.\n";
}


/**
 * Detect user language from their last 20 messages.
 * Returns 'fr' or 'en'. Defaults to 'fr' (majority of beta testers).
 */
function detectLanguage(PDO $pdo, int $chatId): string {
    $stmt = $pdo->prepare('
        SELECT content FROM messages
        WHERE telegram_id = ? AND role = "user"
        ORDER BY timestamp DESC LIMIT 20
    ');
    $stmt->execute([$chatId]);
    $messages = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($messages)) {
        return 'fr'; // default
    }

    $text = mb_strtolower(implode(' ', $messages));

    // Common French function words (high-frequency, low ambiguity)
    $frWords = ['\\bje\\b', '\\btu\\b', '\\bles\\b', '\\bune\\b', '\\bque\\b',
                '\\bpas\\b', '\\bpour\\b', '\\bmon\\b', '\\bton\\b', '\\bson\\b',
                '\\bmes\\b', '\\btes\\b', '\\bses\\b', '\\bnous\\b', '\\bvous\\b',
                '\\bmerci\\b', '\\boui\\b', '\\bcomment\\b', '\\baussi\\b',
                '\\bbonjour\\b', '\\bsalut\\b', '\\best-ce\\b', '\\bj\'ai\\b',
                '\\bc\'est\\b', '\\bqu\'\\b', '\\bavec\\b', '\\bdans\\b',
                '\\bsuis\\b', '\\bfaire\\b', '\\bpeut\\b'];

    // Common English function words
    $enWords = ['\\bthe\\b', '\\band\\b', '\\bthat\\b', '\\bhave\\b', '\\bwith\\b',
                '\\bthis\\b', '\\bfrom\\b', '\\bwhat\\b', '\\byour\\b', '\\babout\\b',
                '\\bwould\\b', '\\bthere\\b', '\\btheir\\b', '\\bbeen\\b', '\\bwhich\\b',
                '\\bcould\\b', '\\bthem\\b', '\\bthey\\b', '\\bthese\\b', '\\bwhen\\b',
                '\\bhello\\b', '\\bthanks\\b', '\\bplease\\b', '\\bhow\\b',
                '\\bdon\'t\\b', '\\bcan\'t\\b', '\\bi\'m\\b', '\\bi\'ve\\b'];

    $frScore = 0;
    foreach ($frWords as $pattern) {
        $frScore += preg_match_all('/' . $pattern . '/u', $text);
    }

    $enScore = 0;
    foreach ($enWords as $pattern) {
        $enScore += preg_match_all('/' . $pattern . '/u', $text);
    }

    // Require a clear margin for English; default to French
    return ($enScore > $frScore * 1.5 && $enScore >= 5) ? 'en' : 'fr';
}


function sendTelegramMessage(int $chatId, string $text): bool {
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';

    $payload = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'Markdown',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("broadcast send failed for {$chatId}: HTTP {$httpCode} — {$result}");
        return false;
    }

    return true;
}
