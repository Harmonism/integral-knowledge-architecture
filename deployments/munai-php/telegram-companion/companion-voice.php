<?php
/**
 * companion-voice.php — MunAI's spoken register on Telegram.
 *
 * Renders a MunAI reply that has ALREADY been delivered as text into audio and
 * sends it after the text, as a Telegram voice message. The text is never
 * replaced, never delayed, and never conditional on anything in this file
 * succeeding: one answer per inbound message is the reliability contract
 * (Decision #1145), and the voice is strictly additive to it.
 *
 * Voice: Ara — the practitioner-facing voice under the fixed TTS convention
 * (Decision #584: Leo narrates doctrine and canon, Ara narrates
 * practitioner-facing content, Sal narrates long-form). Provider is xAI Grok
 * TTS, the same engine behind the article-audio pipeline
 * (harmonia-astro/scripts/tts-smart-update.py), so the corpus and the companion
 * speak in one voice family.
 *
 * Format: xAI returns MP3, and Telegram's sendVoice accepts MP3 directly
 * ("your audio must be in an .OGG file encoded with OPUS, or in .MP3 format,
 * or in .M4A format" — Bot API), so nothing is transcoded and ffmpeg is not
 * required on the server. The audio is held in memory and posted as multipart;
 * it is never written to disk, inside public_html or out of it.
 *
 * Where MunAI does NOT speak, by design:
 *   - Crisis replies. Those never reach the worker — telegram-bot.php answers
 *     them deterministically and inline, and the hotline numbers must stay
 *     readable, tappable and re-readable rather than spoken once (#1179, #535).
 *   - Witness mode. In acute activation the practitioner needs to be witnessed,
 *     not performed at. A synthetic voice reading fresh grief back simulates
 *     exactly the presence Decision #1139 says MunAI must never offer itself as.
 *   - Replies longer than MUNAI_VOICE_MAX_CHARS. Cutting MunAI off mid-thought
 *     is worse than staying silent, and the length governor (#1145) puts its
 *     best work — the refusals, which run 100–400 characters — well inside the
 *     cap anyway.
 *
 * Third-party disclosure: the reply text leaves the server for xAI to render.
 * The practitioner's own words never do. This is why voice is opt-in per
 * practitioner and off by default — see /voice in telegram-bot.php.
 */

// ─── Configuration ───────────────────────────────────────────────────────────

// Ara is the practitioner-facing voice of the fixed narration convention
// (#584) and stays the default. A practitioner may choose the male register
// instead; the corpus voices are deliberately NOT on offer — Leo narrates
// doctrine and canon, Sal narrates long-form, and a companion answering in
// either would blur the article's voice into the conversation's.
if (!defined('MUNAI_VOICE_ID'))        define('MUNAI_VOICE_ID', 'ara');
if (!defined('MUNAI_VOICE_ID_FEMALE')) define('MUNAI_VOICE_ID_FEMALE', 'ara');
if (!defined('MUNAI_VOICE_ID_MALE'))   define('MUNAI_VOICE_ID_MALE', 'rex');
if (!defined('MUNAI_VOICE_ENDPOINT'))  define('MUNAI_VOICE_ENDPOINT', 'https://api.x.ai/v1/tts');
// Telegram API base. A constant rather than a literal so the multipart upload
// path can be exercised against a local stand-in; production never redefines it.
if (!defined('MUNAI_VOICE_TG_API'))    define('MUNAI_VOICE_TG_API', 'https://api.telegram.org/bot');

// Above this, the reply is left as text only. 1200 characters is roughly 80
// seconds of speech and sits well above MunAI's best register (#1145).
if (!defined('MUNAI_VOICE_MAX_CHARS')) define('MUNAI_VOICE_MAX_CHARS', 1200);

// Per-practitioner rolling 24h ceiling on synthesised characters. At $15 per
// million characters this caps one practitioner's voice at ~$0.30/day, which
// keeps the xAI key inside the bounded-spend condition Tier B requires (#485).
if (!defined('MUNAI_VOICE_DAILY_CHARS')) define('MUNAI_VOICE_DAILY_CHARS', 20000);

// xAI list price, September 2026 — used only for the cost line in the log.
if (!defined('MUNAI_VOICE_USD_PER_CHAR')) define('MUNAI_VOICE_USD_PER_CHAR', 15.0 / 1000000);

