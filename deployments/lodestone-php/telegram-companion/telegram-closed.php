<?php
/**
 * telegram-closed.php — Lodestone on Telegram is closed to conversation
 * (Decision #1560, October 29, 2026; brings #1382's closing forward from
 * December 17). Required by telegram-bot.php, process-queue.php and the
 * scheduled senders (outreach-engine.php, outreach-deliver.php, reading-checkin.php).
 *
 * What the bot still does: answers every message with the closure notice, and
 * carries a person's memory into the Lodestone app on request:
 *   /transfer               an account already linked: the carry is queued
 *   /transfer you@email     not linked: a code to enter in Lodestone's Settings → Account (#1580); entering
 *                           it (member/link-telegram.php, action verify) links the
 *                           account and queues the carry
 * process-queue.php runs each queued carry (telegramRunTransfers) through
 * migrate-telegram-to-held.php --synthesize --carry-fallback --apply, and tells the
 * person on Telegram when it is done. Their Lodestone app, signed in with that
 * email, takes the memory in from memory-export.php.
 *
 * Nothing new is stored: no message, no model call. What stays on the server is
 * purged on December 17, 2026 as #1382 says.
 */

if (!defined('LODESTONE_TELEGRAM_CLOSED')) define('LODESTONE_TELEGRAM_CLOSED', true);

const TG_CLOSED_APP = 'https://lodestone.harmonism.io/app/';
const TG_CLOSED_SITE = 'https://lodestone.harmonism.io';
const TG_CLOSED_REGISTER = 'https://lodestone.harmonism.io/member/register.php';
const TG_CLOSED_DASHBOARD = 'https://lodestone.harmonism.io/app/#account';   // the code is entered in Lodestone's Settings → Account (#1580)

/** Queue columns on users (companion-data.db). Idempotent. */
function telegramTransferSchema(PDO $db): void {
    foreach (['transfer_requested_at INTEGER DEFAULT 0', 'transfer_done_at INTEGER DEFAULT 0', "transfer_result TEXT DEFAULT ''", "transfer_lang TEXT DEFAULT 'en'"] as $c) {
        try { $db->exec("ALTER TABLE users ADD COLUMN $c"); } catch (PDOException $e) {}
    }
}

function telegramClosedLang(string $lang): string { return in_array($lang, ['en', 'fr', 'es', 'ar'], true) ? $lang : 'en'; }

