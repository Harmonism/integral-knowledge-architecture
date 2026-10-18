<?php
/**
 * companion-bodily-openness.php
 *
 * Shared graduated bodily-register openness layer for Lodestone.
 * Sibling to companion-terminology.php (graduated doctrinal fluency).
 *
 * Consumed by both companion surfaces via require_once:
 *   - Website:  harmonia-astro/companion-api.php   (via post-build copy into dist/)
 *   - Telegram: harmonia-astro/telegram-companion/telegram-bot.php
 *
 * Architecture:
 *
 *   Practitioners arrive at Lodestone with different degrees of comfort
 *   discussing the body, sexuality, and physical intimacy. The directness
 *   that lands as honest care for one practitioner — "some sex without
 *   the household pressing in" — lands as presumptuous register-violation
 *   for another who hasn't yet opened that door. The corrective is not
 *   euphemism ("intimacy" as therapy-speak softener — exactly what
 *   Harmonism cuts against) but calibration: Lodestone's bodily-register
 *   vocabulary tracks the practitioner's demonstrated openness, not a
 *   fixed default.
 *
 *   Four levels per practitioner:
 *     0 — closed    : never raised the bodily / sexual register. Default.
 *                     Lodestone does NOT enumerate sexual content as an option
 *                     and does NOT use direct sexual vocabulary. Relational
 *                     questions framed in non-bodily terms.
 *     1 — abstract  : raised body / intimacy / marriage in relational
 *                     register but never used direct sexual vocabulary.
 *                     Lodestone can reference the bodily dimension at the
 *                     practitioner's register but does not escalate to
 *                     explicit sexual terms unprompted.
 *     2 — direct    : used direct sexual vocabulary at least once. Lodestone
 *                     can name sex / sexuality / libido directly when
 *                     contextually relevant.
 *     3 — domain    : substantive engagement — sexuality discussed as a
 *                     domain of practice, named difficulty, or named
 *                     aspiration. Lodestone engages the sexual register with
 *                     the same fluency as any other Wheel domain.
 *
 *   Monotonic: level cannot regress. A practitioner who once opened the
 *   register stays open; a quiet stretch is not closure.
 *
 *   The detector returns a SUGGESTED FLOOR (the highest level this single
 *   message warrants). Caller compares against current level and bumps
 *   only if floor > current — never decrements.
 *
 *   Storage: per-user `bodily_openness` integer column (telegram users
 *   table / website clients table). System prompt receives the level and
 *   conditions register via buildBodilyOpennessInstructions().
 *
 *   Sibling to but distinct from doctrinal fluency: doctrinal fluency
 *   tracks Harmonist vocabulary; bodily openness tracks bodily / sexual
 *   register. Orthogonal axes.
 */

