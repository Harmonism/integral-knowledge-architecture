<?php
/**
 * The Wisdom Canon — Telegram surface.
 *
 * Command handlers and inline-keyboard callbacks for the reading track.
 * Kept out of telegram-bot.php so the edit to that file is three insertion
 * points rather than six hundred lines.
 *
 * Depends on companion-reading.php (the engine) and on two helpers that live
 * in telegram-bot.php and are in scope when this file is required from there:
 *   sendTelegramMessage(int $chatId, string $text): void
 *   sendTelegramRequest(string $method, array $params): ?array
 *
 * Commands are handled before the rate-limit gate in telegram-bot.php, so the
 * covenant, the stations and the margin are free at every tier by
 * construction. Only open-ended dialogue meets the caps (#771).
 *
 * PHP 8.0 target.
 */

require_once __DIR__ . '/companion-reading.php';

// Callback codes. Short because Telegram caps callback_data at 64 bytes.
if (!defined('READING_TG_PACE_CODES')) {
    define('READING_TG_PACE_CODES', [
        'd' => 'daily', 'x' => 'double', 'a' => 'alternate',
        '2' => 'twice-week', 'w' => 'weekly',
    ]);
}
if (!defined('READING_TG_CADENCE_CODES')) {
    define('READING_TG_CADENCE_CODES', [
        'e' => 'each_sitting', 't' => 'twice_weekly', 'w' => 'weekly', 's' => 'silent',
    ]);
}


// ==========================================================================
// COMMANDS
// ==========================================================================

/**
 * Entry point for /read, /arc, /done and /margin.
 * Returns true when the command was handled here.
 */
if (!function_exists('readingHandleTelegramCommand')) {
function readingHandleTelegramCommand(int $chatId, string $text, $memory): bool {
    $parts = preg_split('/\s+/', trim($text), 2);
    $cmd   = strtolower($parts[0]);
    $arg   = isset($parts[1]) ? trim($parts[1]) : '';

    $db = $memory->getDb();
    readingEnsureSchema($db);
    $subject = (string)$chatId;

    switch ($cmd) {
        case '/read':
            readingTgRead($chatId, $arg, $db, $memory, $subject);
            return true;
        case '/arc':
            readingTgArc($chatId, $arg, $db, $subject);
            return true;
        case '/done':
            readingTgDone($chatId, $arg, $db, $subject);
            return true;
        case '/margin':
            readingTgMargin($chatId, $arg, $db, $subject);
            return true;
    }
    return false;
}
}


/** /read — the canon, or the covenant, or the current station. */
if (!function_exists('readingTgRead')) {
function readingTgRead(int $chatId, string $arg, PDO $db, $memory, string $subject): void {
    $arg = trim($arg);
    $arc = readingActiveArc($db, 'telegram', $subject);

    if (strtolower($arg) === 'stop' || strtolower($arg) === 'end') {
        if (!$arc) { sendTelegramMessage($chatId, "You have no reading arc running."); return; }
        readingCloseArc($db, (int)$arc['id'], 'released');
        $n = readingNoteCounts($db, (int)$arc['id']);
        sendTelegramMessage($chatId,
            "Closed — {$arc['title']}.\n\n"
            . "Your margin stays: {$n['total']} notes. Nothing further is scheduled.\n"
            . "/read to begin another when you want one."
        );
        return;
    }

    if ($arg === '' && $arc) { readingTgStation($chatId, $db, $arc); return; }

    if ($arg === '') { readingTgCanonMenu($chatId); return; }

    // A canon key, or a free-text title for a self-supplied book.
    $key  = readingTgSlug($arg);
    $work = readingResolveWork($key);

    if (!$work) {
        $match = readingTgFuzzy($arg);
        if ($match) { $key = $match['key']; $work = $match; }
    }

    if (!$work) {
        sendTelegramMessage($chatId,
            "I don't have \"{$arg}\" in the canon.\n\n"
            . "Send /read on its own to see what is there. If you want to walk a book "
            . "outside it, that is coming — for now the arc needs a table of contents "
            . "I can be accurate about, and I would rather say so than invent one."
        );
        return;
    }

    readingTgPaceMenu($chatId, $work);
}
}


