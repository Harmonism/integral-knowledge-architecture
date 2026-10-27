<?php
/**
 * llm-gateway.php — provider switch for every Lodestone model call.
 *
 * Every Lodestone call site builds an Anthropic Messages request and parses an
 * Anthropic Messages response (JSON or SSE). This file keeps that contract
 * and changes only where the request goes. With LLM_PROVIDER = 'anthropic'
 * (the default when the constant is absent) the three wrappers are plain
 * curl and nothing changes. With 'openrouter' (or any OpenAI-compatible
 * endpoint: Venice, DeepInfra, a local llama.cpp / Ollama server), the
 * request is translated to /chat/completions on the way out and the response
 * is translated back to Anthropic shape on the way in, so no parser changes.
 *
 * Call-site usage (replaces curl_init / curl_setopt_array / curl_exec only;
 * curl_getinfo, curl_errno, curl_error and curl_close stay as they were):
 *
 *     $ch = llm_curl_init();
 *     llm_curl_setopt_array($ch, [ ... same options as before ... ]);
 *     $result = llm_curl_exec($ch);
 *
 * Config constants (server config files: .companion-config.php and
 * .telegram-config.php — both surfaces must agree):
 *
 *     LLM_PROVIDER          'anthropic' | 'openrouter'          default 'anthropic'
 *     LLM_BASE_URL          chat-completions base URL           default 'https://openrouter.ai/api/v1'
 *     LLM_API_KEY           bearer key for LLM_BASE_URL         (required when not anthropic)
 *     LLM_MODEL             model for the Opus tier             default 'deepseek/deepseek-v4.1-flash-1017' (#1476)
 *     LLM_MODEL_FAST        model for the Haiku tier            default LLM_MODEL
 *     LLM_MODEL_VISION      model used when a turn carries an image
 *                                                               default 'deepseek/deepseek-v4-flash-vision-exp'
 *     LLM_REASONING_EFFORT  OpenRouter reasoning.effort; '' omits the field
 *                                                               default 'none' (answer without deliberating)
 *     LLM_PROVIDER_ONLY     array of OpenRouter provider slugs to pin; [] = OpenRouter chooses
 *                                                               default []
 *     LLM_DATA_COLLECTION   'deny' | 'allow'                    default 'deny'
 *
 * Model ids are pinned versions, never "~…-latest" aliases: an alias moves
 * under you, which is the failure this switch exists to escape.
 */

// Read lazily, at call time, so it does not matter whether this file is
// required before or after the server config that defines the constants.
function llm_cfg(string $name) {
    if (defined($name)) return constant($name);
    switch ($name) {
        case 'LLM_PROVIDER':         return 'anthropic';
        case 'LLM_BASE_URL':         return 'https://openrouter.ai/api/v1';
        case 'LLM_API_KEY':          return '';
        case 'LLM_MODEL':            return 'deepseek/deepseek-v4.1-flash-1017';   // #1476
        case 'LLM_MODEL_FAST':       return llm_cfg('LLM_MODEL');
        case 'LLM_MODEL_VISION':     return 'deepseek/deepseek-v4-flash-vision-exp';
        case 'LLM_REASONING_EFFORT': return 'none';
        case 'LLM_PROVIDER_ONLY':    return [];
        case 'LLM_DATA_COLLECTION':  return 'deny';
    }
    return null;
}

require_once __DIR__ . '/llm-models.php';

if (!defined('LLM_ANTHROPIC_URL')) define('LLM_ANTHROPIC_URL', 'https://api.anthropic.com/v1/messages');

/**
 * The practitioner's own model choice for this request (Decision #1329).
 * Set by companion-api.php from the request and by process-queue.php from the
 * Telegram user's setting; cleared after the reply so learning and outreach
 * calls stay on the server's models. Only ids on llm-models.php's list are
 * accepted; anything else clears the choice. Applies to the main (Opus) tier
 * only: the classifier and slim replies stay on LLM_MODEL_FAST.
 */
function llm_set_user_model($id): bool {
    $GLOBALS['__llm_user_model'] = (is_string($id) && function_exists('llm_model_valid') && llm_model_valid($id)) ? $id : null;
    return $GLOBALS['__llm_user_model'] !== null;
}

function llm_user_model(): ?string {
    return $GLOBALS['__llm_user_model'] ?? null;
}

function llm_is_translating(): bool {
    return llm_cfg('LLM_PROVIDER') !== 'anthropic';
}

/** Per-handle state for translated calls (stream buffer, non-stream flag). */
function &llm_state($ch): array {
    static $states = [];
    $key = is_object($ch) ? spl_object_id($ch) : (int)$ch;
    if (!isset($states[$key])) $states[$key] = [];
    return $states[$key];
}

function llm_curl_init() {
    if (!llm_is_translating()) return curl_init(LLM_ANTHROPIC_URL);
    $ch = curl_init(rtrim(llm_cfg('LLM_BASE_URL'), '/') . '/chat/completions');
    $st = &llm_state($ch);
    $st = ['translate' => true, 'stream' => false, 'buffer' => '', 'started' => false,
           'finish' => null, 'usage' => null, 'model' => null];
    return $ch;
}

