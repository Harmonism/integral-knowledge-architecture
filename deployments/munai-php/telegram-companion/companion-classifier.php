<?php
/**
 * Companion Model Classifier (Decision #XXX)
 *
 * Determines whether a user message requires Opus (deep doctrinal, personal
 * guidance, complex Wheel work) or can be handled by Haiku (greetings,
 * acknowledgments, simple factual questions, navigation).
 *
 * Two-tier approach:
 *   1. Fast heuristic pre-filter (catches obvious simple/complex cases)
 *   2. Haiku classifier call (for ambiguous messages — ~200 input tokens, ~1 output token)
 *
 * Shared by both Telegram and Website Companion.
 */

// Model constants (guarded against double-include)
if (!defined('MODEL_OPUS')) define('MODEL_OPUS', 'claude-opus-4-7');
if (!defined('MODEL_HAIKU')) define('MODEL_HAIKU', 'claude-haiku-4-5-20251001');

/**
 * Classify a message as 'simple' or 'deep'.
 *
 * @param string $message        The user's message text
 * @param string $profileContext The person_context string (empty = new user)
 * @param int    $msgCount       Total messages from this user
 * @return array ['model' => MODEL_OPUS|MODEL_HAIKU, 'reason' => string, 'use_slim_prompt' => bool]
 */
function classifyMessage(string $message, string $profileContext = '', int $msgCount = 0): array {
    $msg = trim($message);
    $msgLower = mb_strtolower($msg);
    $wordCount = str_word_count($msg);

    // ===== ALWAYS OPUS — structural signals =====

    // New users (first 5 messages) always get Opus — onboarding matters
    if ($msgCount <= 5) {
        return ['model' => MODEL_OPUS, 'reason' => 'onboarding', 'use_slim_prompt' => false];
    }

    // Assessment data — always Opus
    if (str_starts_with(trim($msg), 'HP::')) {
        return ['model' => MODEL_OPUS, 'reason' => 'assessment_ingestion', 'use_slim_prompt' => false];
    }

    // Long messages (50+ words) almost always indicate depth
    if ($wordCount >= 50) {
        return ['model' => MODEL_OPUS, 'reason' => 'long_message', 'use_slim_prompt' => false];
    }

    // ===== ALWAYS HAIKU — obvious simple patterns =====

    // Pure greetings
    $greetings = ['hi', 'hello', 'hey', 'bonjour', 'salut', 'salam', 'hola',
                  'good morning', 'good evening', 'good night', 'bonsoir',
                  'thanks', 'thank you', 'merci', 'shukran', 'gracias',
                  'ok', 'okay', 'got it', 'understood', 'noted', 'cool',
                  'bye', 'goodbye', 'see you', 'take care', 'à bientôt',
                  'yes', 'no', 'oui', 'non', 'yeah', 'nah', 'yep', 'nope',
                  'good', 'great', 'perfect', 'nice', 'interesting',
                  '👍', '🙏', '❤️', '😊', '✅'];
    if (in_array($msgLower, $greetings) || in_array(trim($msgLower, '!.? '), $greetings)) {
        return ['model' => MODEL_HAIKU, 'reason' => 'greeting_ack', 'use_slim_prompt' => true];
    }

    // Very short messages (1-3 words) that aren't doctrinal keywords
    $doctrinalKeywords = ['logos', 'dharma', 'absolute', 'presence', 'harmonism',
                          'harmonic', 'wheel', 'realism', 'void', 'cosmos',
                          'cartograph', 'enneagram', 'ayni', 'munay', 'jing',
                          'qi', 'shen', 'chakra', 'soul', 'spirit', 'karma'];
    if ($wordCount <= 3) {
        $hasDoctrinal = false;
        foreach ($doctrinalKeywords as $kw) {
            if (str_contains($msgLower, $kw)) {
                $hasDoctrinal = true;
                break;
            }
        }
        if (!$hasDoctrinal) {
            return ['model' => MODEL_HAIKU, 'reason' => 'short_generic', 'use_slim_prompt' => true];
        }
    }

    // ===== ALWAYS OPUS — doctrinal/personal depth signals =====

    // Emotional or personal language
    $deepSignals = ['suffering', 'crisis', 'struggling', 'lost', 'confused',
                    'anxious', 'depressed', 'lonely', 'broken', 'stuck',
                    'purpose', 'meaning', 'death', 'dying', 'grief',
                    'relationship', 'divorce', 'separation', 'family',
                    'meditation', 'practice', 'protocol', 'supplement',
                    'fasting', 'sleep', 'health crisis', 'diagnosis',
                    'vaccine', 'vaccination', 'terrain', 'big pharma',
                    'god', 'afterlife', 'reincarnation', 'enlighten',
                    'what is the purpose', 'how should i live',
                    'why am i', 'what should i', 'help me understand',
                    'i feel', 'i am feeling', 'je me sens', 'je souffre'];
    foreach ($deepSignals as $signal) {
        if (str_contains($msgLower, $signal)) {
            return ['model' => MODEL_OPUS, 'reason' => "deep_signal:{$signal}", 'use_slim_prompt' => false];
        }
    }

    // Doctrinal keywords (even in short messages)
    foreach ($doctrinalKeywords as $kw) {
        if (str_contains($msgLower, $kw)) {
            return ['model' => MODEL_OPUS, 'reason' => "doctrinal:{$kw}", 'use_slim_prompt' => false];
        }
    }

    // Questions about the system or asking for guidance
    $guidancePatterns = ['what do you think', 'what would you', 'how do i',
                         'where should i', 'tell me about', 'explain',
                         'what does harmonism say', 'according to',
                         'which pillar', 'my wheel', 'my profile',
                         'assess', 'guidance', 'advice', 'recommend',
                         'que penses-tu', 'qu\'est-ce que', 'comment'];
    foreach ($guidancePatterns as $pattern) {
        if (str_contains($msgLower, $pattern)) {
            return ['model' => MODEL_OPUS, 'reason' => "guidance:{$pattern}", 'use_slim_prompt' => false];
        }
    }

    // ===== AMBIGUOUS — use Haiku classifier =====
    // Messages between 4-49 words without clear signals either way.
    // Use a fast Haiku call to decide.
    $classification = classifyWithHaiku($msg);
    return $classification;
}