/** The canon menu. Threshold in order, then the deeper tracks. */
if (!function_exists('readingTgCanonMenu')) {
function readingTgCanonMenu(int $chatId): void {
    $threshold = readingTrack('threshold');

    $lines = [];
    $lines[] = 'THE WISDOM CANON';
    $lines[] = '';
    $lines[] = 'Pick a text. I hold the arc, ask after each sitting, and then stop asking — '
             . 'contact tapers across the read and the last week is silent.';
    $lines[] = '';
    $lines[] = 'The Threshold — nine books, in order:';
    foreach ($threshold as $i => $w) {
        $n = $i + 1;
        $lines[] = "  {$n}. {$w['title']} — {$w['author']}";
    }
    $lines[] = '';
    $lines[] = 'Also available: Shaman, Healer, Sage · The Way of a Pilgrim · Zhuangzi · '
             . 'Ashtavakra Gita · Yoga Sutras · The Republic · The Interior Castle.';
    $lines[] = '';
    $lines[] = 'Send /read followed by a title — /read dhammapada — or tap one below.';

    $kb = [];
    $row = [];
    foreach (array_slice($threshold, 0, 9) as $w) {
        $row[] = ['text' => readingTgShortTitle($w['title']), 'callback_data' => 'read:p:' . $w['key']];
        if (count($row) === 2) { $kb[] = $row; $row = []; }
    }
    if ($row) $kb[] = $row;

    sendTelegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => implode("\n", $lines),
        'reply_markup' => json_encode(['inline_keyboard' => $kb]),
    ]);
}
}


/** Step 1 of the covenant: pace. */
if (!function_exists('readingTgPaceMenu')) {
function readingTgPaceMenu(int $chatId, array $work): void {
    $units  = (int)$work['units'];
    $label  = (string)$work['unit_label'];
    $key    = (string)$work['key'];
    $presets = readingPacePresets();

    $lines = [];
    $lines[] = $work['title'] . ' — ' . $work['author'];
    $lines[] = '';
    $lines[] = "{$units} {$label}.";
    if (!empty($work['note'])) $lines[] = $work['note'];
    if (empty($work['titles'])) {
        $lines[] = '';
        $lines[] = "I don't hold a verified table of contents for this one, so stations will be "
                 . "numbered rather than named. If your edition divides it differently, "
                 . "/arc units N corrects the count.";
    }
    if (!empty($work['translations'])) {
        $lines[] = '';
        $lines[] = 'Translations worth knowing about: ' . implode(' · ', $work['translations']);
    }
    $lines[] = '';
    $lines[] = 'At what pace?';

    $kb = [];
    foreach ($presets as $presetKey => $p) {
        $short = array_search($presetKey, READING_TG_PACE_CODES, true);
        if ($short === false) continue;
        $days = readingArcDays($units, $p['num'], $p['days']);
        $kb[] = [[
            'text' => ucfirst($p['label']) . " — {$days} days",
            'callback_data' => 'read:c:' . $key . ':' . $short,
        ]];
    }

    sendTelegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => implode("\n", $lines),
        'reply_markup' => json_encode(['inline_keyboard' => $kb]),
    ]);
}
}


