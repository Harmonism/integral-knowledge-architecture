<?php
/**
 * migrate-telegram-to-held.php — carry a person's Lodestone-on-Telegram memory
 * into their own Lodestone memory on harmonism.io / Lodestone Desktop, ahead of
 * the Telegram companion closing.
 *
 * It writes into website-companion-data.db, on the member row that
 * member/memory-export.php hands to their browser on their next signed-in
 * visit. Their browser then takes it into the memory they hold (encrypted,
 * synced between their devices) and asks the server to delete its copy. So
 * what this script writes lives on the server only until that visit.
 *
 * What it carries, for the person's main website row:
 *   - every Telegram message, into their "On harmonism.io" thread, marked
 *     page_title "Lodestone on Telegram"
 *   - a synthesis of what Lodestone knows about them: profile summary,
 *     situation capsule, profile notes, Wheel profile, conversation timeline
 *
 * Where the synthesis comes from:
 *   --synthesis=FILE   a JSON file prepared beforehand (see SPEC below)
 *   --synthesize       written here, on the server, by Lodestone's own model
 *                      from the person's Telegram messages and memory. Nothing
 *                      of what it reads or writes is printed: no person reads
 *                      it on the way. If the model fails, the script stops and
 *                      writes nothing; --carry-fallback instead carries
 *                      Telegram's existing distilled memory unchanged.
 *
 * Command line only, run on the server from public_html/telegram-companion/:
 *
 *   php migrate-telegram-to-held.php --tg=ID --email=EMAIL --synthesis=FILE   # dry run: counts only
 *   php migrate-telegram-to-held.php --tg=ID --email=EMAIL --synthesize --apply
 *   php migrate-telegram-to-held.php --tg=ID --email=EMAIL --status           # has their browser taken it yet?
 *   php migrate-telegram-to-held.php --tg=ID --email=EMAIL --purge-telegram --apply
 *                                     # deletes their rows from companion-data.db;
 *                                     # refuses until the browser has taken the memory
 *                                     # (held_migrated_at set), unless --force
 *
 * Output is counts and lengths only, never content.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$opt = ['tg' => 0, 'email' => '', 'synthesis' => '', 'synthesize' => false, 'carry-fallback' => false,
        'apply' => false, 'status' => false, 'purge-telegram' => false, 'force' => false, 'again' => false, 'create-row' => false,   // create-row: accepted for older commands; --apply makes the row anyway
        'tg-db' => __DIR__ . '/companion-data.db', 'web-db' => __DIR__ . '/../website-companion-data.db', 'config' => ''];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m) || !array_key_exists($m[1], $opt)) { fwrite(STDERR, "unknown argument: $a\n"); exit(2); }
    $opt[$m[1]] = isset($m[2]) ? $m[2] : true;
}
$tg = (int)$opt['tg']; $email = strtolower(trim((string)$opt['email']));
if (!$tg || !filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "usage: --tg=ID --email=EMAIL (see header)\n"); exit(2); }
foreach (['tg-db', 'web-db'] as $k) if (!is_file($opt[$k])) { fwrite(STDERR, "not found: {$opt[$k]}\n"); exit(2); }

$tdb = new PDO('sqlite:' . $opt['tg-db']); $tdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$wdb = new PDO('sqlite:' . $opt['web-db']); $wdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tdb->exec('PRAGMA busy_timeout = 10000'); $wdb->exec('PRAGMA busy_timeout = 10000');
try { $wdb->exec('ALTER TABLE clients ADD COLUMN held_migrated_at INTEGER DEFAULT 0'); } catch (PDOException $e) {}
// When this carry was written: the member's browser lets a newer carry stand over older memory (sync.js mergeState).
try { $wdb->exec('ALTER TABLE clients ADD COLUMN carried_at INTEGER DEFAULT 0'); } catch (PDOException $e) {}

$one = function (PDO $db, string $sql, array $p = []) { $q = $db->prepare($sql); $q->execute($p); return $q->fetch(PDO::FETCH_ASSOC) ?: null; };
$all = function (PDO $db, string $sql, array $p = []) { $q = $db->prepare($sql); $q->execute($p); return $q->fetchAll(PDO::FETCH_ASSOC); };
$say = function (string $s) { fwrite(STDOUT, $s . "\n"); };

$user = $one($tdb, 'SELECT * FROM users WHERE telegram_id = ?', [$tg]);
if (!$user) { fwrite(STDERR, "no Telegram user $tg\n"); exit(1); }
$link = $one($tdb, 'SELECT member_email, verified FROM linked_accounts WHERE telegram_id = ?', [$tg]);
if ($link && strtolower($link['member_email']) !== $email) { fwrite(STDERR, "Telegram $tg is linked to a different email; refusing.\n"); exit(1); }
// Same order memory-export.php uses: its first row is the one whose memory columns travel.
$rows = $all($wdb, 'SELECT client_id, message_count, held_migrated_at FROM clients WHERE lower(email) = ? ORDER BY message_count DESC', [$email]);
if (!$rows) {
    // Signed in only in the Lodestone app: no website row yet. One is made for
    // the carried memory (with --apply), the row memory-export.php reads by email.
    if (!$opt['apply'] || $opt['status'] || $opt['purge-telegram']) { fwrite(STDERR, "no website account row for $email yet; --apply makes one (tg-$tg).\n"); if ($opt['status'] || $opt['purge-telegram']) exit(1); }
    else {
        $wdb->prepare("INSERT OR IGNORE INTO clients (client_id, first_seen, last_seen, message_count, source, email) VALUES (?, ?, ?, 0, 'telegram', ?)")->execute(["tg-$tg", (int)$user['first_seen'] ?: time(), time(), $email]);
        fwrite(STDOUT, "made website row tg-$tg for $email\n");
    }
    $rows = $all($wdb, 'SELECT client_id, message_count, held_migrated_at FROM clients WHERE lower(email) = ? ORDER BY message_count DESC', [$email]);
    if (!$rows) $rows = [['client_id' => "tg-$tg", 'message_count' => 0, 'held_migrated_at' => 0]];
}
$cid = $rows[0]['client_id'];
$taken = max(array_map(function ($r) { return (int)$r['held_migrated_at']; }, $rows));

// ── status ──
if ($opt['status']) {
    $say("telegram $tg  →  website row $cid");
    $say('browser has taken memory: ' . ($taken ? 'yes, ' . date('Y-m-d H:i', $taken) : 'not yet'));
    $say('telegram messages still on server: ' . $one($tdb, 'SELECT COUNT(*) n FROM messages WHERE telegram_id = ?', [$tg])['n']);
    $say('carried messages waiting on website row: ' . $one($wdb, "SELECT COUNT(*) n FROM messages WHERE client_id = ? AND page_title = 'Lodestone on Telegram'", [$cid])['n']);
    exit(0);
}

// ── purge the Telegram side ──
if ($opt['purge-telegram']) {
    if (!$taken && !$opt['force']) { fwrite(STDERR, "their browser has not taken the memory yet; refusing (use --force to delete anyway).\n"); exit(1); }
    $tables = ['messages', 'profile_notes', 'wheel_profile', 'conversation_summaries', 'outreach_events', 'crisis_events', 'share_codes', 'usage_log'];
    $have = array_column($tdb->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $plan = [];
    foreach ($tables as $t) if (in_array($t, $have, true)) $plan[$t] = (int)$one($tdb, "SELECT COUNT(*) n FROM $t WHERE " . ($t === 'usage_log' ? 'chat_id' : 'telegram_id') . ' = ?', [$tg])['n'];
    $arcs = in_array('reading_arcs', $have, true) ? array_column($all($tdb, "SELECT id FROM reading_arcs WHERE subject_id = ?", [(string)$tg]), 'id') : [];
    $plan['reading_arcs'] = count($arcs);
    $plan['response_queue'] = in_array('response_queue', $have, true) ? (int)$one($tdb, 'SELECT COUNT(*) n FROM response_queue WHERE chat_id = ?', [$tg])['n'] : 0;
    foreach ($plan as $k => $n) $say(sprintf('  %-24s %d', $k, $n));
    if (!$opt['apply']) { $say("\nDry run. Add --apply to delete."); exit(0); }
    $tdb->beginTransaction();
    foreach ($tables as $t) if (isset($plan[$t])) { $q = $tdb->prepare("DELETE FROM $t WHERE " . ($t === 'usage_log' ? 'chat_id' : 'telegram_id') . ' = ?'); $q->execute([$tg]); }
    if ($plan['response_queue']) { $q = $tdb->prepare('DELETE FROM response_queue WHERE chat_id = ?'); $q->execute([$tg]); }
    if ($arcs) {
        $in = implode(',', array_map('intval', $arcs));
        foreach (['reading_progress', 'reading_notes', 'reading_checkins'] as $t) if (in_array($t, $have, true)) $tdb->exec("DELETE FROM $t WHERE arc_id IN ($in)");
        $tdb->exec("DELETE FROM reading_arcs WHERE id IN ($in)");
    }
    // Keep the users row (membership, link) but clear every memory column.
    $cols = array_column($tdb->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $clear = array_values(array_intersect(['profile_summary', 'wheel_position', 'notes', 'situation_capsule', 'conversation_state', 'dominant_emotion', 'first_name', 'username'], $cols));
    $q = $tdb->prepare('UPDATE users SET ' . implode(', ', array_map(function ($c) { return "$c = ''"; }, $clear)) . ', message_count = 0 WHERE telegram_id = ?'); $q->execute([$tg]);
    $tdb->commit();
    $say("\nDeleted. Run VACUUM on companion-data.db once every purge is done, so deleted rows don't stay in free pages:");
    $say("  php -r '\$d=new PDO(\"sqlite:companion-data.db\");\$d->exec(\"VACUUM\");'");
    exit(0);
}

// ── migrate ──
// --again: a second carry to a member whose browser already took an earlier one. Lodestone app v66
// and later asks again every ten minutes once a move is confirmed, so it arrives the same way.
if ($taken && !$opt['again']) { fwrite(STDERR, "this member's browser already took their server memory on " . date('Y-m-d', $taken) . ". Lodestone app v66+ asks again: re-run with --again to carry it anyway.\n"); exit(1); }
if (!$opt['synthesis'] && !$opt['synthesize']) { fwrite(STDERR, "give --synthesis=FILE or --synthesize\n"); exit(2); }

const PILLARS = ['health', 'presence', 'relationships', 'matter', 'service', 'learning', 'nature', 'recreation'];
const LEVELS = ['unknown', 'absent', 'nascent', 'developing', 'functional', 'sophisticated', 'sovereign'];

$msgs = $all($tdb, 'SELECT role, content, timestamp, core_version, model, prompt_hash FROM messages WHERE telegram_id = ? ORDER BY timestamp, id', [$tg]);

if ($opt['synthesis']) {
    $syn = json_decode((string)@file_get_contents($opt['synthesis']), true);
    if (!is_array($syn)) { fwrite(STDERR, "cannot read JSON from {$opt['synthesis']}\n"); exit(1); }
} else {
    $syn = synthesize($tg, $user, $msgs, $tdb, $all, $opt);
}
$err = validate($syn);
if ($err) { fwrite(STDERR, "synthesis rejected: $err\n"); exit(1); }

// What would be written
$have = [];
foreach ($all($wdb, 'SELECT timestamp, role FROM messages WHERE client_id = ?', [$cid]) as $r) $have[$r['timestamp'] . '-' . $r['role']] = true;
$new = array_values(array_filter($msgs, function ($m) use ($have) { return !isset($have[$m['timestamp'] . '-' . $m['role']]); }));
if ($taken) $say('  (again: their browser took an earlier move on ' . date('Y-m-d H:i', $taken) . '; it takes this one at its next opening and keeps each message once)');
$say("telegram $tg  →  website row $cid ($email)");
$say(sprintf('  messages to carry          %d of %d (%d already there)', count($new), count($msgs), count($msgs) - count($new)));
$say(sprintf('  profile summary            %d chars', mb_strlen($syn['profile_summary'])));
$say(sprintf('  situation capsule          %d chars', mb_strlen($syn['situation_capsule'] ?? '')));
$say(sprintf('  profile notes              %d', count($syn['profile_notes'])));
$say(sprintf('  wheel pillars              %d', count($syn['wheel'])));
$say(sprintf('  conversation summaries     %d', count($syn['conversation_summaries'])));
$say(sprintf('  first message              %s', $msgs ? date('Y-m-d', (int)$msgs[0]['timestamp']) : '—'));
if (!$opt['apply']) { $say("\nDry run. Add --apply to write."); exit(0); }

$now = time();
$wdb->beginTransaction();
try {
    $ins = $wdb->prepare('INSERT INTO messages (client_id, role, content, page_title, timestamp, core_version, model, prompt_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($new as $m) $ins->execute([$cid, $m['role'], $m['content'], 'Lodestone on Telegram', $m['timestamp'], $m['core_version'], $m['model'], $m['prompt_hash']]);

    $c = $one($wdb, 'SELECT * FROM clients WHERE client_id = ?', [$cid]);
    $set = ['profile_summary = ?', 'situation_capsule = ?', 'message_count = message_count + ?', 'carried_at = ?'];
    $val = [$syn['profile_summary'], mb_substr((string)($syn['situation_capsule'] ?? ''), 0, 60), count($new), $now];
    if (empty($c['display_name']) && !empty($syn['display_name'])) { $set[] = 'display_name = ?'; $val[] = $syn['display_name']; }
    if (empty($c['timezone']) && !empty($user['timezone'])) { $set[] = 'timezone = ?'; $val[] = $user['timezone']; }
    if (empty($c['dominant_emotion']) && !empty($user['dominant_emotion'])) { $set[] = 'dominant_emotion = ?'; $val[] = $user['dominant_emotion']; }
    foreach (['doctrinal_fluency', 'bodily_openness'] as $k) if (array_key_exists($k, $c) && isset($user[$k])) { $set[] = "$k = MAX(COALESCE($k,0), ?)"; $val[] = (int)$user[$k]; }
    if ((int)$c['first_seen'] > (int)$user['first_seen'] && (int)$user['first_seen'] > 0) { $set[] = 'first_seen = ?'; $val[] = (int)$user['first_seen']; }
    $val[] = $cid;
    $wdb->prepare('UPDATE clients SET ' . implode(', ', $set) . ' WHERE client_id = ?')->execute($val);
    // Waiting again for their browser (--status, and the purge's guard, read this).
    $wdb->prepare('UPDATE clients SET held_migrated_at = 0 WHERE lower(email) = ?')->execute([$email]);

    // A re-run replaces what an earlier run wrote rather than adding to it.
    $wdb->prepare("DELETE FROM profile_notes WHERE client_id = ? AND confidence = 'migrated'")->execute([$cid]);
    $wdb->prepare('DELETE FROM conversation_summaries WHERE client_id = ? AND first_message_id = -1')->execute([$cid]);
    $insN = $wdb->prepare('INSERT INTO profile_notes (client_id, category, observation, confidence, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($syn['profile_notes'] as $n) $insN->execute([$cid, (string)$n['category'], (string)$n['observation'], 'migrated', $now, $now]);

    $tgWheel = [];
    foreach ($all($tdb, 'SELECT pillar, discussion_count, last_discussed FROM wheel_profile WHERE telegram_id = ?', [$tg]) as $r) $tgWheel[$r['pillar']] = $r;
    foreach ($syn['wheel'] as $pillar => $w) {
        $cur = $one($wdb, 'SELECT id, discussion_count, last_discussed FROM wheel_profile WHERE client_id = ? AND pillar = ?', [$cid, $pillar]);
        $count = (int)($cur['discussion_count'] ?? 0) + (int)($tgWheel[$pillar]['discussion_count'] ?? 0);
        $last = max((int)($cur['last_discussed'] ?? 0), (int)($tgWheel[$pillar]['last_discussed'] ?? 0));
        $fields = [$w['engagement_level'], (string)($w['primary_concerns'] ?? ''), (string)($w['strengths'] ?? ''), (string)($w['growth_edges'] ?? ''), (string)($w['notes'] ?? ''), $count, $last, $now];
        if ($cur) $wdb->prepare('UPDATE wheel_profile SET engagement_level = ?, primary_concerns = ?, strengths = ?, growth_edges = ?, notes = ?, discussion_count = ?, last_discussed = ?, updated_at = ? WHERE id = ?')->execute(array_merge($fields, [$cur['id']]));
        else $wdb->prepare('INSERT INTO wheel_profile (engagement_level, primary_concerns, strengths, growth_edges, notes, discussion_count, last_discussed, updated_at, client_id, pillar) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_merge($fields, [$cid, $pillar]));
    }

    $insS = $wdb->prepare('INSERT INTO conversation_summaries (client_id, period_start, period_end, message_count, first_message_id, last_message_id, summary, pillars_discussed, key_developments, created_at) VALUES (?, ?, ?, ?, -1, -1, ?, ?, ?, ?)');
    foreach ($syn['conversation_summaries'] as $s) {
        $a = strtotime($s['period_start'] . ' 00:00:00'); $b = strtotime($s['period_end'] . ' 23:59:59');
        $n = count(array_filter($msgs, function ($m) use ($a, $b) { return $m['timestamp'] >= $a && $m['timestamp'] <= $b; }));
        $insS->execute([$cid, $a, $b, $n, (string)$s['summary'], (string)($s['pillars_discussed'] ?? ''), (string)($s['key_developments'] ?? ''), $now]);
    }
    $wdb->commit();
} catch (Throwable $e) {
    $wdb->rollBack();
    fwrite(STDERR, 'write failed, nothing changed: ' . $e->getMessage() . "\n"); exit(1);
}
$say("\nWritten. It moves into their own memory the next time they sign in on harmonism.io or in Lodestone Desktop.");
$say("Check later with --status; then --purge-telegram --apply.");
exit(0);


function validate($s): string {
    if (!is_array($s)) return 'not an object';
    if (!is_string($s['profile_summary'] ?? null) || mb_strlen($s['profile_summary']) < 300) return 'profile_summary missing or too short';
    if (!is_array($s['profile_notes'] ?? null) || !$s['profile_notes']) return 'profile_notes missing';
    foreach ($s['profile_notes'] as $n) if (!is_array($n) || empty($n['category']) || empty($n['observation'])) return 'a profile note lacks category/observation';
    if (!is_array($s['wheel'] ?? null)) return 'wheel missing';
    foreach ($s['wheel'] as $p => $w) {
        if (!in_array($p, PILLARS, true)) return "unknown pillar $p";
        if (!in_array($w['engagement_level'] ?? '', LEVELS, true)) return "bad engagement_level for $p";
    }
    if (!is_array($s['conversation_summaries'] ?? null)) return 'conversation_summaries missing';
    foreach ($s['conversation_summaries'] as $c) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['period_start'] ?? '') || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['period_end'] ?? '') || empty($c['summary'])) return 'a conversation summary is malformed';
    }
    return '';
}

/**
 * The model writes the synthesis on the server. Map: notes per chunk of the
 * conversation. Reduce: the JSON the SPEC asks for. Nothing is printed.
 */
