<?php
/**
 * llm-models.php — the models a practitioner may choose for hosted Lodestone.
 *
 * The list is closed and lives in code, not in config: a model enters only
 * after it has passed scripts/lodestone-fidelity-suite.py on the backbone version
 * named in 'tested', and only if its weights are published (Decisions #1325,
 * #1329). Size is no bar: a model too large for any owned machine may be listed
 * (#1333). No closed model is listed, whatever its score. A model is left off
 * when reasoning it cannot switch off makes it drift, when its calls are
 * unreliable, or when its cost is out of proportion (#1333).
 *
 * Keys are OpenRouter model ids, pinned versions, never "~…-latest" aliases.
 *   label     shown to the practitioner
 *   maker     the lab, named because its hand travels with the weights
 *   licence   the weights' licence
 *   local     smallest owned machine that holds it at four bits
 *   reasoning 'none' sends reasoning.effort=none; 'low'/'medium'/'high' send
 *             that effort; '' omits the field, for models whose providers make
 *             reasoning mandatory. The Haiku tier never deliberates (llm-gateway.php)
 *   min_tokens  floor for max_tokens when the model reasons before answering
 *   default   the model used when the practitioner has chosen none
 *
 * When the backbone changes, re-run the suite on every entry and update
 * 'tested'; an entry that fails comes off the list in the same commit.
 *
 * The desktop app carries the same list as RECOMMENDED in
 * lodestone/desktop/src/remote.js (#1341); change both in the same commit.
 */

if (!function_exists('llm_model_catalog')) {
    function llm_model_catalog(): array {
        return [
            // The October 17 snapshot replaces the September one (#1476), with reasoning
            // at medium: it asked the direct question on French passive ideation 8/8
            // against 4/8 with reasoning off. The floor keeps the reasoning from eating
            // the answer. The September id stays usable under a practitioner's own key.
            'deepseek/deepseek-v4.1-flash-1017' => [
                'label' => 'DeepSeek V4.1 Flash', 'maker' => 'DeepSeek', 'licence' => 'MIT',
                'local' => '512 GB', 'reasoning' => 'medium', 'min_tokens' => 4000,
                'tested' => '2026-10-24, backbone 2.8', 'default' => true,
            ],
            // DeepSeek V4 Flash dropped October 15, 2026 (#1330): V4.1 Flash supersedes it
            // on the hosted list; V4 Flash stays the 256 GB local recommendation.
            // One model per region from October 26, 2026 (#1511): China, the United
            // States, Europe. GLM-5.3 Flash and Qwen 3.5 397B left the list (a second and
            // third Chinese model); a saved choice of either falls back to the default.
            // Nemotron is also the tested reserve should DeepSeek fail.
            'nvidia/nemotron-3-ultra-550b-a55b' => [
                'label' => 'Nemotron 3 Ultra', 'maker' => 'NVIDIA', 'licence' => 'OpenMDW 1.1',
                'local' => '512 GB', 'reasoning' => 'none', 'min_tokens' => 0,
                'tested' => '2026-10-15, backbone 2.8', 'default' => false,
            ],
            // Europe's place: Mistral Large 4 (released October 6, 2026; weights promised,
            // not yet published) joins when its weights are out and it passes the suite.
            // Light models (Mistral Small 4, Gemma 4 26B) left on October 15 (#1331).
        ];
    }
}

if (!function_exists('llm_model_valid')) {
    function llm_model_valid($id): bool {
        return is_string($id) && isset(llm_model_catalog()[$id]);
    }
}

if (!function_exists('llm_model_default')) {
    function llm_model_default(): string {
        // The server's configured main model is the default when it is on the list.
        if (defined('LLM_MODEL') && llm_model_valid(LLM_MODEL)) return LLM_MODEL;
        foreach (llm_model_catalog() as $id => $m) {
            if (!empty($m['default'])) return $id;
        }
        return array_key_first(llm_model_catalog());
    }
}

/** The list as the website and the Telegram /model command present it. */
if (!function_exists('llm_models_public')) {
    function llm_models_public(): array {
        $out = [];
        foreach (llm_model_catalog() as $id => $m) {
            $out[] = [
                'id' => $id, 'label' => $m['label'], 'maker' => $m['maker'],
                'licence' => $m['licence'], 'local' => $m['local'],
                'default' => $id === llm_model_default(),
            ];
        }
        return $out;
    }
}