/** Step 2 of the covenant: cadence, shown with its own taper. */
if (!function_exists('readingTgCadenceMenu')) {
function readingTgCadenceMenu(int $chatId, array $work, string $paceCode): void {
    $presets = readingPacePresets();
    $paceKey = READING_TG_PACE_CODES[$paceCode] ?? 'daily';
    $p = $presets[$paceKey] ?? $presets['daily'];
    $days  = readingArcDays((int)$work['units'], $p['num'], $p['days']);
    $weeks = (int)max(1, (int)ceil($days / 7));

    $lines = [];
    $lines[] = $work['title'] . ' — ' . $p['label'] . ', ' . $days . ' days.';
    $lines[] = '';
    $lines[] = 'How often should I ask?';
    $lines[] = '';
    $lines[] = 'Whatever you choose, it tapers. It does not hold steady and it never escalates.';

    $kb = [];
    foreach (['e' => 'After each sitting', 't' => 'Twice a week', 'w' => 'Once a week', 's' => "Don't ask"] as $code => $label) {
        $series = readingTaperSeries($weeks, READING_TG_CADENCE_CODES[$code]);
        $total  = array_sum($series) + 1; // + the close
        $shape  = $code === 's' ? 'the close only' : implode('·', $series) . " per week";
        $kb[] = [[
            'text' => $label . ' — ' . $total . ' message' . ($total === 1 ? '' : 's'),
            'callback_data' => 'read:g:' . $work['key'] . ':' . $paceCode . ':' . $code,
        ]];
        $lines[] = '  ' . $label . ': ' . $shape;
    }

    sendTelegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => implode("\n", $lines),
        'reply_markup' => json_encode(['inline_keyboard' => $kb]),
    ]);
}
}


/** Step 3: create the arc. */
if (!function_exists('readingTgCreate')) {
function readingTgCreate(int $chatId, PDO $db, $memory, string $subject,
                         string $workKey, string $paceCode, string $cadenceCode): void {
    $work = readingResolveWork($workKey);
    if (!$work) { sendTelegramMessage($chatId, "That text is no longer available."); return; }

    $presets = readingPacePresets();
    $paceKey = READING_TG_PACE_CODES[$paceCode] ?? 'daily';
    $p = $presets[$paceKey] ?? $presets['daily'];
    $cadence = READING_TG_CADENCE_CODES[$cadenceCode] ?? 'each_sitting';

    $tz = '';
    if (method_exists($memory, 'getTimezone')) $tz = (string)$memory->getTimezone($chatId);
    if ($tz === '' || !readingTzValid($tz)) $tz = 'UTC';

    $arcId = readingCreateArc($db, 'telegram', $subject, [
        'work_key'     => $workKey,
        'pace_num'     => $p['num'],
        'pace_days'    => $p['days'],
        'sitting_hour' => -1,
        'timezone'     => $tz,
        'cadence'      => $cadence,
        'surface'      => 'telegram',
        'discuss'      => $cadence === 'silent' ? 'on_demand' : 'yes',
    ]);

    $arc  = readingGetArc($db, $arcId);
    $rem  = readingTaperRemaining($db, $arcId);
    $st   = readingStation($arc, 1);

    $lines = [];
    $lines[] = 'The arc is set.';
    $lines[] = '';
    $lines[] = $arc['title'] . ' — ' . $p['label'];
    $lines[] = readingFormatDate((string)$arc['started_on']) . ' → '
             . readingFormatDate((string)$arc['projected_end'])
             . ' · ' . readingArcDays((int)$arc['units'], (int)$arc['pace_num'], (int)$arc['pace_days']) . ' days';
    $lines[] = $rem['pending'] . ' messages from me across the whole read, tapering to none.';
    if ($tz === 'UTC') {
        $lines[] = '';
        $lines[] = 'I have you in UTC. /timezone sets your own, so the day rolls over when yours does.';
    }
    $lines[] = '';
    $lines[] = 'First station: ' . $st['heading'] . ($st['range'] !== '' ? ' · verses ' . $st['range'] : '');
    $lines[] = '';
    $lines[] = '/done when you have read it. /margin to keep a note. /arc for where you stand.';
    $lines[] = '/read stop closes it at any point, and closing costs nothing.';

    sendTelegramMessage($chatId, implode("\n", $lines));
}
}