/** The texts, by language. {email}, {code}, {date} are filled by telegramClosedSay(). */
function telegramClosedText(string $key, string $lang): string {
    $app = TG_CLOSED_APP; $site = TG_CLOSED_SITE; $reg = TG_CLOSED_REGISTER; $dash = TG_CLOSED_DASHBOARD;
    $t = [
        'closed' => [
            'en' => "Lodestone on Telegram has closed. Lodestone now lives in its app, on your phone or computer, where your memory stays on your own devices, encrypted, and Harmonia's server keeps none of it: $app\n\nTo stay on Telegram, run your own Lodestone bot from Lodestone Desktop: $site",
            'fr' => "Lodestone sur Telegram a fermé. Lodestone vit désormais dans son application, sur votre téléphone ou votre ordinateur, où votre mémoire reste sur vos propres appareils, chiffrée, et le serveur de Harmonia n'en garde rien : $app\n\nPour rester sur Telegram, faites tourner votre propre bot Lodestone depuis Lodestone Desktop : $site",
            'es' => "Lodestone en Telegram ha cerrado. Lodestone vive ahora en su aplicación, en tu teléfono u ordenador, donde tu memoria se queda en tus propios dispositivos, cifrada, y el servidor de Harmonia no guarda nada de ella: $app\n\nPara seguir en Telegram, ejecuta tu propio bot de Lodestone desde Lodestone Desktop: $site",
            'ar' => "أُغلق Lodestone على تيليغرام. يعيش Lodestone الآن في تطبيقه، على هاتفك أو حاسوبك، حيث تبقى ذاكرتك على أجهزتك أنت، مشفّرة، ولا يحتفظ خادم Harmonia بشيء منها: $app\n\nللبقاء على تيليغرام، شغّل بوت Lodestone الخاص بك من Lodestone Desktop: $site",
        ],
        'how_unlinked' => [
            'en' => "What Lodestone learned of you here can come with you. Send:\n/transfer your@email.com\nwith the email of your harmonism.io account (none yet? create one free: $reg). Whatever is not carried is deleted from Harmonia's server on 17 December 2026.",
            'fr' => "Ce que Lodestone a appris de vous ici peut vous suivre. Envoyez :\n/transfer votre@email.com\navec l'adresse de votre compte harmonism.io (pas encore de compte ? créez-en un gratuitement : $reg). Ce qui n'est pas transféré est effacé du serveur de Harmonia le 17 décembre 2026.",
            'es' => "Lo que Lodestone aprendió de ti aquí puede ir contigo. Envía:\n/transfer tu@email.com\ncon el correo de tu cuenta de harmonism.io (¿aún no tienes? créala gratis: $reg). Lo que no se transfiera se borra del servidor de Harmonia el 17 de diciembre de 2026.",
            'ar' => "ما تعلّمه Lodestone عنك هنا يمكن أن ينتقل معك. أرسل:\n/transfer your@email.com\nبالبريد الإلكتروني لحسابك على harmonism.io (لا حساب لديك بعد؟ أنشئ واحداً مجاناً: $reg). ما لا يُنقل يُحذف من خادم Harmonia في 17 ديسمبر 2026.",
        ],
        'how_linked' => [
            'en' => "What Lodestone learned of you here can come with you, into the app signed in as {email}. Send /transfer. Whatever is not carried is deleted from Harmonia's server on 17 December 2026.",
            'fr' => "Ce que Lodestone a appris de vous ici peut vous suivre, dans l'application connectée avec {email}. Envoyez /transfer. Ce qui n'est pas transféré est effacé du serveur de Harmonia le 17 décembre 2026.",
            'es' => "Lo que Lodestone aprendió de ti aquí puede ir contigo, a la aplicación con la sesión de {email}. Envía /transfer. Lo que no se transfiera se borra del servidor de Harmonia el 17 de diciembre de 2026.",
            'ar' => "ما تعلّمه Lodestone عنك هنا يمكن أن ينتقل معك، إلى التطبيق المسجَّل باسم {email}. أرسل /transfer. ما لا يُنقل يُحذف من خادم Harmonia في 17 ديسمبر 2026.",
        ],
        'queued' => [
            'en' => "Carrying your memory into Lodestone for {email}. It takes a few minutes; I will write here when it is done.",
            'fr' => "Votre mémoire part vers Lodestone pour {email}. Cela prend quelques minutes ; j'écrirai ici quand ce sera fait.",
            'es' => "Tu memoria va camino de Lodestone para {email}. Tarda unos minutos; escribiré aquí cuando esté hecho.",
            'ar' => "تُنقل ذاكرتك إلى Lodestone لحساب {email}. يستغرق ذلك بضع دقائق؛ سأكتب هنا عند الانتهاء.",
        ],
        'pending' => [
            'en' => "Your memory is already on its way for {email}. I will write here when it is done.",
            'fr' => "Votre mémoire est déjà en route pour {email}. J'écrirai ici quand ce sera fait.",
            'es' => "Tu memoria ya va en camino para {email}. Escribiré aquí cuando esté hecho.",
            'ar' => "ذاكرتك في طريقها بالفعل لحساب {email}. سأكتب هنا عند الانتهاء.",
        ],
        'code' => [
            'en' => "To prove the account is yours:\n\n🔑 {code}\n\n1. Open $dash\n2. Sign in as {email}\n3. Enter the code under Telegram\n\nThe code lasts 15 minutes. Once it is entered your memory is carried, and I will write here when it is done.",
            'fr' => "Pour prouver que le compte est le vôtre :\n\n🔑 {code}\n\n1. Ouvrez $dash\n2. Connectez-vous avec {email}\n3. Saisissez le code sous Telegram\n\nLe code vaut 15 minutes. Dès qu'il est saisi, votre mémoire est transférée, et j'écrirai ici quand ce sera fait.",
            'es' => "Para demostrar que la cuenta es tuya:\n\n🔑 {code}\n\n1. Abre $dash\n2. Inicia sesión con {email}\n3. Escribe el código en Telegram\n\nEl código dura 15 minutos. En cuanto lo escribas, tu memoria se transfiere, y escribiré aquí cuando esté hecho.",
            'ar' => "لإثبات أن الحساب حسابك:\n\n🔑 {code}\n\n1. افتح $dash\n2. سجّل الدخول بـ {email}\n3. أدخل الرمز تحت Telegram\n\nالرمز صالح 15 دقيقة. بمجرد إدخاله تُنقل ذاكرتك، وسأكتب هنا عند الانتهاء.",
        ],
        'other_email' => [
            'en' => "This Telegram account is linked to {email}. Send /transfer to carry your memory there.",
            'fr' => "Ce compte Telegram est lié à {email}. Envoyez /transfer pour y transférer votre mémoire.",
            'es' => "Esta cuenta de Telegram está vinculada a {email}. Envía /transfer para llevar tu memoria allí.",
            'ar' => "حساب تيليغرام هذا مرتبط بـ {email}. أرسل /transfer لنقل ذاكرتك إليه.",
        ],
        'done' => [
            'en' => "Done. Your memory is ready for Lodestone. Open it signed in as {email} and it arrives: $app",
            'fr' => "C'est fait. Votre mémoire attend Lodestone. Ouvrez-le connecté avec {email}, et elle arrive : $app",
            'es' => "Hecho. Tu memoria está lista para Lodestone. Ábrelo con la sesión de {email} y llegará: $app",
            'ar' => "تمّ. ذاكرتك جاهزة لـ Lodestone. افتحه مسجّلاً باسم {email} وستصل: $app",
        ],
        'already' => [
            'en' => "Your memory was already carried into Lodestone for {email} on {date}. Open Lodestone signed in with that email: $app",
            'fr' => "Votre mémoire a déjà été transférée dans Lodestone pour {email} le {date}. Ouvrez Lodestone connecté avec cette adresse : $app",
            'es' => "Tu memoria ya se llevó a Lodestone para {email} el {date}. Abre Lodestone con la sesión de ese correo: $app",
            'ar' => "سبق نقل ذاكرتك إلى Lodestone لحساب {email} في {date}. افتح Lodestone مسجّلاً بهذا البريد: $app",
        ],
        'failed' => [
            'en' => "Your memory could not be carried just now. Harmonia has been told and will carry it by hand; nothing is lost before 17 December.",
            'fr' => "Votre mémoire n'a pas pu être transférée pour l'instant. Harmonia est prévenu et la transférera à la main ; rien n'est perdu avant le 17 décembre.",
            'es' => "Tu memoria no se pudo transferir ahora. Harmonia lo sabe y la transferirá a mano; nada se pierde antes del 17 de diciembre.",
            'ar' => "تعذّر نقل ذاكرتك الآن. أُبلغت Harmonia وستنقلها يدوياً؛ لا يضيع شيء قبل 17 ديسمبر.",
        ],
        'bad_email' => [
            'en' => "That does not look like an email. Send:\n/transfer your@email.com",
            'fr' => "Ce n'est pas une adresse email. Envoyez :\n/transfer votre@email.com",
            'es' => "Eso no parece un correo. Envía:\n/transfer tu@email.com",
            'ar' => "هذا لا يبدو بريداً إلكترونياً. أرسل:\n/transfer your@email.com",
        ],
        'nothing' => [
            'en' => "Lodestone on Telegram holds no memory of you, so there is nothing to carry.",
            'fr' => "Lodestone sur Telegram ne garde aucune mémoire de vous : il n'y a rien à transférer.",
            'es' => "Lodestone en Telegram no guarda memoria tuya: no hay nada que transferir.",
            'ar' => "لا يحتفظ Lodestone على تيليغرام بأي ذاكرة عنك، فلا شيء لنقله.",
        ],
        'button' => [
            'en' => 'Lodestone on Telegram has closed.', 'fr' => 'Lodestone sur Telegram a fermé.',
            'es' => 'Lodestone en Telegram ha cerrado.', 'ar' => 'أُغلق Lodestone على تيليغرام.',
        ],
    ];
    return $t[$key][telegramClosedLang($lang)] ?? $t[$key]['en'];
}