if (!function_exists('bodilyOpennessLibrary')) {
    /**
     * Three pattern tiers. Each tier is a list of patterns that, when
     * matched, suggest the corresponding floor level.
     *
     * Patterns matched against the trimmed, lowercased, whitespace-collapsed
     * message. Patterns starting with '\b' are word-boundary regex; the rest
     * are substring matches. Detection is intentionally conservative — false
     * positives at tier 1+ are worse than false negatives because the level
     * does not regress once stamped.
     */
    function bodilyOpennessLibrary(): array {
        return [
            // ─── Tier 3 → level 3 (domain) ───
            // Sexuality named as practice, named difficulty, or named
            // aspiration. Substantive engagement at the level the practitioner
            // is actively working with.
            'tier3_substantive' => [
                // Sexuality as practice / cultivation
                'sexual practice', 'sexual cultivation', 'sexual energy work',
                'sexual sovereignty', 'sexual integration', 'sexual healing',
                'sexual continence', 'sexual transmutation', 'sexual alchemy',
                'tantric practice', 'tantric sex',
                'white tantra', 'red tantra',
                'sacred sexuality', 'conscious sexuality',
                'jing retention', 'semen retention', 'brahmacharya',
                'ojas cultivation',
                // Named difficulty
                'sexual dysfunction', 'erectile dysfunction',
                'low libido', 'mismatched libido', 'mismatched libidos',
                'libido mismatch',
                'dead bedroom', 'sexless marriage', 'sexless relationship',
                'our sex life', 'my sex life', 'their sex life',
                'sexual difficulty', 'sexual difficulties',
                'sexual problems', 'sexual issue', 'sexual issues',
                // Named aspiration / depth
                'reclaiming sexuality', 'reclaiming my sexuality',
                'deeper sexual', 'sexual depth', 'sexual longing',
            ],

            // ─── Tier 2 → level 2 (direct) ───
            // Direct sexual vocabulary used in any context. A single
            // occurrence advances the practitioner past the threshold.
            // Word-boundary regex on single words to avoid false positives
            // (sex / Essex / Sussex; arousal / carousal).
            'tier2_direct' => [
                '\bsex\b', '\bsexual\b', '\bsexuality\b', '\bsexually\b',
                '\bsexier\b', '\bsexy\b',
                '\blibido\b', '\blibidinal\b',
                '\berotic\b', '\beroticism\b', '\berotically\b',
                '\borgasm\b', '\borgasms\b', '\borgasmic\b',
                '\bintercourse\b', '\bcoitus\b',
                '\bmasturbation\b', '\bmasturbate\b', '\bmasturbating\b',
                '\bforeplay\b', '\bafterglow\b',
                '\bclimax\b', '\bclimaxing\b', '\bclimaxed\b',
                '\bejaculation\b', '\bejaculate\b',
                '\barousal\b', '\baroused\b',
                // Direct phrases (no word boundary needed)
                'making love', 'make love', 'made love',
                'lovemaking',
                'sleep together', 'slept together',
                'in bed together', 'in bed with my',
            ],

            // ─── Tier 1 → level 1 (abstract) ───
            // Bodily / relational register raised without direct sexual
            // vocabulary. Conservative — only clearly couple-context bodily
            // phrases. Generic "body" or "intimacy" alone do NOT fire (they
            // appear constantly in Health-pillar contexts and friendship
            // contexts where the bodily-couple register is not the subject).
            'tier1_abstract' => [
                'physical intimacy', 'physical affection',
                'physical closeness', 'physical connection',
                'physical relationship', 'physical side',
                'intimate connection', 'intimate moments',
                'marital intimacy', 'romantic intimacy',
                "we don't touch", 'haven\'t touched in',
                'no physical', 'lost the physical',
                'passionless',
                'romantic life', 'romantic spark',
                'spark in our', 'spark between us',
                'desire for each other', 'desire each other',
                'attraction to each other', 'attracted to each other',
                'energy between us', 'erotic charge',
                'in the bedroom', 'in our bedroom',
            ],
        ];
    }
}

if (!function_exists('detectBodilyOpenness')) {
    /**
     * Scan a single message and return the suggested FLOOR level (0–3).
     *
     * Returns the highest tier matched. 0 means no bodily-register signal
     * was detected. Caller compares against current level and bumps only
     * if floor > current (monotonic — level never regresses).
     *
     * Multiple distinct tier-2 matches in a single message escalate to
     * level 3 — repeated direct vocabulary in one breath is itself a
     * substantive-register signal.
     *
     * @param string $message Raw user message.
     * @return int            Suggested floor 0 | 1 | 2 | 3.
     */
    function detectBodilyOpenness(string $message): int {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 10000) {
            return 0;
        }

        $normalized = mb_strtolower($message, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        $library = bodilyOpennessLibrary();

        // Tier 3 — any match returns immediately
        foreach ($library['tier3_substantive'] as $needle) {
            if (mb_strpos($normalized, $needle) !== false) {
                return 3;
            }
        }

        // Tier 2 — count distinct matches across the list
        $tier2Hits = 0;
        foreach ($library['tier2_direct'] as $pattern) {
            if (str_starts_with($pattern, '\\b')) {
                if (preg_match('/' . $pattern . '/u', $normalized)) {
                    $tier2Hits++;
                }
            } else {
                if (mb_strpos($normalized, $pattern) !== false) {
                    $tier2Hits++;
                }
            }
            if ($tier2Hits >= 2) {
                return 3;
            }
        }
        if ($tier2Hits >= 1) {
            return 2;
        }

        // Tier 1 — any match
        foreach ($library['tier1_abstract'] as $needle) {
            if (mb_strpos($normalized, $needle) !== false) {
                return 1;
            }
        }

        return 0;
    }
}