/** The current station. Reference data and a refusal — never a reading. */
if (!function_exists('readingTgStation')) {
function readingTgStation(int $chatId, PDO $db, array $arc): void {
    $prog = readingProgress($db, $arc);
    $st   = readingStation($arc, (int)$prog['current']);
    readingOpenStation($db, (int)$arc['id'], (int)$prog['current']);

    $lines = [];
    $lines[] = $arc['title'] . ' · day ' . $prog['day_index'] . ' of ' . $prog['total_days'];
    $lines[] = '';
    $lines[] = $st['heading'];
    if ($st['native'] !== '') $lines[] = $st['native'] . ($st['range'] !== '' ? ' · verses ' . $st['range'] : '');
    elseif ($st['range'] !== '') $lines[] = 'verses ' . $st['range'];
    $lines[] = '';
    $lines[] = $prog['completed'] . ' of ' . $prog['units'] . ' read.';
    if ((int)$prog['behind'] > 0) {
        $lines[] = 'The pace you set puts you at ' . $prog['due_unit'] . '. That is information, not a verdict — '
                 . '/arc pace changes it if the pace was wrong.';
    }
    $lines[] = '';
    $lines[] = 'Read it, then /done. If you want a question to carry in with you, ask me for one.';

    sendTelegramMessage($chatId, implode("\n", $lines));
}
}


/** /done — mark the station read. */
if (!function_exists('readingTgDone')) {
function readingTgDone(int $chatId, string $arg, PDO $db, string $subject): void {
    $arc = readingActiveArc($db, 'telegram', $subject);
    if (!$arc) { sendTelegramMessage($chatId, "No arc running. /read to begin one."); return; }

    $prog = readingProgress($db, $arc);
    $unit = $arg !== '' && ctype_digit($arg) ? (int)$arg : (int)$prog['current'];

    $prog = readingMarkRead($db, $arc, $unit);
    $st   = readingStation($arc, $unit);

    if ($prog['finished']) {
        $n = readingNoteCounts($db, (int)$arc['id']);
        sendTelegramMessage($chatId,
            "{$st['heading']} — read. That is the whole of {$arc['title']}.\n\n"
            . "{$n['total']} notes in your margin. I'll close the arc properly shortly; "
            . "there is one more thing I want to ask you, and it is better asked after the book has settled."
        );
        return;
    }

    $next = readingStation($arc, (int)$prog['current']);
    $tail = $next['range'] !== '' ? ' · verses ' . $next['range'] : '';
    sendTelegramMessage($chatId,
        "{$st['heading']} — read. {$prog['completed']} of {$prog['units']}.\n\n"
        . "Next: {$next['heading']}{$tail}\n\n"
        . "/margin to keep anything from this one before it goes."
    );
}
}


/**
 * /margin — the seam-stamped note.
 *
 * The default stamp is `mine`, because the practitioner's own words are the
 * safe attribution: nothing is ever silently stamped as the text's claim or as
 * Harmonism's. An explicit prefix sets the other two.
 */