/** Plain text, never Markdown: an email's underscore would make Telegram refuse the message. */
function telegramClosedSend(int $chatId, string $text): void {
    sendTelegramRequest('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true]);
}

function telegramClosedSay(string $key, string $lang, array $fill = []): string {
    $s = telegramClosedText($key, $lang);
    foreach ($fill as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
    return $s;
}

/** Whether this Telegram user holds any memory worth carrying. */
function telegramHasMemory(PDO $db, int $tg): bool {
    $q = $db->prepare('SELECT COUNT(*) FROM messages WHERE telegram_id = ?'); $q->execute([$tg]);
    if ((int)$q->fetchColumn() > 0) return true;
    $q = $db->prepare("SELECT 1 FROM users WHERE telegram_id = ? AND (COALESCE(profile_summary, '') != '' OR COALESCE(situation_capsule, '') != '')"); $q->execute([$tg]);
    return (bool)$q->fetchColumn();
}

/**
 * The bot's whole behaviour while closed. Called by telegram-bot.php for every
 * message update; never returns.
 */
function telegramClosedHandle(CompanionMemory $memory, int $chatId, string $text, string $lang): void {
    http_response_code(200);
    $db = $memory->getDb();   // one connection: a second one finds the database locked
    telegramTransferSchema($db);
    $known = $db->prepare('SELECT * FROM users WHERE telegram_id = ?'); $known->execute([$chatId]);
    $user = $known->fetch(PDO::FETCH_ASSOC) ?: null;
    $linked = $user ? $memory->isAccountLinked($chatId) : false;
    $email = $linked ? (string)$memory->getLinkedEmail($chatId) : '';
    $has = $user && telegramHasMemory($db, $chatId);
    $send = function (string $s) use ($chatId) { telegramClosedSend($chatId, $s); };

    $parts = preg_split('/\s+/', trim($text), 2);
    $cmd = strtolower(preg_replace('/@\w+$/', '', $parts[0] ?? ''));
    $arg = trim($parts[1] ?? '');

    if ($cmd === '/transfer' || $cmd === '/link') {
        if (!$has) { $send(telegramClosedSay('nothing', $lang)); exit; }
        if ($linked) {
            if ($arg !== '' && strtolower($arg) !== strtolower($email)) { $send(telegramClosedSay('other_email', $lang, ['email' => $email])); exit; }
            if ((int)$user['transfer_done_at'] && $user['transfer_result'] === 'ok') {
                $send(telegramClosedSay('already', $lang, ['email' => $email, 'date' => date('Y-m-d', (int)$user['transfer_done_at'])])); exit;
            }
            if ((int)$user['transfer_requested_at'] > (int)$user['transfer_done_at']) { $send(telegramClosedSay('pending', $lang, ['email' => $email])); exit; }
            $db->prepare('UPDATE users SET transfer_requested_at = ?, transfer_lang = ? WHERE telegram_id = ?')->execute([time(), telegramClosedLang($lang), $chatId]);
            $send(telegramClosedSay('queued', $lang, ['email' => $email]));
            exit;
        }
        if (!filter_var($arg, FILTER_VALIDATE_EMAIL)) { $send(telegramClosedSay($arg === '' ? 'how_unlinked' : 'bad_email', $lang)); exit; }
        $code = $memory->requestAccountLink($chatId, $arg);
        $db->prepare('UPDATE users SET transfer_lang = ? WHERE telegram_id = ?')->execute([telegramClosedLang($lang), $chatId]);
        $send(telegramClosedSay('code', $lang, ['code' => $code, 'email' => strtolower($arg)]));
        exit;
    }

    // Anything else: the notice, and how to carry the memory if there is any.
    $msg = telegramClosedSay('closed', $lang);
    if ($has && $linked && (int)($user['transfer_done_at'] ?? 0) && ($user['transfer_result'] ?? '') === 'ok') {
        $msg .= "\n\n" . telegramClosedSay('already', $lang, ['email' => $email, 'date' => date('Y-m-d', (int)$user['transfer_done_at'])]);
    } elseif ($has) {
        $msg .= "\n\n" . ($linked ? telegramClosedSay('how_linked', $lang, ['email' => $email]) : telegramClosedSay('how_unlinked', $lang));
    }
    $send($msg);
    exit;
}

/**
 * Runs the queued carries. Called from process-queue.php's loop; each carry is
 * migrate-telegram-to-held.php in its own process (it prints counts only, never
 * content). Each person is told on Telegram how it went.
 */
function telegramRunTransfers(PDO $db): int {
    $notify = function (int $tg, string $s) { telegramClosedSend($tg, $s); };
    telegramTransferSchema($db);
    $rows = $db->query('SELECT u.telegram_id, u.transfer_lang, l.member_email FROM users u JOIN linked_accounts l ON l.telegram_id = u.telegram_id AND l.verified = 1
                        WHERE u.transfer_requested_at > u.transfer_done_at ORDER BY u.transfer_requested_at LIMIT 3')->fetchAll(PDO::FETCH_ASSOC);
    $n = 0;
    foreach ($rows as $r) {
        $tg = (int)$r['telegram_id']; $email = strtolower((string)$r['member_email']);
        $lang = telegramClosedLang((string)($r['transfer_lang'] ?? 'en'));
        $cmd = [PHP_BINARY ?: 'php', __DIR__ . '/migrate-telegram-to-held.php', "--tg=$tg", "--email=$email", '--synthesize', '--carry-fallback', '--apply'];
        $out = ''; $err = ''; $code = -1;
        $p = function_exists('proc_open') ? @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__) : false;
        if (is_resource($p)) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $code = proc_close($p);
        } else $err = 'proc_open unavailable';
        if ($code === 0) $result = 'ok';
        elseif (strpos($err, 'already took') !== false) $result = 'ok';
        else $result = 'failed';
        $db->prepare('UPDATE users SET transfer_done_at = ?, transfer_result = ? WHERE telegram_id = ?')->execute([time(), $result, $tg]);
        error_log("[transfer] tg=$tg exit=$code result=$result " . trim(preg_replace('/\s+/', ' ', $err)));
        if (strpos($err, 'already took') !== false) {
            $notify($tg, telegramClosedSay('already', $lang, ['email' => $email, 'date' => date('Y-m-d')]));
        } else $notify($tg, telegramClosedSay($result === 'ok' ? 'done' : 'failed', $lang, ['email' => $email]));
        $n++;
    }
    return $n;
}