function synthesize(int $tg, array $user, array $msgs, PDO $tdb, callable $all, array $opt): array {
    $cfg = $opt['config'] ?: (is_file('/home/SERVER_USER/.telegram-config.php') ? '/home/SERVER_USER/.telegram-config.php' : __DIR__ . '/.telegram-config.php');
    if (!is_file($cfg)) { fwrite(STDERR, "config not found ($cfg); pass --config=PATH\n"); exit(1); }
    require_once $cfg;
    require_once __DIR__ . '/llm-gateway.php';
    $model = defined('COMPANION_MODEL') ? COMPANION_MODEL : 'claude-opus-5-5';

    $held = "Existing profile summary:\n" . ($user['profile_summary'] ?? '') . "\n\nSituation: " . ($user['situation_capsule'] ?? '') . "\n";
    foreach ($all($tdb, 'SELECT category, observation FROM profile_notes WHERE telegram_id = ?', [$tg]) as $n) $held .= "- {$n['category']}: {$n['observation']}\n";
    foreach ($all($tdb, 'SELECT pillar, engagement_level, primary_concerns, strengths, growth_edges, notes FROM wheel_profile WHERE telegram_id = ?', [$tg]) as $w) $held .= "Wheel {$w['pillar']}: {$w['engagement_level']}; concerns {$w['primary_concerns']}; strengths {$w['strengths']}; edges {$w['growth_edges']}; {$w['notes']}\n";
    foreach ($all($tdb, 'SELECT period_start, period_end, summary, key_developments FROM conversation_summaries WHERE telegram_id = ? ORDER BY period_start', [$tg]) as $s) $held .= '[' . date('Y-m-d', $s['period_start']) . '–' . date('Y-m-d', $s['period_end']) . "] {$s['summary']} {$s['key_developments']}\n";

    $chunks = []; $buf = '';
    foreach ($msgs as $m) {
        $line = '[' . date('Y-m-d H:i', $m['timestamp']) . '] ' . ($m['role'] === 'user' ? 'PERSON' : 'LODESTONE') . ': ' . $m['content'] . "\n\n";
        if (mb_strlen($buf) + mb_strlen($line) > 60000 && $buf !== '') { $chunks[] = $buf; $buf = ''; }
        $buf .= $line;
    }
    if ($buf !== '') $chunks[] = $buf;

    $mapSys = "You are preparing Lodestone's memory of one person so it can continue with them on a new surface. Read this part of their conversation with Lodestone and write exhaustive, dated, factual notes: health (conditions, medications with doses, test results with dates and values, practitioners, symptoms over time, what helped and what didn't), the people in their life and their roles, practices, goals, how they like to be spoken to (language, register, length, what landed, what irritated them), and open threads (promises to follow up, pending events with dates). Only what the text supports; mark interpretations. Notes only, no preamble.";
    $notes = []; $lost = 0; $total = mb_strlen(implode('', $chunks));
    // A part the model answers empty is read again in two halves, then in quarters; only what
    // still fails is left out, and if more than a fifth of the conversation is lost the synthesis stops.
    $read = function (string $c, string $label, int $depth) use (&$read, &$notes, &$lost, $model, $mapSys) {
        $r = llm_call($model, $mapSys, "$label:\n\n" . $c, 16000);   // room for hidden reasoning before the notes
        if ($r !== null) { $notes[] = "## $label\n" . $r; return; }
        $cut = $depth < 2 ? mb_strpos($c, "\n\n[", (int)(mb_strlen($c) / 2)) : false;
        if ($cut === false || $cut <= 0) { $lost += mb_strlen($c); fwrite(STDOUT, "  $label: left out after retries\n"); return; }
        fwrite(STDOUT, "  $label: reading it in two halves\n");
        $read(mb_substr($c, 0, $cut), "$label a", $depth + 1);
        $read(mb_substr($c, $cut + 2), "$label b", $depth + 1);
    };
    foreach ($chunks as $i => $c) {
        $read($c, 'Part ' . ($i + 1) . ' of ' . count($chunks), 0);
        fwrite(STDOUT, '  read part ' . ($i + 1) . '/' . count($chunks) . "\n");
    }
    if ($lost > 0.2 * $total) return fallback($user, $tg, $tdb, $all, $opt, sprintf('%d%% of the conversation could not be read', 100 * $lost / max(1, $total)));
    if ($lost) fwrite(STDOUT, sprintf("  %d%% of the conversation could not be read; the synthesis is made from the rest, and every message is still carried\n", 100 * $lost / max(1, $total)));

    $spec = <<<'SPEC'
Write what Lodestone should know about this person when they continue on the web app / desktop app, so its answers are more personal than before. It is loaded into Lodestone's memory, shown to the model, not to the person. Third person, dense, factual; no flattery, no therapy-speak, no generic wellness. Wheel of Harmony pillars (health, presence, relationships, matter, service, learning, nature, recreation) where load-bearing. Every claim from the material; latest state wins, superseded facts kept as dated history; medical content precise; how to talk to them; open threads; people with roles.
Return ONLY a JSON object with exactly these keys:
{"display_name": str, "language": str, "situation_capsule": str (<=60 chars),
 "profile_summary": markdown str 5000-9000 chars,
 "profile_notes": [{"category": "health|medication|history|family|people|preference|register|practice|goal|open_thread|logistics", "observation": str}] (25-60 items),
 "wheel": {pillar: {"engagement_level": "absent|nascent|developing|functional|sophisticated|sovereign|unknown", "primary_concerns": str, "strengths": str, "growth_edges": str, "notes": str}} for all 8 pillars,
 "conversation_summaries": [{"period_start": "YYYY-MM-DD", "period_end": "YYYY-MM-DD", "summary": str, "key_developments": str}] (4-10, chronological)}
SPEC;
    $out = llm_call($model, $spec, "What Lodestone already held:\n\n$held\n\nNotes from the full conversation:\n\n" . implode("\n\n", $notes), 32000);
    if ($out === null) return fallback($user, $tg, $tdb, $all, $opt, 'reduce step');
    $out = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($out)));
    $j = json_decode($out, true);
    if (!is_array($j) || validate($j) !== '') return fallback($user, $tg, $tdb, $all, $opt, 'model output did not validate');
    return $j;
}

