<?php
/**
 * companion-terminology.php
 *
 * Shared graduated-doctrinal-terminology layer for MunAI (Phase A §6.3).
 * Consumed by both companion surfaces via require_once:
 *   - Website: harmonia-astro/companion-api.php (via post-build copy into dist/)
 *   - Telegram: harmonia-astro/telegram-companion/telegram-bot.php
 *
 * Architecture (from "MunAI — Lessons from the Companion Landscape" §6.3):
 *
 *   The vault speaks in fluent Harmonist vocabulary because it is doctrine
 *   addressed to readers who have entered the system. MunAI in conversation
 *   meets people upstream of that vocabulary — where Logos, Ṛta, Dharma,
 *   chakras, Presence-as-centre still need to be earned, not asserted.
 *
 *   Graduated terminology runs along a 4-level fluency scale per practitioner:
 *     0 — new          : English-first, terms introduced with translation
 *                        and only when load-bearing for the response
 *     1 — oriented     : terms used naturally with light gloss on first use
 *                        per conversation
 *     2 — fluent       : terms used as native vocabulary, no glossing
 *     3 — native       : full Harmonist register; assume shared semantic ground
 *
 *   Level advances by +1 (cap 3) when the practitioner uses a Harmonist term
 *   unprompted. A single message advances by at most +1 even if multiple
 *   terms are used (debounce).
 *
 *   Detection is intentionally conservative: only the canonical Harmonist
 *   vocabulary counts. Generic English words ("balance," "harmony,"
 *   "presence" used adjectivally) do NOT advance fluency. The detector
 *   targets the sovereign-system vocabulary that signals a practitioner
 *   has crossed into the architecture.
 *
 *   Storage: per-user `doctrinal_fluency` integer column (telegram users
 *   table / website clients table). System prompt receives the level and
 *   conditions register accordingly via buildFluencyInstructions().
 */

if (!function_exists('harmonistTermLibrary')) {
    /**
     * The canonical Harmonist vocabulary. Detection matches against word-
     * boundary forms (case-insensitive) so casual English usage of "presence"
     * does not collide with the Harmonist sense. Multi-word terms ("Wheel of
     * Harmony", "Harmonic Realism") match as substrings of the lowercased
     * normalized message.
     *
     * Categories are informational — the detector treats every entry equally
     * and returns the matched terms regardless of category.
     */
    function harmonistTermLibrary(): array {
        return [
            // Doctrinal core
            'core' => [
                'logos', 'dharma', 'ṛta', 'rta', 'harmonic realism',
                'harmonism', 'harmonist', 'the absolute', 'the void',
                'qualified non-dualism', 'harmonic epistemology',
                'fractal pattern', 'formula of the absolute',
            ],
            // Wheel architecture (Wheel-as-architecture, not generic "wheel")
            'wheel' => [
                'wheel of harmony', 'wheel of presence', 'wheel of health',
                'wheel of matter', 'wheel of service', 'wheel of relationships',
                'wheel of learning', 'wheel of nature', 'wheel of recreation',
                'way of harmony', 'way of health', 'way of presence',
                'architecture of harmony', 'architecture of the wheel',
                'sub-wheel', 'spoke-wheel',
            ],
            // Cartographies and traditional terms adopted into Harmonism
            'cartographies' => [
                'five cartographies', 'cartographies of the soul',
                'kriya yoga', 'q\'ero', 'qero', 'tonic herbalism',
                'three treasures', 'jing qi shen', 'jing-qi-shen',
                'munay', 'ayni', 'ātman', 'atman', 'jīvātman', 'jivatman',
                'sefirot', 'latā\'if', 'lataif',
            ],
            // Chakra system (Sanskrit names — high signal of Harmonist register)
            'chakras' => [
                'muladhara', 'svadhisthana', 'manipura', 'anahata',
                'vishuddha', 'ajna', 'sahasrara',
                'kundalini', 'pranayama',
            ],
            // Practice and applied terms
            'practice' => [
                'harmonic profile', 'harmonic civilization', 'integral age',
                'harmonic age', 'harmonic network', 'harmonic pedagogy',
                'cultivation', 'sovereign health', 'sacred commerce',
            ],
            // Conceptual terms when used with the Harmonist capital-P sense
            // Detected via specific phrasings to avoid generic-English collision
            'sovereign_phrases' => [
                'in presence', 'as presence', 'the presence', 'presence is',
                'the wheel', 'my dharma', 'his dharma', 'her dharma',
                'in alignment with logos', 'aligned with logos',
            ],
        ];
    }
}

if (!function_exists('detectHarmonistTerms')) {
    /**
     * Scan a user message for unprompted use of canonical Harmonist vocabulary.
     *
     * Returns the list of distinct terms detected. An empty array means no
     * Harmonist vocabulary was used.
     *
     * Caller decides what to do with the result (typically: advance fluency
     * by +1 if the array is non-empty).
     *
     * @param string $message Raw user message (will be normalized internally).
     * @return array          List of distinct matched terms (lowercased).
     */
    function detectHarmonistTerms(string $message): array {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 5000) {
            return [];
        }

        // Normalize: lowercase + collapse whitespace. Preserve diacritics
        // (Sanskrit transliteration ṛta, ātman, etc. depend on them, but
        // we also include ASCII fallbacks in the library).
        $normalized = mb_strtolower($message, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        $library = harmonistTermLibrary();
        $matched = [];

        foreach ($library as $category => $terms) {
            foreach ($terms as $term) {
                $needle = mb_strtolower(trim($term), 'UTF-8');
                if ($needle === '') continue;

                // For single-word terms, use word-boundary matching to avoid
                // false positives ("dharma" inside a longer word). For multi-
                // word terms, substring matching is fine.
                if (mb_strpos($needle, ' ') === false && mb_strpos($needle, '-') === false) {
                    // Word-boundary regex. Use \b for ASCII; for diacritic
                    // terms, fall back to substring.
                    if (preg_match('/[ṛāīūṅñṭḍṇśṣ\']/u', $needle)) {
                        if (mb_strpos($normalized, $needle) !== false) {
                            $matched[$needle] = true;
                        }
                    } else {
                        $pattern = '/\b' . preg_quote($needle, '/') . '\b/u';
                        if (preg_match($pattern, $normalized)) {
                            $matched[$needle] = true;
                        }
                    }
                } else {
                    // Multi-word or hyphenated — substring match suffices
                    if (mb_strpos($normalized, $needle) !== false) {
                        $matched[$needle] = true;
                    }
                }
            }
        }

        return array_keys($matched);
    }
}