function llm_curl_setopt_array($ch, array $opts): bool {
    if (!llm_is_translating()) return curl_setopt_array($ch, $opts);
    $st = &llm_state($ch);

    $anth = json_decode((string)($opts[CURLOPT_POSTFIELDS] ?? ''), true) ?: [];
    $oai  = llm_request_to_openai($anth);
    $st['stream'] = !empty($oai['stream']);
    $st['model']  = $oai['model'];
    $opts[CURLOPT_POSTFIELDS] = json_encode($oai);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . llm_cfg('LLM_API_KEY'),
        'HTTP-Referer: https://harmonism.io',
        'X-Title: Lodestone',
    ];
    if ($st['stream']) $headers[] = 'Accept: text/event-stream';
    $opts[CURLOPT_HTTPHEADER] = $headers;

    if ($st['stream'] && isset($opts[CURLOPT_WRITEFUNCTION])) {
        $inner = $opts[CURLOPT_WRITEFUNCTION];
        $st['inner'] = $inner;
        $opts[CURLOPT_WRITEFUNCTION] = function ($h, $chunk) use ($ch, $inner) {
            $out = llm_translate_stream_chunk($ch, $chunk);
            if ($out !== '') $inner($h, $out);
            return strlen($chunk);
        };
    }
    return curl_setopt_array($ch, $opts);
}

function llm_curl_exec($ch) {
    $res = curl_exec($ch);
    if (!llm_is_translating()) return $res;
    $st = &llm_state($ch);
    if ($st['stream']) {
        // Flush whatever the stream left unterminated, then close the message.
        $tail = llm_translate_stream_chunk($ch, "\n\n", true);
        if ($tail !== '' && isset($st['inner'])) ($st['inner'])($ch, $tail);
        return $res;
    }
    if (!is_string($res)) return $res;
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code !== 200) return $res;  // call sites log the provider's error body as is
    return llm_response_to_anthropic($res, $st['model']);
}

// ─── Request: Anthropic Messages → OpenAI chat/completions ──────────────────

function llm_text_of($content): string {
    if (is_string($content)) return $content;
    $parts = [];
    foreach ((array)$content as $b) {
        if (($b['type'] ?? '') === 'text') $parts[] = (string)($b['text'] ?? '');
    }
    return implode("\n\n", $parts);
}

function llm_request_to_openai(array $a): array {
    $hasImage = false;
    $msgs = [];
    $system = llm_text_of($a['system'] ?? '');
    if ($system !== '') $msgs[] = ['role' => 'system', 'content' => $system];

    foreach (($a['messages'] ?? []) as $m) {
        $c = $m['content'] ?? '';
        if (is_array($c)) {
            $parts = [];
            foreach ($c as $b) {
                $t = $b['type'] ?? '';
                if ($t === 'text') {
                    $parts[] = ['type' => 'text', 'text' => (string)($b['text'] ?? '')];
                } elseif ($t === 'image' && ($b['source']['type'] ?? '') === 'base64') {
                    $hasImage = true;
                    $parts[] = ['type' => 'image_url', 'image_url' => [
                        'url' => 'data:' . $b['source']['media_type'] . ';base64,' . $b['source']['data'],
                    ]];
                }
                // thinking / tool blocks: Lodestone sends none; dropped if they appear.
            }
            $c = $hasImage ? $parts : llm_text_of($c);
        }
        $msgs[] = ['role' => $m['role'] ?? 'user', 'content' => $c];
    }

    $tierModel = (string)($a['model'] ?? '');
    $isFastTier = stripos($tierModel, 'haiku') !== false;
    $model = $hasImage ? llm_cfg('LLM_MODEL_VISION')
           : ($isFastTier ? llm_cfg('LLM_MODEL_FAST') : (llm_user_model() ?? llm_cfg('LLM_MODEL')));
    $entry = function_exists('llm_model_catalog') ? (llm_model_catalog()[$model] ?? null) : null;

    $o = [
        'model'      => $model,
        'messages'   => $msgs,
        'max_tokens' => (int)($a['max_tokens'] ?? 2048),
    ];
    if (isset($a['temperature'])) $o['temperature'] = $a['temperature'];
    if (!empty($a['stream'])) {
        $o['stream'] = true;
        $o['stream_options'] = ['include_usage' => true];
    }
    // A listed model carries its own reasoning setting and token floor (some
    // providers make reasoning mandatory and spend the budget on it).
    $effort = $entry !== null ? (string)$entry['reasoning'] : (string)llm_cfg('LLM_REASONING_EFFORT');
    // The classifier and slim replies stay fast: a listed effort is lowered to none
    // on the Haiku tier ('' is left alone: those providers make reasoning mandatory).
    if ($isFastTier && $effort !== '' && $effort !== 'none') $effort = 'none';
    $o['reasoning'] = $effort !== '' ? ['effort' => $effort, 'exclude' => true] : ['exclude' => true];
    if ($entry !== null && !empty($entry['min_tokens'])) $o['max_tokens'] = max($o['max_tokens'], (int)$entry['min_tokens']);

    $provider = ['data_collection' => llm_cfg('LLM_DATA_COLLECTION')];
    $only = (array)llm_cfg('LLM_PROVIDER_ONLY');
    if ($only) $provider['only'] = array_values($only);
    $o['provider'] = $provider;
    // Anthropic-only fields (output_config, thinking, cache_control, metadata) are not carried.
    return $o;
}