function llm_call(string $model, string $system, string $user, int $max): ?string {
    for ($try = 0; $try < 3; $try++) {
        $ch = llm_curl_init();
        llm_curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'max_tokens' => $max, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ''), 'anthropic-version: 2023-06-01'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = llm_curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 200) {
            $d = json_decode((string)$raw, true);
            $text = '';
            foreach (($d['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
            if ($text !== '') return $text;
        }
        $d = json_decode((string)$raw, true);
        $why = isset($d['error']) ? ('error ' . ($d['error']['code'] ?? '') . ' ' . mb_substr((string)($d['error']['message'] ?? ''), 0, 120)) : ('stop ' . ($d['stop_reason'] ?? '?') . ', ' . (is_array($d['content'] ?? null) ? count($d['content']) . ' blocks, ' . mb_strlen((string)($d['content'][0]['text'] ?? '')) . ' chars' : 'no content'));
        fwrite(STDOUT, "  model call failed (http $code; $why), retrying\n");
        sleep(5 * ($try + 1));
    }
    return null;
}

/** Telegram's own distilled memory, carried unchanged — only with --carry-fallback. */
function fallback(array $user, int $tg, PDO $tdb, callable $all, array $opt, string $why): array {
    if (!$opt['carry-fallback']) { fwrite(STDERR, "synthesis failed ($why); nothing written. Re-run, or add --carry-fallback to carry Telegram's existing memory as it is.\n"); exit(1); }
    fwrite(STDOUT, "  synthesis failed ($why); carrying Telegram's existing memory instead\n");
    $wheel = [];
    foreach ($all($tdb, 'SELECT * FROM wheel_profile WHERE telegram_id = ?', [$tg]) as $w)
        if (in_array($w['pillar'], PILLARS, true)) $wheel[$w['pillar']] = ['engagement_level' => in_array($w['engagement_level'], LEVELS, true) ? $w['engagement_level'] : 'unknown', 'primary_concerns' => $w['primary_concerns'], 'strengths' => $w['strengths'], 'growth_edges' => $w['growth_edges'], 'notes' => $w['notes']];
    $notes = array_map(function ($n) { return ['category' => $n['category'], 'observation' => $n['observation']]; }, $all($tdb, 'SELECT category, observation FROM profile_notes WHERE telegram_id = ?', [$tg]));
    if (!$notes) $notes = [['category' => 'history', 'observation' => 'Continued from Lodestone on Telegram.']];
    $sums = array_map(function ($s) { return ['period_start' => date('Y-m-d', $s['period_start']), 'period_end' => date('Y-m-d', $s['period_end']), 'summary' => $s['summary'], 'key_developments' => $s['key_developments']]; },
        $all($tdb, 'SELECT * FROM conversation_summaries WHERE telegram_id = ? ORDER BY period_start', [$tg]));
    $summary = (string)($user['profile_summary'] ?? '');
    if (mb_strlen($summary) < 300) $summary = str_pad($summary, 300, ' ');
    return ['display_name' => $user['first_name'], 'situation_capsule' => (string)($user['situation_capsule'] ?? ''), 'profile_summary' => $summary, 'profile_notes' => $notes, 'wheel' => $wheel, 'conversation_summaries' => $sums];
}
