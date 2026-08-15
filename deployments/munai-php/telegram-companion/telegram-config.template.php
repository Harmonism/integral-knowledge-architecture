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

// Claude Model
define('COMPANION_MODEL', 'claude-opus-4-7');
