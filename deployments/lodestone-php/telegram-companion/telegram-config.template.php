<?php
/**
 * Telegram Companion Configuration — TEMPLATE
 *
 * Copy this file to /home/SERVER_USER/.telegram-config.php on the server
 * and fill in your API keys.
 *
 * IMPORTANT: This file must live OUTSIDE public_html to keep secrets secure.
 *
 * To deploy:
 *   scp -P SSH_PORT telegram-config.template.php SERVER_USER@SERVER_HOST:/home/SERVER_USER/.telegram-config.php
 *   Then SSH in and edit:
 *   ssh -p SSH_PORT SERVER_USER@SERVER_HOST
 *   nano /home/SERVER_USER/.telegram-config.php
 */

// Telegram Bot Token (from @BotFather)
define('TELEGRAM_BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE');

// Telegram Bot Username (without @, e.g. 'HarmonAIBot')
define('TELEGRAM_BOT_USERNAME', 'HarmonAIBot');

// Anthropic API Key (same as companion-config.php or a separate one)
define('ANTHROPIC_API_KEY', 'sk-ant-api03-YOUR-KEY-HERE');

// OpenAI API Key (for embedding-based semantic search in RAG)
// Uses text-embedding-3-small — ~$0.02/1M tokens
// If not set, RAG falls back to FTS5 keyword search only
define('OPENAI_API_KEY', 'sk-YOUR-OPENAI-KEY-HERE');

// xAI API Key (for Lodestone's spoken replies — see companion-voice.php and /voice)
// Grok TTS, the same engine that narrates the articles on harmonism.io.
// ~$15 per 1M characters; a spoken reply is typically 400-900 characters.
// Tier B secret with bounded spend (Decision #485). Optional: without it,
// every reply stays text-only and /voice reports that no audio is available.
define('XAI_API_KEY', 'xai-YOUR-KEY-HERE');

// Claude Model
define('COMPANION_MODEL', 'claude-opus-5-5');

// ===== Model provider (llm-gateway.php) =====
// 'anthropic' keeps every call on api.anthropic.com with the key above.
// 'openrouter' sends every call to LLM_BASE_URL's /chat/completions instead;
// call sites keep their Anthropic request/response shape (translated in
// llm-gateway.php). Must match between .companion-config.php and
// .telegram-config.php. Model ids are pinned versions, never "~…-latest".
define('LLM_PROVIDER', 'openrouter');
define('LLM_BASE_URL', 'https://openrouter.ai/api/v1');
define('LLM_API_KEY', 'sk-or-v1-YOUR-OPENROUTER-KEY-HERE');
define('LLM_MODEL', 'deepseek/deepseek-v4.1-flash-1017');      // Opus tier (deep replies, learning calls)
define('LLM_MODEL_FAST', 'deepseek/deepseek-v4.1-flash-1017'); // Haiku tier (classifier, simple replies)
define('LLM_MODEL_VISION', 'deepseek/deepseek-v4-flash-vision-exp'); // any turn carrying an image
define('LLM_REASONING_EFFORT', 'none');                        // '' omits the field if a model rejects 'none'
define('LLM_PROVIDER_ONLY', []);                               // e.g. ['deepinfra', 'parasail'] to pin hosts
define('LLM_DATA_COLLECTION', 'deny');                         // exclude hosts that store or train on prompts
