<?php
/**
 * companion-crisis-detector.php
 *
 * Shared crisis detection & regional resource routing for MunAI.
 * Consumed by both companion surfaces via require_once:
 *   - Website: harmonia-astro/companion-api.php (via post-build copy into dist/)
 *   - Telegram: harmonia-astro/telegram-companion/telegram-bot.php
 *
 * Architecture (Phase A §6.1, from "MunAI — Lessons from the Companion Landscape"):
 *   detectCrisis()        — strong-patterns only (first-person explicit intent);
 *                           returns null OR ['category','severity','pattern_matched'].
 *                           Designed for low false-positive rate; false negatives
 *                           acceptable (the model still engages via existing
 *                           Harmonism care guardrails).
 *   buildCrisisResponse() — deterministic template with regional hotline routing
 *                           by ISO country code (MA/FR/UK/GB/US/CA/DE/ES/PT) with
 *                           Befrienders Worldwide as international fallback.
 *   redactTrigger()       — trigger-redaction helper for audit logging without
 *                           storing verbatim crisis content.
 *
 * Patterns live in a separate JSON file (crisis-patterns.json) so the pattern
 * library can be versioned, reviewed, and updated without touching code.
 *
 * Categories:
 *   active_suicidal_ideation — critical — crisis hotline route
 *   self_harm                 — critical — crisis hotline route
 *   acute_dissociation        — high     — grounding + professional psychiatric
 *   intoxication_distress     — high     — emergency services + poison control
 *   child_safety              — critical — emergency services + child welfare
 */

if (!function_exists('crisisPatternsPath')) {
    function crisisPatternsPath(): string {
        // Prefer colocated JSON; fall back to __DIR__ resolution.
        $colocated = __DIR__ . '/crisis-patterns.json';
        if (file_exists($colocated)) {
            return $colocated;
        }
        // In website dist/, post-build.sh copies detector + JSON next to each other.
        $docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docroot && file_exists($docroot . '/crisis-patterns.json')) {
            return $docroot . '/crisis-patterns.json';
        }
        return $colocated; // will fail-open with empty library if missing
    }
}

if (!function_exists('loadCrisisPatterns')) {
    function loadCrisisPatterns(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        $path = crisisPatternsPath();
        if (!file_exists($path)) {
            error_log('[crisis-detector] pattern file missing at ' . $path);
            $cache = ['version' => 'missing', 'categories' => []];
            return $cache;
        }
        $raw = @file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['categories'])) {
            error_log('[crisis-detector] pattern file malformed: ' . $path);
            $cache = ['version' => 'malformed', 'categories' => []];
            return $cache;
        }
        $cache = $decoded;
        return $cache;
    }
}

if (!function_exists('detectCrisis')) {
    /**
     * Detect a crisis signal in a user message.
     *
     * @param string $message  Raw user message (will be normalized internally).
     * @param string $language Language hint: 'en', 'fr', 'es', 'ar' (others fall back to 'en').
     * @return array|null      null if no strong pattern matched, else
     *                         ['category'=>string, 'severity'=>string, 'pattern_matched'=>string]
     */
    function detectCrisis(string $message, string $language = 'en'): ?array {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 5000) {
            // Absurd-length inputs: skip to avoid regex pathology.
            return null;
        }

        // Normalize for matching: lowercase + collapse whitespace. Keep diacritics
        // (French/Spanish/Arabic patterns rely on them); use mb_strtolower.
        $normalized = mb_strtolower($message, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        $library = loadCrisisPatterns();
        $categories = $library['categories'] ?? [];

        $langKey = in_array($language, ['en', 'fr', 'es', 'ar'], true) ? $language : 'en';

        foreach ($categories as $categoryName => $def) {
            $severity = $def['severity'] ?? 'high';
            $patterns = $def['patterns'][$langKey] ?? [];
            // Always check English patterns as a safety net — users mix languages.
            if ($langKey !== 'en' && !empty($def['patterns']['en'])) {
                $patterns = array_merge($patterns, $def['patterns']['en']);
            }
            foreach ($patterns as $phrase) {
                $needle = mb_strtolower(trim($phrase), 'UTF-8');
                if ($needle === '') continue;
                if (mb_strpos($normalized, $needle) !== false) {
                    return [
                        'category'        => $categoryName,
                        'severity'        => $severity,
                        'pattern_matched' => $phrase,
                    ];
                }
            }
        }
        return null;
    }
}