// Languages the TTS request is allowed to declare. Anything else falls back to
// English rather than asserting a language the voice may not carry.
if (!defined('MUNAI_VOICE_LANGS'))     define('MUNAI_VOICE_LANGS', 'en,fr,es,ar,pt,de,ru');

// Valid values for the users.voice_mode column.
if (!defined('MUNAI_VOICE_MODES'))     define('MUNAI_VOICE_MODES', 'off,mirror,always');


/**
 * Pronunciation overrides applied in memory before synthesis, mirroring
 * PRONUNCIATION_OVERRIDES in scripts/tts-smart-update.py (#728). The stored
 * message text is never touched — only what is sent to the API.
 *
 * Only entries verified by ear belong here. The French one is carried over from
 * the article pipeline, where a capital H plus elision triggers an English-like
 * aspirated H. Add Harmonist-terminology entries as listening tests confirm
 * them; do not guess respellings that have not been heard.
 */
if (!function_exists('munaiVoicePronunciation')) {
    function munaiVoicePronunciation(string $text, string $lang): string {
        $map = [
            'fr' => [
                // Both cases: the article pipeline only ever meets this at the
                // head of a sentence, MunAI meets it mid-sentence far more often.
                "L'Harmonisme" => "L'harmonisme",
                "l'Harmonisme" => "l'harmonisme",
                "L'Harmoniste" => "L'harmoniste",
                "l'Harmoniste" => "l'harmoniste",
            ],
        ];
        foreach ($map[$lang] ?? [] as $from => $to) {
            $text = str_replace($from, $to, $text);
        }
        return $text;
    }
}


/**
 * Reduce a MunAI reply to speakable prose.
 *
 * Markdown emphasis, wikilinks, headers and bullets are structure for the eye.
 * URLs are the sharpest case: a spoken harmonism.io path is noise, and the
 * clickable link is already in the text message immediately above, so bare URLs
 * are removed rather than read out.
 */
if (!function_exists('munaiVoiceCleanForSpeech')) {
    function munaiVoiceCleanForSpeech(string $text, string $lang = 'en'): string {
        $t = $text;

        // Fenced and inline code — structure, not speech.
        $t = preg_replace('/```.*?```/su', ' ', $t);
        $t = preg_replace('/`([^`]*)`/u', '$1', $t);

        // Markdown links: keep the label, drop the target.
        $t = preg_replace('/\[([^\]]+)\]\((?:[^)]*)\)/u', '$1', $t);
        // Wikilinks: [[Target|Display]] -> Display, [[Target]] -> Target.
        $t = preg_replace('/\[\[(?:[^\]|]+\|)?([^\]]+)\]\]/u', '$1', $t);
        // Bare URLs and bare domains. A spoken URL is noise, and the clickable
        // one is already in the text message immediately above. They are marked
        // with a sentinel first so the connector that introduced them ("read it
        // at …") can be dropped by position rather than guessed at afterwards.
        $t = preg_replace('#\bhttps?://\S+#iu', "\x00", $t);
        $t = preg_replace('/\b[\w.-]+\.(?:io|com|org|net|app)\b(?:\/\S*)?/iu', "\x00", $t);
        $t = preg_replace('/\s*\b(?:at|on|see|via|from|à|sur|voir|en|ver|visita)\b\s*(?=\x00)/iu', ' ', $t);
        $t = str_replace("\x00", '', $t);

        // Emphasis markers.
        $t = preg_replace('/\*\*([^*]+)\*\*/u', '$1', $t);
        $t = preg_replace('/\*([^*\n]+)\*/u', '$1', $t);
        $t = preg_replace('/(?<![\w_])_([^_\n]+)_(?![\w_])/u', '$1', $t);

        // Headers, blockquote carets, horizontal rules, list bullets.
        $t = preg_replace('/^\s{0,3}#{1,6}\s+/mu', '', $t);
        $t = preg_replace('/^\s{0,3}>\s?/mu', '', $t);
        $t = preg_replace('/^\s{0,3}(?:[-*_]\s*){3,}$/mu', '', $t);
        $t = preg_replace('/^\s{0,3}[-*•]\s+/mu', '', $t);
        $t = preg_replace('/^\s{0,3}\d+[.)]\s+/mu', '', $t);

        // Emoji and dingbats: read aloud they are either noise or silence.
        $t = preg_replace('/[\x{1F300}-\x{1FAFF}\x{1F000}-\x{1F2FF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u', '', $t);

        // Structural metadata that must never be spoken (or written) — the
        // relative-time tags the prompt injects on prior user turns (#657).
        $t = preg_replace('/\[ago:[^\]]*\]/iu', '', $t);

        $t = preg_replace('/\s+([,.;:!?])/u', '$1', $t);
        $t = preg_replace('/\(\s*\)/u', '', $t);
        $t = preg_replace('/([—–])\s*\1+/u', '$1', $t);
        $t = preg_replace('/\s*([—–])\s*/u', ' $1 ', $t);

        $t = preg_replace('/[ \t]{2,}/u', ' ', $t);
        $t = preg_replace('/\n{3,}/u', "\n\n", $t);
        $t = trim($t);

        return munaiVoicePronunciation($t, $lang);
    }
}