if (!function_exists('buildBodilyOpennessInstructions')) {
    /**
     * Per-level system-prompt block. Caller wraps in
     * <bodily_openness_level value="N"> ... </bodily_openness_level>.
     *
     * @param int $level 0 (closed) | 1 (abstract) | 2 (direct) | 3 (domain)
     * @return string    Instruction block (no surrounding tags).
     */
    function buildBodilyOpennessInstructions(int $level): string {
        $level = max(0, min(3, $level));

        switch ($level) {
            case 0:
                // Closed — practitioner has not raised the bodily register
                return "Bodily-register openness: CLOSED. The practitioner has not raised "
                     . "sexuality, the body in coupled context, or physical intimacy in "
                     . "this relationship. Do NOT enumerate sex, sexual connection, or "
                     . "physical intimacy as a possible intention, gap, or outcome — even "
                     . "when they would naturally fit the topic (couple trips, marital "
                     . "reconnection, Health pillar, Relationships pillar). Frame "
                     . "relational questions in non-bodily terms: shared time, real "
                     . "conversation, shared silence, presence with each other, what each "
                     . "of you needs from this. Let the practitioner name the bodily "
                     . "dimension first if it is part of the picture. Once they have, "
                     . "future exchanges adjust register; until they have, the door stays "
                     . "where they left it. This is calibration, not prudishness — "
                     . "Harmonism honors the body without presuming to name it for "
                     . "someone who hasn't.";

            case 1:
                // Abstract — bodily-relational register raised without explicit terms
                return "Bodily-register openness: ABSTRACT. The practitioner has raised the "
                     . "bodily / relational dimension in non-explicit terms (physical "
                     . "intimacy, marital closeness, the body in coupled context). The "
                     . "door is open at the abstract level. You can reference the physical "
                     . "or bodily dimension of partnership in the register they used — "
                     . "'the physical dimension,' 'physical intimacy,' 'how you are with "
                     . "each other in the body' are available. Do NOT escalate to direct "
                     . "sexual vocabulary unprompted (sex, sexual, libido, lovemaking). "
                     . "Mirror their register — meet them where they are, advance only "
                     . "when they advance.";

            case 2:
                // Direct — practitioner has used direct sexual vocabulary
                return "Bodily-register openness: DIRECT. The practitioner has used direct "
                     . "sexual vocabulary in conversation. You can name sex, sexuality, "
                     . "sexual connection, and the bodily register directly when "
                     . "contextually relevant — without therapy-speak softening (don't "
                     . "code 'intimacy' for sex when sex is what's being discussed). "
                     . "Honest naming of the body is part of how Harmonism speaks. Do not "
                     . "make every relational question about sex, but do not circumlocute "
                     . "when sex is the subject.";

            case 3:
            default:
                // Domain — substantive engagement, sexuality as Wheel domain
                return "Bodily-register openness: DOMAIN. The practitioner engages sexuality "
                     . "as a domain of practice, named difficulty, or named aspiration. "
                     . "Sexuality is available as a Wheel domain in the same way Health, "
                     . "Service, or Relationships is — substantively, without "
                     . "throat-clearing, with the same density and specificity Harmonism "
                     . "brings to any cultivation register. Tantric, Daoist "
                     . "(jing / ojas / sexual transmutation), and shamanic-bodily "
                     . "registers can be drawn on directly when relevant. The conversation "
                     . "can include desire, libido, sexual energy, sexual difficulty, and "
                     . "sexual cultivation as substantive subject matter, not subtext.";
        }
    }
}