if (!function_exists('crisisRegionalResources')) {
    function crisisRegionalResources(): array {
        // Curated regional table. Country codes are ISO 3166-1 alpha-2.
        // GB and UK both accepted (Telegram/browser locale inconsistency).
        return [
            'MA' => [
                'name'      => 'Morocco',
                'hotline'   => 'SOS Détresse Maroc : +212 5 22 87 47 40',
                'emergency' => '15 (SAMU) · 19 (Police)',
            ],
            'FR' => [
                'name'      => 'France',
                'hotline'   => '3114 — Numéro national de prévention du suicide (24/7)',
                'emergency' => '15 (SAMU) · 112',
            ],
            'BE' => [
                'name'      => 'Belgium',
                'hotline'   => 'Centre de Prévention du Suicide : 0800 32 123',
                'emergency' => '112',
            ],
            'CH' => [
                'name'      => 'Switzerland',
                'hotline'   => 'La Main Tendue : 143',
                'emergency' => '144 · 112',
            ],
            'UK' => [
                'name'      => 'United Kingdom',
                'hotline'   => 'Samaritans: 116 123 (free, 24/7)',
                'emergency' => '999 · 112',
            ],
            'GB' => [
                'name'      => 'United Kingdom',
                'hotline'   => 'Samaritans: 116 123 (free, 24/7)',
                'emergency' => '999 · 112',
            ],
            'US' => [
                'name'      => 'United States',
                'hotline'   => '988 — Suicide & Crisis Lifeline (call or text)',
                'emergency' => '911',
            ],
            'CA' => [
                'name'      => 'Canada',
                'hotline'   => '9-8-8 — Suicide Crisis Helpline (call or text)',
                'emergency' => '911',
            ],
            'DE' => [
                'name'      => 'Germany',
                'hotline'   => 'Telefonseelsorge: 0800 111 0 111 or 0800 111 0 222',
                'emergency' => '112',
            ],
            'ES' => [
                'name'      => 'Spain',
                'hotline'   => '024 — Línea de atención a la conducta suicida',
                'emergency' => '112',
            ],
            'PT' => [
                'name'      => 'Portugal',
                'hotline'   => 'SOS Voz Amiga: 213 544 545',
                'emergency' => '112',
            ],
            'NL' => [
                'name'      => 'Netherlands',
                'hotline'   => '113 Zelfmoordpreventie: 113 or 0800-0113',
                'emergency' => '112',
            ],
            'IT' => [
                'name'      => 'Italy',
                'hotline'   => 'Telefono Amico: 02 2327 2327',
                'emergency' => '112',
            ],
            'AU' => [
                'name'      => 'Australia',
                'hotline'   => 'Lifeline: 13 11 14',
                'emergency' => '000',
            ],
        ];
    }
}