/**
 * Decide whether this reply should also be spoken.
 *
 * @param array $ctx mode, inbound_voice, witness, has_key, chars_used_24h
 * @return array ['send' => bool, 'reason' => string, 'speech' => string]
 */
if (!function_exists('munaiVoiceDecide')) {
    function munaiVoiceDecide(string $responseText, array $ctx): array {
        $no = fn(string $why) => ['send' => false, 'reason' => $why, 'speech' => ''];

        $mode = (string) ($ctx['mode'] ?? 'off');
        if (!in_array($mode, explode(',', MUNAI_VOICE_MODES), true) || $mode === 'off') {
            return $no('mode_off');
        }
        if (empty($ctx['has_key']))                                  return $no('no_api_key');
        if ($mode === 'mirror' && empty($ctx['inbound_voice']))      return $no('mode_mirror_text_inbound');
        if (!empty($ctx['witness']))                                 return $no('witness_mode');

        $speech = munaiVoiceCleanForSpeech($responseText, (string) ($ctx['lang'] ?? 'en'));
        $len = mb_strlen($speech);
        if ($len === 0)                                              return $no('empty_after_cleaning');
        if ($len > MUNAI_VOICE_MAX_CHARS)                            return $no("too_long:{$len}");

        $used = (int) ($ctx['chars_used_24h'] ?? 0);
        if ($used + $len > MUNAI_VOICE_DAILY_CHARS)                  return $no("daily_cap:{$used}");

        return ['send' => true, 'reason' => 'ok', 'speech' => $speech];
    }
}


/**
 * Synthesise speech. Returns raw MP3 bytes, or null on any failure.
 */
if (!function_exists('munaiVoiceSynthesize')) {
    function munaiVoiceSynthesize(string $speech, string $lang, string $apiKey, string $voiceId = MUNAI_VOICE_ID): ?string {
        $lang = in_array($lang, explode(',', MUNAI_VOICE_LANGS), true) ? $lang : 'en';
        $voiceId = munaiVoiceResolveId($voiceId);

        $payload = json_encode([
            'text'     => $speech,
            'voice_id' => $voiceId,
            'language' => $lang,
            'output_format' => [
                'codec'       => 'mp3',
                'sample_rate' => 24000,
                'bit_rate'    => 64000,   // speech at 24 kHz; half the article bitrate, a third of the bytes
            ],
        ]);

        $ch = curl_init(MUNAI_VOICE_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$apiKey}",
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);

        if ($curlErr !== '') {
            error_log("[munai-voice] TTS curl error: {$curlErr}");
            return null;
        }
        if ($httpCode !== 200 || !is_string($result) || $result === '') {
            error_log("[munai-voice] TTS HTTP {$httpCode}: " . mb_substr((string) $result, 0, 300));
            return null;
        }
        // A JSON body where audio was expected is an error envelope.
        if (str_starts_with(ltrim($result), '{')) {
            error_log('[munai-voice] TTS returned JSON, not audio: ' . mb_substr($result, 0, 300));
            return null;
        }
        return $result;
    }
}


/**
 * Resolve a stored preference to a voice id. 'male' / 'female' are what the
 * practitioner sets; anything unrecognised falls back to the default rather
 * than asking the API to speak in a voice that does not exist.
 */
if (!function_exists('munaiVoiceResolveId')) {
    function munaiVoiceResolveId(string $pref): string {
        return match (strtolower(trim($pref))) {
            'male'   => MUNAI_VOICE_ID_MALE,
            'female' => MUNAI_VOICE_ID_FEMALE,
            default  => MUNAI_VOICE_ID,
        };
    }
}