if (!function_exists('readingTgMargin')) {
function readingTgMargin(int $chatId, string $arg, PDO $db, string $subject): void {
    $arc = readingActiveArc($db, 'telegram', $subject);
    if (!$arc) { sendTelegramMessage($chatId, "No arc running. /read to begin one."); return; }

    $arcId = (int)$arc['id'];

    if ($arg === '') {
        $notes = readingNotes($db, $arcId, 12);
        $c = readingNoteCounts($db, $arcId);
        if (!$notes) {
            sendTelegramMessage($chatId,
                "Your margin is empty.\n\n"
                . "/margin followed by whatever you want to keep. Three stamps:\n"
                . "  /margin text: <a line from the book>\n"
                . "  /margin harmonism: <where the corpus stands nearby>\n"
                . "  /margin <your own thought>  — the default\n\n"
                . "The stamps never merge. Six weeks in, that is the difference between "
                . "knowing where a conviction came from and not."
            );
            return;
        }
        $lines = ["Margin — {$arc['title']} · {$c['total']} notes "
                  . "({$c['text']} text, {$c['harmonism']} harmonism, {$c['mine']} yours)", ''];
        foreach ($notes as $n) {
            $stamp = strtoupper((string)$n['seam']);
            $u = (int)$n['unit_index'];
            $cite = trim((string)$n['cite']);
            $lines[] = "[{$stamp} · " . ($u > 0 ? "unit {$u}" : 'arc') . '] ' . $n['body']
                     . ($cite !== '' ? "\n    — {$cite}" : '');
        }
        sendTelegramMessage($chatId, implode("\n", $lines));
        return;
    }

    $seam = 'mine';
    $body = $arg;
    if (preg_match('/^(text|harmonism|mine)\s*:\s*(.+)$/is', $arg, $m)) {
        $seam = strtolower($m[1]);
        $body = trim($m[2]);
    }

    $prog = readingProgress($db, $arc);
    $id = readingAddNote($db, $arcId, (int)$prog['current'], $seam, $body);
    if ($id === 0) { sendTelegramMessage($chatId, "Nothing to keep — send some text with it."); return; }

    $st = readingStation($arc, (int)$prog['current']);
    $stamp = strtoupper($seam);
    $hint = $seam === 'mine'
        ? "Stamped yours. If that was the book's line rather than your thought, send it again as /margin text: …"
        : "Stamped {$seam}.";

    sendTelegramMessage($chatId, "[{$stamp} · {$st['heading']}] kept.\n{$hint}");
}
}


/** /arc — where you stand, and the two things you are allowed to change. */
if (!function_exists('readingTgArc')) {
function readingTgArc(int $chatId, string $arg, PDO $db, string $subject): void {
    $arc = readingActiveArc($db, 'telegram', $subject);
    if (!$arc) {
        sendTelegramMessage($chatId, "No arc running. /read to see the canon.");
        return;
    }
    $arcId = (int)$arc['id'];

    // /arc units N — correct the count when an edition divides differently.
    if (preg_match('/^units\s+(\d{1,3})$/i', trim($arg), $m)) {
        $n = max(1, min(400, (int)$m[1]));
        $days = readingArcDays($n, (int)$arc['pace_num'], (int)$arc['pace_days']);
        $end  = readingAddDays((string)$arc['started_on'], $days - 1);
        $db->prepare('UPDATE reading_arcs SET units = ?, projected_end = ?, updated_at = ? WHERE id = ?')
           ->execute([$n, $end, time(), $arcId]);
        sendTelegramMessage($chatId,
            "{$arc['title']} is now {$n} {$arc['unit_label']} — {$days} days, ending "
            . readingFormatDate($end) . ".\n\n"
            . "The taper is unchanged: it was fixed when the arc began and I do not add contact to a running arc."
        );
        return;
    }

    // /arc pace <preset> — slowing down is ordinary. It never adds contact.
    if (preg_match('/^pace\s+(\S+)$/i', trim($arg), $m)) {
        $presets = readingPacePresets();
        $wanted = strtolower($m[1]);
        if (!isset($presets[$wanted])) {
            sendTelegramMessage($chatId, "Pace options: " . implode(', ', array_keys($presets)));
            return;
        }
        $p = $presets[$wanted];
        $days = readingArcDays((int)$arc['units'], $p['num'], $p['days']);
        $end  = readingAddDays((string)$arc['started_on'], $days - 1);
        $db->prepare('UPDATE reading_arcs SET pace_num = ?, pace_days = ?, projected_end = ?, updated_at = ?
                      WHERE id = ?')->execute([$p['num'], $p['days'], $end, time(), $arcId]);
        sendTelegramMessage($chatId,
            "Pace is now {$p['label']} — {$days} days, ending " . readingFormatDate($end) . ".\n\n"
            . "Nothing was added to the taper. Changing the pace is the ordinary thing to do; "
            . "it is the plan that was wrong, not you."
        );
        return;
    }

    $prog = readingProgress($db, $arc);
    $rem  = readingTaperRemaining($db, $arcId);
    $c    = readingNoteCounts($db, $arcId);
    $st   = readingStation($arc, (int)$prog['current']);

    $lines = [];
    $lines[] = $arc['title'];
    $lines[] = readingFormatDate((string)$arc['started_on']) . ' → '
             . readingFormatDate((string)$arc['projected_end']);
    $lines[] = '';
    $lines[] = 'Day ' . $prog['day_index'] . ' of ' . $prog['total_days']
             . ' · ' . $prog['completed'] . ' of ' . $prog['units'] . ' read';
    $lines[] = 'Now: ' . $st['heading'];
    $lines[] = 'Margin: ' . $c['total'] . " notes ({$c['text']} text, {$c['harmonism']} harmonism, {$c['mine']} yours)";
    $lines[] = 'Contact: ' . $rem['sent'] . ' sent, ' . $rem['pending'] . ' left, then silence';
    $lines[] = '';
    $lines[] = '/arc pace daily|alternate|twice-week|weekly — change the pace';
    $lines[] = '/arc units N — correct the chapter count for your edition';
    $lines[] = '/read stop — close the arc';

    sendTelegramMessage($chatId, implode("\n", $lines));
}
}


// ==========================================================================
// CALLBACKS
// ==========================================================================

/**
 * Handle a `read:` inline-keyboard callback. Returns true when handled.
 * telegram-bot.php answers the callback query itself.
 */
if (!function_exists('readingHandleTelegramCallback')) {
function readingHandleTelegramCallback(int $chatId, string $data, $memory): bool {
    if (strncmp($data, 'read:', 5) !== 0) return false;

    $db = $memory->getDb();
    readingEnsureSchema($db);
    $subject = (string)$chatId;
    $p = explode(':', $data);

    $step = $p[1] ?? '';
    if ($step === 'p' && isset($p[2])) {
        $work = readingResolveWork($p[2]);
        if ($work) readingTgPaceMenu($chatId, $work);
        return true;
    }
    if ($step === 'c' && isset($p[2], $p[3])) {
        $work = readingResolveWork($p[2]);
        if ($work) readingTgCadenceMenu($chatId, $work, $p[3]);
        return true;
    }
    if ($step === 'g' && isset($p[2], $p[3], $p[4])) {
        readingTgCreate($chatId, $db, $memory, $subject, $p[2], $p[3], $p[4]);
        return true;
    }
    return true; // unknown read: callback — swallow rather than fall through
}
}


// ==========================================================================
// HELPERS
// ==========================================================================

if (!function_exists('readingTgSlug')) {
function readingTgSlug(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string)$s, '-');
}
}