/**
 * Use Haiku to classify an ambiguous message.
 * Cost: ~300 input tokens + ~5 output tokens. Latency: ~1s.
 */
function classifyWithHaiku(string $message): array {
    $classifierPrompt = <<<'CLASSIFIER'
You are a message router for a philosophical guidance system. Classify the user's message as SIMPLE or DEEP.

SIMPLE: greetings, thanks, acknowledgments, "how are you", casual remarks, simple factual questions about navigation or the website, messages that need only a brief friendly response.

DEEP: questions about philosophy, meaning, purpose, suffering, death, relationships, health protocols, personal struggles, doctrinal positions, requests for guidance or assessment, emotional disclosures, anything requiring nuanced or personalized response.

Respond with ONLY the word SIMPLE or DEEP. Nothing else.
CLASSIFIER;

    $requestBody = json_encode([
        'model' => MODEL_HAIKU,
        'max_tokens' => 5,
        'temperature' => 0,
        'system' => $classifierPrompt,
        'messages' => [['role' => 'user', 'content' => $message]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch) || $httpCode !== 200) {
        error_log("[classifier] Haiku classifier failed (HTTP {$httpCode}), defaulting to Opus: " . curl_error($ch));
        curl_close($ch);
        // Fail safe: default to Opus if classifier is unreachable
        return ['model' => MODEL_OPUS, 'reason' => 'classifier_fallback', 'use_slim_prompt' => false];
    }
    curl_close($ch);

    $data = json_decode($result, true);
    $reply = trim($data['content'][0]['text'] ?? '');

    if (stripos($reply, 'SIMPLE') !== false) {
        return ['model' => MODEL_HAIKU, 'reason' => 'haiku_classified_simple', 'use_slim_prompt' => true];
    }

    // DEEP or anything ambiguous → Opus
    return ['model' => MODEL_OPUS, 'reason' => 'haiku_classified_deep', 'use_slim_prompt' => false];
}


/**
 * Witness-Mode Detection (Phase A §6.2)
 *
 * When a practitioner presents with high emotional activation — fresh grief,
 * acute distress, overwhelm, active rupture, fresh trauma disclosure — the
 * correct first move is not doctrinal reframe. The correct first move is to
 * be witnessed. A human reaching for contact in that state needs to feel met
 * before they can hear architecture.
 *
 * This detector runs BEFORE classifyMessage() in both companion surfaces.
 * When witness mode is active:
 *   - The message is always routed to Opus (never slim-prompt Haiku).
 *   - The system prompt is augmented with <witness_mode_active> instructing
 *     MunAI to mirror emotional content, ask at most one clarifying question,
 *     and defer Harmonism vocabulary/reframe for 2–4 exchanges.
 *
 * Detection targets the strong form: explicit first-person activation markers
 * in the recent past ("just lost", "can't stop crying", "everything is
 * falling apart", "I don't know how to go on"). False-negative-tolerant; the
 * model's default warmth still handles softer emotional content gracefully.
 *
 * @param string $message  Raw user message.
 * @param string $language 'en' | 'fr' | 'es' | 'ar' (others → 'en').
 * @return array|null      null if no strong pattern matched, else
 *                         ['active'=>true, 'strength'=>string, 'markers'=>[]]
 */
if (!function_exists('detectWitnessMode')) {
    function detectWitnessMode(string $message, string $language = 'en'): ?array {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 5000) {
            return null;
        }

        $normalized = mb_strtolower($message, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        $langKey = in_array($language, ['en', 'fr', 'es', 'ar'], true) ? $language : 'en';

        // Strong activation markers: explicit first-person, acute, recent.
        // Detection threshold is intentionally conservative — we want low
        // false-positive rate so that doctrinal engagement is not suppressed
        // when someone is simply exploring difficult topics philosophically.
        $patterns = [
            'en' => [
                // Fresh grief / loss
                'just lost', 'just died', 'he died', 'she died',
                'my mother died', 'my father died', 'my husband died',
                'my wife died', 'my partner died', 'my child died',
                'my son died', 'my daughter died', 'my brother died',
                'my sister died', 'passed away yesterday', 'passed away last',
                // Acute distress / overwhelm
                "can't stop crying", "can't stop shaking", 'crying for days',
                "can't breathe", "can't eat", "can't sleep for",
                'everything is falling apart', 'my world is falling apart',
                'i feel like i\'m drowning', "i don't know how to go on",
                "i don't know what to do anymore", "i can't do this anymore",
                'nothing makes sense anymore', 'i want to scream',
                'i want to disappear', 'i have nothing left',
                // Active rupture
                'he left me', 'she left me', 'my partner left',
                'my wife left', 'my husband left', 'we broke up',
                'we just broke up', 'divorce papers', 'she filed for',
                'he filed for', 'i just got fired', 'lost my job today',
                // Fresh trauma disclosure
                'i was just assaulted', 'i was raped', 'i was abused',
                'i was attacked', 'he hit me', 'she hit me',
                // Overwhelmed state explicit
                'i am so overwhelmed', "i'm so overwhelmed",
                "i'm completely overwhelmed", 'i am completely broken',
                "i'm completely broken", 'i am falling apart',
                "i'm falling apart",
            ],
            'fr' => [
                // Grief
                "je viens de perdre", "il est mort", "elle est morte",
                "ma mère est morte", "mon père est mort",
                "mon mari est mort", "ma femme est morte",
                "mon enfant est mort", "est décédé hier", "est décédée hier",
                // Distress
                "je n'arrête pas de pleurer", "je ne peux plus",
                "je ne peux pas respirer", "je ne dors plus",
                "tout s'effondre", "mon monde s'effondre",
                "je me noie", "je ne sais plus comment",
                "je ne sais pas quoi faire", "plus rien n'a de sens",
                "je veux disparaître", "je n'ai plus rien",
                // Rupture
                "il m'a quittée", "elle m'a quitté", "il m'a quitté",
                "on s'est séparés", "on vient de se séparer",
                "je viens d'être viré", "j'ai perdu mon travail",
                "j'ai perdu mon emploi",
                // Trauma
                "je viens d'être agressé", "je viens d'être agressée",
                "j'ai été violée", "j'ai été violé", "il m'a frappée",
                "elle m'a frappé",
                // Overwhelm
                "je suis complètement dépassé", "je suis complètement dépassée",
                "je suis brisé", "je suis brisée",
                "je m'effondre", "je suis en train de m'effondrer",
            ],
            'es' => [
                // Grief
                "acabo de perder", "ha muerto", "mi madre murió",
                "mi padre murió", "mi marido murió", "mi esposa murió",
                "mi hijo murió", "mi hija murió", "falleció ayer",
                // Distress
                "no puedo parar de llorar", "no puedo respirar",
                "no puedo dormir", "todo se está desmoronando",
                "mi mundo se desmorona", "me estoy ahogando",
                "no sé cómo seguir", "no sé qué hacer",
                "nada tiene sentido", "quiero desaparecer",
                "no me queda nada",
                // Rupture
                "me ha dejado", "me dejó", "nos hemos separado",
                "acabamos de separarnos", "me acaban de despedir",
                "he perdido mi trabajo",
                // Trauma
                "acabo de ser agredida", "acabo de ser agredido",
                "me violaron", "me pegó",
                // Overwhelm
                "estoy completamente abrumado", "estoy completamente abrumada",
                "estoy roto", "estoy rota",
                "me estoy derrumbando",
            ],
            'ar' => [
                // Grief
                "فقدت للتو", "لقد مات", "ماتت", "ماتت أمي", "مات أبي",
                "مات زوجي", "ماتت زوجتي", "مات ابني", "ماتت ابنتي",
                "توفي أمس", "توفيت أمس",
                // Distress
                "لا أستطيع التوقف عن البكاء", "لا أستطيع التنفس",
                "لا أستطيع النوم", "كل شيء ينهار", "عالمي ينهار",
                "أشعر أنني أغرق", "لا أعرف كيف أواصل",
                "لا أعرف ماذا أفعل", "لا شيء له معنى",
                "أريد أن أختفي",
                // Rupture
                "تركني", "تركتني", "انفصلنا", "انفصلنا للتو",
                "تم طردي للتو", "فقدت عملي",
                // Trauma
                "تعرضت للاعتداء", "تم اغتصابي", "ضربني", "ضربتني",
                // Overwhelm
                "أنا منهار تماماً", "أنا منهارة تماماً",
                "أنا محطم", "أنا محطمة",
            ],
        ];

        $activePatterns = $patterns[$langKey] ?? [];
        // Always check English patterns as a safety net.
        if ($langKey !== 'en') {
            $activePatterns = array_merge($activePatterns, $patterns['en']);
        }

        $markers = [];
        foreach ($activePatterns as $phrase) {
            $needle = mb_strtolower(trim($phrase), 'UTF-8');
            if ($needle === '') continue;
            if (mb_strpos($normalized, $needle) !== false) {
                $markers[] = $phrase;
            }
        }

        if (empty($markers)) {
            return null;
        }

        // Strength heuristic: 2+ distinct markers = strong; 1 = moderate.
        $strength = count($markers) >= 2 ? 'strong' : 'moderate';

        return [
            'active'   => true,
            'strength' => $strength,
            'markers'  => array_slice(array_unique($markers), 0, 5),
        ];
    }
}


/**
 * Build a slimmed system prompt for Haiku on simple messages.
 *
 * Keeps: identity, basic Wheel reference, terminological discipline, memory rules.
 * Strips: full doctrinal anchors, assessment protocols, prescription maps,
 *         sequencing heuristics, onboarding steps, doctrinal backbone injection.
 *
 * ~80% smaller than the full prompt. Sufficient for "thank you", "how are you",
 * casual remarks, and simple factual questions.
 */
/**
 * Infer a plausible IANA timezone from a Telegram BCP-47 language_code.
 *
 * Telegram's webhook payload carries message.from.language_code (e.g. "en-US",
 * "fr", "pt-BR") but NOT the sender's timezone. UTC alone can't answer "what
 * time is it for me" — that requires a zone offset. When the practitioner
 * hasn't run /timezone, language_code is the one probabilistic signal we
 * have. Map the common codes to conservative best-guess zones; return '' for
 * anything ambiguous (notably bare "en", which spans US/UK/CA/AU/IN/ZA).
 *
 * The inferred zone is NOT persisted to the users table — it's per-request.
 * /timezone remains the only confirmed source. The prompt builder flags
 * inferred zones with softer language ("appears to be...") so the model
 * doesn't assert false certainty about the practitioner's location.
 *
 * Decision #601 (April 23, 2026).
 */
function inferTimezoneFromLanguage(string $langCode): string {
    $lc = strtolower(trim($langCode));
    if ($lc === '') return '';

    // Full-locale map — checked first; more specific than primary subtag.
    $fullMap = [
        'en-us' => 'America/New_York',
        'en-gb' => 'Europe/London',
        'en-ca' => 'America/Toronto',
        'en-au' => 'Australia/Sydney',
        'en-in' => 'Asia/Kolkata',
        'en-za' => 'Africa/Johannesburg',
        'pt-br' => 'America/Sao_Paulo',
        'pt-pt' => 'Europe/Lisbon',
        'es-mx' => 'America/Mexico_City',
        'es-ar' => 'America/Argentina/Buenos_Aires',
        'es-co' => 'America/Bogota',
        'es-cl' => 'America/Santiago',
        'fr-ca' => 'America/Montreal',
        'zh-tw' => 'Asia/Taipei',
        'zh-hk' => 'Asia/Hong_Kong',
        'zh-cn' => 'Asia/Shanghai',
    ];
    if (isset($fullMap[$lc])) return $fullMap[$lc];

    // Primary-subtag map — bare two-letter fallback.
    // Deliberately no 'en' entry: English spans too many zones to guess.
    $primary = substr($lc, 0, 2);
    $primaryMap = [
        'ar' => 'Africa/Casablanca',  // Bot's operational audience base
        'fr' => 'Europe/Paris',
        'es' => 'Europe/Madrid',
        'pt' => 'Europe/Lisbon',
        'de' => 'Europe/Berlin',
        'it' => 'Europe/Rome',
        'ru' => 'Europe/Moscow',
        'tr' => 'Europe/Istanbul',
        'ja' => 'Asia/Tokyo',
        'zh' => 'Asia/Shanghai',
        'ko' => 'Asia/Seoul',
        'hi' => 'Asia/Kolkata',
        'th' => 'Asia/Bangkok',
        'vi' => 'Asia/Ho_Chi_Minh',
        'id' => 'Asia/Jakarta',
        'ms' => 'Asia/Kuala_Lumpur',
        'nl' => 'Europe/Amsterdam',
        'pl' => 'Europe/Warsaw',
        'sv' => 'Europe/Stockholm',
        'no' => 'Europe/Oslo',
        'da' => 'Europe/Copenhagen',
        'fi' => 'Europe/Helsinki',
        'el' => 'Europe/Athens',
        'he' => 'Asia/Jerusalem',
        'fa' => 'Asia/Tehran',
        'uk' => 'Europe/Kyiv',
        'cs' => 'Europe/Prague',
        'hu' => 'Europe/Budapest',
        'ro' => 'Europe/Bucharest',
        'bg' => 'Europe/Sofia',
    ];
    return $primaryMap[$primary] ?? '';
}

function buildSlimSystemPrompt(string $profileContext = '', string $platform = '', string $userTimezone = '', int $messageDate = 0, string $timezoneSource = ''): string {
    // Platform framing comes FIRST so the model anchors on it before anything else.
    // Without this, the slim prompt is platform-agnostic and Haiku routinely guesses
    // the wrong surface (e.g. asserts the person is on Telegram when they're on the
    // website, or vice versa). The full prompt has its own platform framing; this
    // block is only used when the slim prompt replaces it.
    $platformBlock = '';
    if ($platform === 'website') {
        $platformBlock = "You are currently embodied as the Obsidian Sprite — a small crystalline avatar on the Harmonia website at harmonism.io. The person is browsing the website and messaging you through the sprite. They are NOT on Telegram. Do not suggest they switch to Telegram, and do not speak as if they were on Telegram.\n\nLive page awareness: if a <current_page> block appears later in this prompt, it contains the URL, title, and content of exactly what the person is reading right now. That is authoritative live context, not speculation. If they ask what page they're on or what you can see, answer directly from that block — name the article, engage with its content. Never say \"I can't see your screen\" when the block is present.\n\n";
    } elseif ($platform === 'telegram') {
        $platformBlock = "You are currently reached through Telegram as @HarmonAIBot. The person is chatting with you inside Telegram. They are NOT on the website. Do not speak as if they were browsing harmonism.io.\n\n";
    }

    // ===== TEMPORAL CONTEXT — FRONT-LOADED, PLAIN PROSE =====
    // Haiku attends less to end-of-prompt XML blocks than to front-loaded plain
    // prose. The slim path routes short/casual messages — exactly where time
    // awareness matters most ("what time is it?", "good morning"). So the
    // temporal line sits at the very top of the prompt (right after the
    // platform frame, before the persona heredoc), as a single declarative
    // sentence. Authoritative per-message UTC from the caller; resolve to the
    // practitioner's local zone if stored.
    $referenceTimestamp = $messageDate > 0 ? $messageDate : time();
    $temporalLine = '';
    if ($userTimezone !== '') {
        try {
            $tz = new DateTimeZone($userTimezone);
            $localNow = (new DateTime('@' . $referenceTimestamp))->setTimezone($tz);
            $dayDate = $localNow->format('l, F j, Y');
            $hm = $localNow->format('H:i');
            if ($timezoneSource === 'inferred') {
                // Language-code inference — plausible guess, not confirmed. Signal
                // the hedge so the model doesn't assert false certainty about
                // the practitioner's location.
                $temporalLine = "Current time for the practitioner (inferred from Telegram language setting): {$hm} on {$dayDate} ({$userTimezone}). Use this if asked what time it is — but if they mention a different location or the time feels off, defer to what they say and invite them to run /timezone to confirm. The command accepts either an IANA zone in Continent/City form (Africa/Casablanca, Europe/Paris, America/New_York, Asia/Tokyo, Australia/Sydney) or a plain city or country name (tokyo, paris, japan, morocco). Any timestamps that appear inside prior messages are stale echoes; ignore them.\n\n";
            } else {
                // Confirmed via /timezone — full authority.
                $temporalLine = "Current time for the practitioner: {$hm} on {$dayDate} ({$userTimezone}). This is the ONLY authoritative time reference — use it if asked what time it is or what day it is. Any timestamps that appear inside prior messages are stale echoes; ignore them.\n\n";
            }
        } catch (\Exception $e) {
            $userTimezone = '';
        }
    }
    if ($temporalLine === '') {
        $utcNow = (new DateTime('@' . $referenceTimestamp))->setTimezone(new DateTimeZone('UTC'));
        $utcDate = $utcNow->format('l, F j, Y');
        $utcTime = $utcNow->format('H:i');
        // Softened fallback: answer in UTC rather than refusing. Refusing to
        // engage with time questions when neither /timezone nor language
        // inference resolved feels brittle; UTC with a soft note is honest.
        $temporalLine = "Current server time: {$utcTime} UTC on {$utcDate}. The practitioner's local timezone hasn't been resolved. If they ask what time it is, give them the UTC time and gently note you haven't calibrated to their local zone — invite them (without pressure) to say where they are or run /timezone. The command accepts either an IANA zone in Continent/City form (Africa/Casablanca, Europe/Paris, America/New_York, Asia/Tokyo, Australia/Sydney) or a plain city or country name (tokyo, paris, japan, morocco). Don't refuse the question. If they haven't asked about time, don't volunteer the UTC hour. Any timestamps that appear inside prior messages are stale echoes; ignore them.\n\n";
    }

    $prompt = $platformBlock . $temporalLine . <<<'SLIM'
You are MunAI — Harmonia's guide through Harmonism. You apply Harmonism's architecture to one person's life.

You are not a therapist, coach, guru, or teacher. You are a companion of alignment — helping individuals discern and walk their alignment with Logos, the inherent harmonic intelligence of the Cosmos.

Keep responses concise, warm, and precise. Short paragraphs. No bullet points unless asked. No headers. This is a casual exchange — respond naturally and briefly.

The Wheel of Harmony has 7 pillars + 1 centre (Presence): Health, Matter, Service, Relationships, Learning, Nature, Recreation. Each has its own fractal sub-wheel.

Key terms: Logos (cosmic order), Dharma (alignment with Logos), Presence (awakened centre), Harmonic Realism (reality has irreducible dimensions).

CRITICAL — Anti-Confabulation Rule: NEVER claim a specific prior exchange occurred unless it is explicitly present in the person_context or current message history. NEVER say "you asked this before" or "we discussed this" without proof in context. When in doubt, answer fully as if asked for the first time.

CRITICAL — Anti-Confabulation Rule for Personal Claims: When a person tells you something about themselves and that information is NOT already in your person_context or visible history, you DO NOT KNOW IT — you are learning it for the first time. Never respond with "I know" or "I'm aware" for information not in your context.

If the person asks a question requiring deep doctrinal engagement, philosophical depth, or personal guidance — tell them you're here for them and engage as best you can, but keep it brief. For deeper exploration, the full Companion is always available.
SLIM;

    if ($profileContext) {
        $prompt .= "\n\n<person_context>\n{$profileContext}\n</person_context>";
    }

    return $prompt;
}