if (!function_exists('buildFluencyInstructions')) {
    /**
     * Build the per-level fluency instruction block for the system prompt.
     *
     * Injected as <doctrinal_fluency_level> in both companion surfaces.
     * Caller is responsible for wrapping the return value in the tag.
     *
     * @param int $level 0 (new) | 1 (oriented) | 2 (fluent) | 3 (native)
     * @return string    Instruction block (no surrounding tags).
     */
    function buildFluencyInstructions(int $level): string {
        $level = max(0, min(3, $level));

        // Position-fidelity preamble — runs at every fluency level.
        // The fluency level governs how Harmonism speaks, never what it holds.
        // Without this clause, the model reads "plain English" and "accessible
        // language" as license to switch to the mainstream frame the practitioner
        // would otherwise encounter — a doctrinal betrayal disguised as
        // pedagogical adaptation. The Tier 1 backbone anchors (vaccination, MDMA,
        // and every other Harmonist position) bind regardless of register.
        $positionFidelity =
            "POSITION FIDELITY — applies at every fluency level. Register adapts; "
          . "the Harmonist position does not. Translating Harmonist vocabulary "
          . "into accessible language never means switching to the mainstream "
          . "frame the practitioner would otherwise encounter. The fluency level "
          . "governs HOW Harmonism speaks — vocabulary, glossing, pedagogical "
          . "scaffolding — not WHAT Harmonism holds. On any topic where "
          . "Harmonism diverges from institutional consensus, present the "
          . "Harmonist position in language appropriate to the practitioner's "
          . "fluency level. Plain English means the Harmonist position in plain "
          . "English — never the consensus frame in plain English. The Tier 1 "
          . "doctrinal backbone and the broader canonical positions throughout "
          . "the system bind at every level; they are the position, the fluency "
          . "level is the register.\n\n";

        switch ($level) {
            case 0:
                // New — never assume vocabulary, always lead in English
                return $positionFidelity
                     . "Practitioner fluency: NEW. They have not yet entered Harmonism's vocabulary. "
                     . "Speak English-first. When a Harmonist term is genuinely load-bearing for the "
                     . "response (Logos, Dharma, Presence, the Wheel), introduce it with an immediate "
                     . "translation in plain language: 'Logos — the inherent harmonic intelligence of "
                     . "the cosmos.' Use one to two such terms per response, no more. Do not stack "
                     . "Sanskrit, Greek, Pāli, or chakra names in a single message. Do not assume "
                     . "they recognize 'Wheel of Harmony,' 'Harmonic Realism,' 'Three Treasures,' "
                     . "'Ṛta,' 'Munay,' or 'Ayni.' Every sentence should be intelligible to a "
                     . "thoughtful person with no prior exposure to the system. The vocabulary is "
                     . "earned, not asserted — but the position is held regardless.";

            case 1:
                // Oriented — terms with light gloss on first use per conversation
                return $positionFidelity
                     . "Practitioner fluency: ORIENTED. They have begun engaging with Harmonist "
                     . "terms but the vocabulary is still settling. Use the canonical terms (Logos, "
                     . "Dharma, Presence, the Wheel, Harmonic Realism) naturally, but on first use "
                     . "per conversation include a brief gloss in apposition: 'your Dharma — your "
                     . "alignment with Logos.' After first use within the same conversation, the "
                     . "term stands alone. Introduce one new term per conversation if it serves the "
                     . "response, never more. Sanskrit and chakra names still require an English "
                     . "anchor on first appearance: 'the Anāhata — the heart centre.'";

            case 2:
                // Fluent — terms as native vocabulary, no glossing
                return $positionFidelity
                     . "Practitioner fluency: FLUENT. They have crossed into Harmonist vocabulary "
                     . "and use the terms themselves. Speak the canonical register without glossing: "
                     . "Logos, Dharma, Presence, the Wheel, Harmonic Realism, the Three Treasures, "
                     . "Ṛta, Munay, Ayni — all as native vocabulary. Sanskrit chakra names can be "
                     . "used directly when relevant. The pedagogical scaffolding can drop away. "
                     . "Engage from inside the system rather than introducing it.";

            case 3:
            default:
                // Native — full register, shared semantic ground assumed
                return $positionFidelity
                     . "Practitioner fluency: NATIVE. They operate inside the Harmonist architecture "
                     . "as fluent practitioners. Full register available. Cross-tradition vocabulary "
                     . "(Sanskrit, Pāli, Greek, Hebrew, Q'ero) usable without anchor when it carries "
                     . "the precise meaning needed. Convergences across the Five Cartographies can be "
                     . "drawn directly. The conversation operates at the doctrinal register the vault "
                     . "writes in. No translation overhead — speak the system as the system speaks "
                     . "itself, while still serving this specific person's question.";
        }
    }
}