/**
 * Post the MP3 to Telegram as a voice message (multipart, from memory).
 *
 * Falls back to sendAudio if sendVoice rejects the file: a playable track is
 * still the spoken reply, just in a different bubble.
 */
if (!function_exists('munaiVoiceDeliver')) {
    function munaiVoiceDeliver(int $chatId, string $mp3, string $method = 'sendVoice'): bool {
        $field    = ($method === 'sendVoice') ? 'voice' : 'audio';
        $boundary = uniqid('munaivoice', true);

        $body = "--{$boundary}\r\n"
              . "Content-Disposition: form-data; name=\"chat_id\"\r\n\r\n"
              . "{$chatId}\r\n"
              . "--{$boundary}\r\n"
              . "Content-Disposition: form-data; name=\"{$field}\"; filename=\"munai.mp3\"\r\n"
              . "Content-Type: audio/mpeg\r\n\r\n"
              . $mp3 . "\r\n";
        if ($method === 'sendAudio') {
            $body .= "--{$boundary}\r\n"
                   . "Content-Disposition: form-data; name=\"title\"\r\n\r\n"
                   . "MunAI\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        $ch = curl_init(MUNAI_VOICE_TG_API . TELEGRAM_BOT_TOKEN . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ["Content-Type: multipart/form-data; boundary={$boundary}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);

        if ($curlErr !== '') {
            error_log("[munai-voice] {$method} curl error: {$curlErr}");
            return false;
        }
        $data = json_decode((string) $result, true);
        if ($httpCode === 200 && is_array($data) && ($data['ok'] ?? false)) {
            return true;
        }

        error_log("[munai-voice] {$method} HTTP {$httpCode}: " . mb_substr((string) $result, 0, 300));
        if ($method === 'sendVoice') {
            error_log('[munai-voice] retrying as sendAudio');
            return munaiVoiceDeliver($chatId, $mp3, 'sendAudio');
        }
        return false;
    }
}


/**
 * Orchestrator: decide, synthesise, send. Never throws into the caller.
 *
 * @return array ['sent'=>bool,'reason'=>string,'chars'=>int,'cost_usd'=>float]
 */
if (!function_exists('munaiVoiceSendReply')) {
    function munaiVoiceSendReply(int $chatId, string $responseText, array $ctx): array {
        $out = ['sent' => false, 'reason' => 'unknown', 'chars' => 0, 'cost_usd' => 0.0];
        try {
            $decision = munaiVoiceDecide($responseText, $ctx);
            $out['reason'] = $decision['reason'];
            if (!$decision['send']) {
                return $out;
            }

            $speech = $decision['speech'];
            $chars  = mb_strlen($speech);

            if (function_exists('sendTelegramAction')) {
                sendTelegramAction($chatId, 'record_voice');
            }

            $t0  = microtime(true);
            $mp3 = munaiVoiceSynthesize($speech, (string) ($ctx['lang'] ?? 'en'), (string) $ctx['api_key'], (string) ($ctx['voice'] ?? 'female'));
            if ($mp3 === null) {
                $out['reason'] = 'tts_failed';
                return $out;
            }
            $synthSec = round(microtime(true) - $t0, 1);

            $sent = munaiVoiceDeliver($chatId, $mp3);
            $out['sent']     = $sent;
            $out['reason']   = $sent ? 'ok' : 'telegram_failed';
            $out['chars']    = $chars;
            $out['cost_usd'] = round($chars * MUNAI_VOICE_USD_PER_CHAR, 6);

            error_log(sprintf(
                '[munai-voice] chat=%d %s voice=%s chars=%d bytes=%d synth=%ss cost=$%s',
                $chatId, $sent ? 'sent' : 'FAILED', munaiVoiceResolveId((string) ($ctx['voice'] ?? 'female')),
                $chars, strlen($mp3), $synthSec, $out['cost_usd']
            ));
            return $out;
        } catch (\Throwable $e) {
            // The text answer is already delivered; a voice failure must never
            // surface to the practitioner or interrupt the worker.
            error_log('[munai-voice] exception: ' . $e->getMessage());
            $out['reason'] = 'exception';
            return $out;
        }
    }
}