// ─── Response: OpenAI → Anthropic Messages ───────────────────────────────────

function llm_stop_reason(?string $finish): ?string {
    if ($finish === null) return null;
    return ['stop' => 'end_turn', 'length' => 'max_tokens'][$finish] ?? 'end_turn';
}

function llm_usage(?array $u): array {
    $u = $u ?? [];
    return [
        'input_tokens'            => $u['prompt_tokens'] ?? null,
        'output_tokens'           => $u['completion_tokens'] ?? null,
        'cache_read_input_tokens' => $u['prompt_tokens_details']['cached_tokens'] ?? null,
        'cache_creation_input_tokens' => null,
    ];
}

function llm_response_to_anthropic(string $raw, ?string $model): string {
    $d = json_decode($raw, true);
    if (!is_array($d) || !isset($d['choices'][0])) return $raw;
    $choice = $d['choices'][0];
    $text = (string)($choice['message']['content'] ?? '');
    return json_encode([
        'id'          => $d['id'] ?? null,
        'type'        => 'message',
        'role'        => 'assistant',
        'model'       => $d['model'] ?? $model,
        'content'     => [['type' => 'text', 'text' => $text]],
        'stop_reason' => llm_stop_reason($choice['finish_reason'] ?? 'stop'),
        'usage'       => llm_usage($d['usage'] ?? null),
    ]);
}

function llm_sse(string $type, array $payload): string {
    return "event: {$type}\ndata: " . json_encode(['type' => $type] + $payload) . "\n\n";
}

/**
 * Translate a raw OpenAI SSE chunk into Anthropic SSE events. Buffers partial
 * lines across chunks. On [DONE] (or $final) emits the closing events once.
 */
function llm_translate_stream_chunk($ch, string $chunk, bool $final = false): string {
    $st = &llm_state($ch);
    $out = '';
    $st['buffer'] .= str_replace("\r\n", "\n", $chunk);

    $start = function () use (&$st, &$out) {
        if ($st['started']) return;
        $st['started'] = true;
        $out .= llm_sse('message_start', ['message' => [
            'id' => null, 'type' => 'message', 'role' => 'assistant', 'model' => $st['model'],
            'content' => [], 'stop_reason' => null,
            'usage' => ['input_tokens' => null, 'output_tokens' => 0],
        ]]);
        $out .= llm_sse('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
    };
    $close = function () use (&$st, &$out, $start) {
        if (!empty($st['closed'])) return;
        $start();
        $st['closed'] = true;
        $out .= llm_sse('content_block_stop', ['index' => 0]);
        $out .= llm_sse('message_delta', [
            'delta' => ['stop_reason' => llm_stop_reason($st['finish'] ?? 'stop')],
            'usage' => llm_usage($st['usage']),
        ]);
        $out .= llm_sse('message_stop', []);
    };

    while (($nl = strpos($st['buffer'], "\n")) !== false) {
        $line = rtrim(substr($st['buffer'], 0, $nl));
        $st['buffer'] = substr($st['buffer'], $nl + 1);
        if ($line === '' || $line[0] === ':') continue;      // blank or OpenRouter keep-alive comment
        if (strncmp($line, 'data:', 5) !== 0) {
            // A non-200 reply arrives as a plain JSON error body, not SSE.
            $j = json_decode($line, true);
            if (is_array($j) && isset($j['error'])) {
                $out .= llm_sse('error', ['error' => [
                    'type' => 'api_error',
                    'message' => (string)($j['error']['message'] ?? 'provider error'),
                ]]);
            }
            continue;
        }
        $data = ltrim(substr($line, 5));
        if ($data === '[DONE]') { $close(); continue; }
        $j = json_decode($data, true);
        if (!is_array($j)) continue;

        if (isset($j['error'])) {
            $out .= llm_sse('error', ['error' => [
                'type' => 'api_error',
                'message' => (string)($j['error']['message'] ?? 'provider stream error'),
            ]]);
            continue;
        }
        if (isset($j['usage'])) $st['usage'] = $j['usage'];
        if (!empty($j['model'])) $st['model'] = $j['model'];
        $choice = $j['choices'][0] ?? null;
        if (!$choice) continue;
        $text = $choice['delta']['content'] ?? '';
        if ($text !== '' && $text !== null) {
            $start();
            $out .= llm_sse('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]]);
        }
        if (!empty($choice['finish_reason'])) $st['finish'] = $choice['finish_reason'];
    }
    if ($final && $st['started']) $close();
    return $out;
}