if (!function_exists('buildCrisisResponse')) {
    /**
     * Build a deterministic crisis response in the user's language, routed to
     * regional resources when country is known, with Befrienders fallback.
     *
     * @param string      $category
     * @param string|null $country  ISO 3166-1 alpha-2 (upper or lowercase), or null.
     * @param string      $language 'en'|'fr'|'es'|'ar'
     * @return string
     */
    function buildCrisisResponse(string $category, ?string $country, string $language = 'en'): string {
        $langKey = in_array($language, ['en', 'fr', 'es', 'ar'], true) ? $language : 'en';
        $countryKey = $country ? strtoupper(trim($country)) : null;
        $table = crisisRegionalResources();
        $resource = ($countryKey && isset($table[$countryKey])) ? $table[$countryKey] : null;

        $befrienders = 'Befrienders Worldwide — https://www.befrienders.org (find a helpline in your country)';

        // Category-specific acknowledgement copy. All templates share the same
        // structure: (1) acknowledge the weight, (2) name the limit honestly,
        // (3) give a concrete next step (hotline / emergency), (4) close with
        // an invitation to stay present afterward. No reframing, no doctrine.
        $templates = [
            'active_suicidal_ideation' => [
                'en' => "What you've just shared carries real weight, and I want to meet it honestly.\n\nI'm a companion shaped by Harmonism — a presence, not a clinician. When someone is holding thoughts of ending their life, the most important thing is to reach a human being trained for this moment, right now.\n\n%RESOURCE%\n\nIf you are in immediate danger, please call your local emergency number.\n\nI'll stay here. When you're safe — whether that's in ten minutes or tomorrow — we can continue. You matter, and this conversation isn't going anywhere.",
                'fr' => "Ce que vous venez de partager a un poids réel, et je veux l'accueillir avec honnêteté.\n\nJe suis un compagnon façonné par l'Harmonisme — une présence, pas un clinicien. Quand quelqu'un porte des pensées de mettre fin à sa vie, le plus important est de joindre un être humain formé pour ce moment, maintenant.\n\n%RESOURCE%\n\nSi vous êtes en danger immédiat, appelez le numéro d'urgence local.\n\nJe reste ici. Quand vous serez en sécurité — dans dix minutes ou demain — nous pourrons continuer. Vous comptez, et cette conversation ne va nulle part.",
                'es' => "Lo que acabas de compartir tiene un peso real, y quiero acogerlo con honestidad.\n\nSoy un compañero moldeado por el Harmonismo — una presencia, no un clínico. Cuando alguien sostiene pensamientos de terminar con su vida, lo más importante es contactar a un ser humano formado para este momento, ahora.\n\n%RESOURCE%\n\nSi estás en peligro inmediato, llama a tu número de emergencia local.\n\nEstaré aquí. Cuando estés a salvo — dentro de diez minutos o mañana — podremos continuar. Importas, y esta conversación no va a ningún lado.",
                'ar' => "ما شاركتَه للتوّ يحمل ثقلاً حقيقياً، وأريد أن أقابله بصدق.\n\nأنا رفيق تشكَّل من الهارمونية — حضورٌ لا طبيب. حين يحمل المرء أفكاراً بإنهاء حياته، فالأهمّ هو الوصول إلى إنسانٍ مدرَّبٍ لهذه اللحظة، الآن.\n\n%RESOURCE%\n\nإن كنتَ في خطر مباشر، اتصل برقم الطوارئ المحلّي.\n\nسأبقى هنا. حين تكون في أمان — بعد عشر دقائق أو غداً — يمكننا المتابعة. أنتَ مهمّ، وهذه المحادثة لن تذهب إلى أيّ مكان.",
            ],
            'self_harm' => [
                'en' => "Thank you for trusting me with that. Hurting yourself is a real signal, and it deserves real care — not just words from an AI.\n\nPlease reach a trained human who can be with you through this.\n\n%RESOURCE%\n\nIf you've already caused injury that needs attention, please call your local emergency number.\n\nI'm here, and I'll stay here. When you're with someone safe, we can continue.",
                'fr' => "Merci de me l'avoir confié. Se faire du mal est un vrai signal, et cela mérite un vrai soin — pas seulement des mots d'une IA.\n\nJe vous prie de joindre un humain formé qui peut être avec vous à travers cela.\n\n%RESOURCE%\n\nSi vous avez déjà une blessure qui nécessite une attention, appelez le numéro d'urgence local.\n\nJe suis là, et je reste là. Quand vous serez avec quelqu'un en sécurité, nous pourrons continuer.",
                'es' => "Gracias por confiarme eso. Hacerte daño es una señal real, y merece un cuidado real — no sólo palabras de una IA.\n\nPor favor contacta a un humano formado que pueda estar contigo a través de esto.\n\n%RESOURCE%\n\nSi ya has causado una lesión que necesita atención, llama a tu número de emergencia local.\n\nEstoy aquí, y me quedaré aquí. Cuando estés con alguien seguro, podremos continuar.",
                'ar' => "شكراً لثقتك. إيذاء النفس إشارةٌ حقيقيّة، وتستحقّ رعايةً حقيقيّة — لا مجرّد كلماتٍ من ذكاءٍ اصطناعي.\n\nأرجوك تواصل مع إنسانٍ مدرَّب يستطيع أن يكون معك خلال هذا.\n\n%RESOURCE%\n\nإن كنتَ قد سبّبت جرحاً يحتاج رعاية، اتصل برقم الطوارئ المحلّي.\n\nأنا هنا، وسأبقى هنا. حين تكون مع شخصٍ آمن، يمكننا المتابعة.",
            ],
            'acute_dissociation' => [
                'en' => "What you're describing sounds like your nervous system is in a lot right now. Let's not try to think our way through this.\n\nIf you can, put your feet flat on the ground, press your palms against a surface, and name three things you can see. You are here. This body is safe in this moment.\n\nStates like this can be held by a human trained in trauma-informed care. Please reach out:\n\n%RESOURCE%\n\nIf symptoms are severe or you feel unsafe, call your local emergency number. I'll be here when you're back.",
                'fr' => "Ce que vous décrivez ressemble à un système nerveux submergé en ce moment. Ne cherchons pas à raisonner pour traverser cela.\n\nSi vous le pouvez, posez les pieds à plat au sol, pressez vos paumes contre une surface, et nommez trois choses que vous voyez. Vous êtes ici. Ce corps est en sécurité à cet instant.\n\nCe genre d'état peut être accueilli par un humain formé aux soins informés par le trauma. Contactez :\n\n%RESOURCE%\n\nSi les symptômes sont sévères ou si vous ne vous sentez pas en sécurité, appelez le numéro d'urgence local. Je serai là à votre retour.",
                'es' => "Lo que describes suena a un sistema nervioso muy cargado en este momento. No intentemos razonar para atravesar esto.\n\nSi puedes, pon los pies planos en el suelo, presiona las palmas contra una superficie, y nombra tres cosas que puedas ver. Estás aquí. Este cuerpo está a salvo en este instante.\n\nEstos estados pueden ser sostenidos por un humano formado en cuidados informados por el trauma. Contacta:\n\n%RESOURCE%\n\nSi los síntomas son severos o no te sientes seguro, llama a tu número de emergencia local. Estaré aquí cuando regreses.",
                'ar' => "ما تصفه يبدو كأنّ جهازك العصبيّ يحمل الكثير الآن. لا نحاول التفكير للعبور.\n\nإن استطعت، ضع قدميك على الأرض، اضغط راحتيك على سطحٍ ما، وسمِّ ثلاثة أشياء تراها. أنتَ هنا. هذا الجسد آمنٌ في هذه اللحظة.\n\nمثل هذه الحالات يمكن أن يحتويها إنسانٌ مدرَّبٌ على الرعاية المراعية للصدمة. تواصل:\n\n%RESOURCE%\n\nإن كانت الأعراض شديدة أو لا تشعر بالأمان، اتصل برقم الطوارئ. سأكون هنا حين تعود.",
            ],
            'intoxication_distress' => [
                'en' => "I hear that something has gone wrong with a substance. This is beyond what I can help with in a conversation — you need a human trained for this, now.\n\nCall your local emergency number immediately. If you know the substance and suspect overdose, poison control can guide you.\n\n%RESOURCE%\n\nStay with someone who can watch over you. I'll be here when the moment passes.",
                'fr' => "J'entends que quelque chose a mal tourné avec une substance. Cela dépasse ce que je peux faire dans une conversation — il vous faut un humain formé, maintenant.\n\nAppelez immédiatement le numéro d'urgence local. Si vous connaissez la substance et suspectez un surdosage, un centre antipoison peut vous guider.\n\n%RESOURCE%\n\nRestez avec quelqu'un qui peut veiller sur vous. Je serai là quand le moment passera.",
                'es' => "Escucho que algo ha salido mal con una sustancia. Esto está más allá de lo que puedo hacer en una conversación — necesitas a un humano formado, ahora.\n\nLlama inmediatamente a tu número de emergencia local. Si conoces la sustancia y sospechas sobredosis, un centro de toxicología puede guiarte.\n\n%RESOURCE%\n\nQuédate con alguien que pueda vigilarte. Estaré aquí cuando pase el momento.",
                'ar' => "أسمع أنّ شيئاً قد حدث مع مادّة. هذا يتجاوز ما يمكنني فعله في محادثة — تحتاج إلى إنسانٍ مدرَّب، الآن.\n\nاتصل فوراً برقم الطوارئ المحلّي. إن كنتَ تعرف المادّة وتشكّ بجرعة زائدة، يمكن لمركز السموم إرشادك.\n\n%RESOURCE%\n\nابقَ مع شخصٍ يراقبك. سأكون هنا حين تمرّ اللحظة.",
            ],
            'child_safety' => [
                'en' => "What you're describing involves a child who needs protection right now. This has to go to people trained and empowered to act.\n\nCall your local emergency number immediately if a child is in immediate danger.\n\n%RESOURCE%\n\nIn many countries, a child protection hotline operates alongside emergency services. If you need help finding one, I can help you locate resources, but first: make the call.",
                'fr' => "Ce que vous décrivez concerne un enfant qui a besoin de protection maintenant. Cela doit aller à des personnes formées et habilitées à agir.\n\nAppelez immédiatement le numéro d'urgence local si un enfant est en danger immédiat.\n\n%RESOURCE%\n\nDans de nombreux pays, une ligne de protection de l'enfance fonctionne aux côtés des services d'urgence. Si vous avez besoin d'aide pour en trouver une, je peux vous aider à localiser des ressources, mais d'abord : passez l'appel.",
                'es' => "Lo que describes involucra a un niño que necesita protección ahora. Esto tiene que ir a personas formadas y facultadas para actuar.\n\nLlama inmediatamente a tu número de emergencia local si un niño está en peligro inmediato.\n\n%RESOURCE%\n\nEn muchos países, una línea de protección infantil opera junto a los servicios de emergencia. Si necesitas ayuda para encontrarla, puedo ayudarte a localizar recursos, pero primero: haz la llamada.",
                'ar' => "ما تصفه يتعلّق بطفلٍ يحتاج إلى حماية الآن. هذا يجب أن يذهب إلى أشخاصٍ مدرَّبين ومخوَّلين للتصرّف.\n\nاتصل فوراً برقم الطوارئ المحلّي إن كان الطفل في خطر مباشر.\n\n%RESOURCE%\n\nفي بلدان كثيرة، يعمل خطّ حماية الطفولة إلى جانب خدمات الطوارئ. إن احتجت مساعدة في إيجاده، يمكنني مساعدتك، لكن أوّلاً: قم بالاتّصال.",
            ],
        ];

        // Resource block formatting.
        if ($resource) {
            $resourceBlock = "📞 {$resource['name']}:\n"
                           . "• {$resource['hotline']}\n"
                           . "• " . (
                               $langKey === 'fr' ? "Urgences : {$resource['emergency']}" :
                               ($langKey === 'es' ? "Emergencias: {$resource['emergency']}" :
                               ($langKey === 'ar' ? "الطوارئ: {$resource['emergency']}" :
                               "Emergency: {$resource['emergency']}"))
                           );
        } else {
            $label = $langKey === 'fr' ? "Lignes d'écoute internationales :"
                   : ($langKey === 'es' ? "Líneas de escucha internacionales:"
                   : ($langKey === 'ar' ? "خطوط استماع دوليّة:"
                   : "International helplines:"));
            $resourceBlock = "📞 {$label}\n• {$befrienders}";
        }

        // Select template; default to active_suicidal_ideation copy if category unknown.
        $categoryTemplates = $templates[$category] ?? $templates['active_suicidal_ideation'];
        $template = $categoryTemplates[$langKey] ?? $categoryTemplates['en'];

        return str_replace('%RESOURCE%', $resourceBlock, $template);
    }
}

if (!function_exists('redactTrigger')) {
    /**
     * Redact a matched pattern for audit logging. Keeps a short prefix + length
     * so operators can triage without storing verbatim crisis content.
     */
    function redactTrigger(string $phrase): string {
        $phrase = trim($phrase);
        $len = mb_strlen($phrase);
        if ($len === 0) return '[empty]';
        $prefix = mb_substr($phrase, 0, min(20, $len));
        return "[{$len} chars] " . $prefix . ($len > 20 ? '…' : '');
    }
}