/** Loose title match, so "/read the gita" and "/read dhammapada" both land. */
if (!function_exists('readingTgFuzzy')) {
function readingTgFuzzy(string $q): ?array {
    $needle = readingTgSlug($q);
    if ($needle === '') return null;
    $best = null;
    foreach (readingCanon() as $key => $w) {
        $hay = readingTgSlug($w['title']);
        if ($hay === $needle || strpos($hay, $needle) !== false || strpos($key, $needle) !== false) {
            $w['key'] = $key;
            if ($best === null || strlen($hay) < strlen(readingTgSlug($best['title']))) $best = $w;
        }
    }
    return $best;
}
}

/** Button labels have to fit; Telegram truncates silently otherwise. */
if (!function_exists('readingTgShortTitle')) {
function readingTgShortTitle(string $t): string {
    $map = [
        'The Miracle of Mindfulness' => 'Miracle of Mindfulness',
        'The Essential Rumi'         => 'Rumi',
        'Bhagavad Gita'              => 'Bhagavad Gita',
    ];
    return $map[$t] ?? $t;
}
}

/** The /help lines for the reading track, spliced into the existing menu. */
if (!function_exists('readingTgHelpLines')) {
function readingTgHelpLines(): string {
    return "/read — walk a book from the canon with me\n"
         . "/done — mark the chapter read\n"
         . "/margin — keep a note, stamped text / harmonism / yours\n"
         . "/arc — where you stand in the reading\n";
}
}
