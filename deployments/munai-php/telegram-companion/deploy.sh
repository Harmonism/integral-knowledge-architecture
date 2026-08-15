#!/bin/bash
#
# Deploy Telegram Companion to Hostinger
#
# Usage:
#   ./deploy.sh                    — Full deploy (index vault + upload files + set webhook)
#   ./deploy.sh --upload           — Upload files; auto-reindexes first if the vault
#                                    has changed since the last index (staleness gate)
#   ./deploy.sh --upload --no-index — Upload the existing index as-is (skip the gate)
#   ./deploy.sh --index            — Re-index vault only (skip upload)
#   ./deploy.sh --webhook          — Set/update Telegram webhook only
#
# RAG-freshness guarantee: --upload will never ship a stale index. If any indexable
# vault .md (outside Internal/) or any translation .md is newer than vault-index.db,
# the upload path runs --index first. This binds RAG freshness to the deploy seam so
# MunAI cannot silently transmit superseded doctrine (replaces the retired
# companion-knowledge-drift scheduled task — Decision #758-sibling, May 2026).
#
# Prerequisites:
#   1. Create bot via @BotFather on Telegram
#   2. Copy telegram-config.template.php to server as .telegram-config.php
#   3. Fill in API keys in server config
#   4. pip install pyyaml (for vault indexer)

set -e

# ===== Configuration =====
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VAULT_PATH="${SCRIPT_DIR}/../../Vault/Harmonia"
TRANSLATIONS_PATH="${SCRIPT_DIR}/../i18n/translations"
SERVER_USER="SERVER_USER"
SERVER_HOST="SERVER_HOST"
SERVER_PORT="SSH_PORT"
SERVER_PATH="domains/harmonism.io/public_html/telegram-companion"

# SSH connection multiplexing — one password prompt for the entire deploy
CONTROL_SOCK="/tmp/.ssh-deploy-tg"
SSH_OPTS="-o ControlMaster=auto -o ControlPath=${CONTROL_SOCK} -o ControlPersist=120"
SSH_CMD="ssh -p ${SERVER_PORT} ${SSH_OPTS} ${SERVER_USER}@${SERVER_HOST}"
RSYNC_CMD="/opt/homebrew/bin/rsync"
RSYNC_SSH="ssh -p ${SERVER_PORT} ${SSH_OPTS}"

# Clean up control socket on exit
cleanup() {
    ssh -o ControlPath="${CONTROL_SOCK}" -O exit ${SERVER_USER}@${SERVER_HOST} 2>/dev/null || true
    rm -f "${CONTROL_SOCK}"
}
trap cleanup EXIT

# Files to upload
PHP_FILES=(
    "telegram-bot.php"
    "telegram-memory.php"
    "telegram-rag.php"
    "process-queue.php"
    "companion-classifier.php"
    "companion-membership.php"
    "watchdog-queue.sh"
    "outreach-engine.php"
    "outreach-deliver.php"
    "doctrinal-backbone.md"
    ".htaccess"
)

# ===== Functions =====

index_vault() {
    echo "=== Indexing Vault ==="
    if [ ! -d "${VAULT_PATH}" ]; then
        echo "Error: Vault not found at ${VAULT_PATH}"
        exit 1
    fi

    cd "${SCRIPT_DIR}"

    # Pass --translations explicitly so language-aware indexing is the documented
    # default. The indexer also auto-discovers ../i18n/translations relative to
    # itself when the flag is omitted, but explicit is safer against future moves.
    if [ -d "${TRANSLATIONS_PATH}" ]; then
        python3 index-vault.py \
            --vault "${VAULT_PATH}" \
            --translations "${TRANSLATIONS_PATH}" \
            --output vault-index.db
    else
        echo "  Note: translations directory not found at ${TRANSLATIONS_PATH} — indexing English only."
        python3 index-vault.py --vault "${VAULT_PATH}" --output vault-index.db
    fi
    echo ""
}

# Returns 0 (stale) if the index is missing or older than any indexable source
# file; returns 1 (fresh) otherwise. Indexable = vault .md outside Internal/ and
# i18n/, plus translation .md under TRANSLATIONS_PATH. The mtime gate is the cheap
# correctness check; the indexer itself applies the full audience:developer filter,
# so a touched developer file at worst triggers one harmless reindex.
vault_is_stale() {
    local db="${SCRIPT_DIR}/vault-index.db"
    [ -f "${db}" ] || { echo "  No existing index — reindex required."; return 0; }

    local newer
    newer=$(find "${VAULT_PATH}" -name '*.md' -type f -newer "${db}" \
        -not -path '*/Internal/*' -not -path '*/i18n/*' -print -quit 2>/dev/null)

    if [ -z "${newer}" ] && [ -d "${TRANSLATIONS_PATH}" ]; then
        newer=$(find "${TRANSLATIONS_PATH}" -name '*.md' -type f -newer "${db}" -print -quit 2>/dev/null)
    fi

    if [ -n "${newer}" ]; then
        echo "  Vault changed since last index (e.g. ${newer#${VAULT_PATH}/})."
        return 0
    fi
    return 1
}

upload_files() {
    echo "=== Uploading to Server ==="

    # Create remote directory
    ${SSH_CMD} "mkdir -p ${SERVER_PATH}"

    # Upload PHP files
    for f in "${PHP_FILES[@]}"; do
        echo "  Uploading ${f}..."
        ${RSYNC_CMD} -avz --chmod=F644,D755 \
            -e "${RSYNC_SSH}" \
            "${SCRIPT_DIR}/${f}" \
            "${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}/${f}"
    done

    # Upload vault index database
    if [ -f "${SCRIPT_DIR}/vault-index.db" ]; then
        echo "  Uploading vault-index.db..."
        ${RSYNC_CMD} -avz --chmod=F644,D755 \
            -e "${RSYNC_SSH}" \
            "${SCRIPT_DIR}/vault-index.db" \
            "${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}/vault-index.db"
    else
        echo "  Warning: vault-index.db not found. Run with --index first."
    fi

    # Set permissions
    ${SSH_CMD} "chmod 755 ${SERVER_PATH} && chmod 644 ${SERVER_PATH}/*.php ${SERVER_PATH}/*.md ${SERVER_PATH}/.htaccess"
    ${SSH_CMD} "chmod 755 ${SERVER_PATH}/watchdog-queue.sh 2>/dev/null || true"

    # Ensure data directory is writable (for SQLite companion-data.db)
    ${SSH_CMD} "touch ${SERVER_PATH}/companion-data.db && chmod 666 ${SERVER_PATH}/companion-data.db"
    ${SSH_CMD} "touch ${SERVER_PATH}/companion-errors.log && chmod 666 ${SERVER_PATH}/companion-errors.log"

    # Kill any existing worker so it restarts with new code
    echo "  Stopping existing queue worker..."
    ${SSH_CMD} "if [ -f ${SERVER_PATH}/queue-processor.lock ]; then PID=\$(cat ${SERVER_PATH}/queue-processor.lock); kill \$PID 2>/dev/null && echo '  Killed worker PID '\$PID || echo '  No running worker found'; rm -f ${SERVER_PATH}/queue-processor.lock; else echo '  No lock file found'; fi"

    # Start the worker
    echo "  Starting queue worker..."
    ${SSH_CMD} "cd ${SERVER_PATH} && nohup /usr/bin/php process-queue.php >> companion-errors.log 2>&1 &" &
    sleep 1
    ${SSH_CMD} "if [ -f ${SERVER_PATH}/queue-processor.lock ]; then echo '  Worker started (PID '\$(cat ${SERVER_PATH}/queue-processor.lock)')'; else echo '  Warning: worker may not have started — check companion-errors.log'; fi"

    echo "  Upload complete."
    echo ""
    echo "  NOTE: Set up the watchdog cron on the server (Hostinger cPanel → Cron Jobs):"
    echo "    */5 * * * * /home/${SERVER_USER}/${SERVER_PATH}/watchdog-queue.sh >> /home/${SERVER_USER}/${SERVER_PATH}/watchdog.log 2>&1"
    echo "  This replaces the old '* * * * * php process-queue.php' cron."
    echo ""
}

set_webhook() {
    echo "=== Setting Telegram Webhook ==="

    # Read bot token from server config
    BOT_TOKEN=$(${SSH_CMD} "php -r \"require('/home/${SERVER_USER}/.telegram-config.php'); echo TELEGRAM_BOT_TOKEN;\"" 2>/dev/null)

    if [ -z "${BOT_TOKEN}" ] || [ "${BOT_TOKEN}" = "YOUR_BOT_TOKEN_HERE" ]; then
        echo "Error: Bot token not configured on server."
        echo "Please edit /home/${SERVER_USER}/.telegram-config.php with your bot token."
        exit 1
    fi

    WEBHOOK_URL="https://harmonism.io/telegram-companion/telegram-bot.php"

    echo "  Setting webhook to: ${WEBHOOK_URL}"
    RESPONSE=$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook?url=${WEBHOOK_URL}")
    echo "  Telegram response: ${RESPONSE}"

    # Verify
    echo ""
    echo "  Webhook info:"
    curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo" | python3 -m json.tool 2>/dev/null || \
    curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo"

    echo ""
}

verify() {
    echo "=== Verification ==="

    # Check files exist on server
    echo "  Remote files:"
    ${SSH_CMD} "ls -la ${SERVER_PATH}/"

    echo ""
    echo "  Config check:"
    ${SSH_CMD} "php -r \"
        if (file_exists('/home/${SERVER_USER}/.telegram-config.php')) {
            require('/home/${SERVER_USER}/.telegram-config.php');
            echo 'Config: OK\n';
            echo 'Bot token: ' . (TELEGRAM_BOT_TOKEN !== 'YOUR_BOT_TOKEN_HERE' ? 'SET' : 'NOT SET') . '\n';
            echo 'API key: ' . (ANTHROPIC_API_KEY !== 'sk-ant-api03-YOUR-KEY-HERE' ? 'SET' : 'NOT SET') . '\n';
            echo 'Model: ' . COMPANION_MODEL . '\n';
        } else {
            echo 'Config: NOT FOUND\n';
        }
    \""

    echo ""
    echo "  Database check:"
    ${SSH_CMD} "ls -la ${SERVER_PATH}/*.db 2>/dev/null || echo 'No databases found'"

    echo ""
}

# ===== Main =====

case "${1:-}" in
    --index)
        index_vault
        ;;
    --upload)
        if [ "${2:-}" = "--no-index" ]; then
            echo "  --no-index: skipping staleness gate, uploading existing index as-is."
        elif vault_is_stale; then
            echo "=== Vault is stale — auto-reindexing before upload ==="
            index_vault
        else
            echo "  Vault index is current — no reindex needed."
            echo ""
        fi
        upload_files
        verify
        ;;
    --webhook)
        set_webhook
        ;;
    --verify)
        verify
        ;;
    *)
        # Full deploy
        index_vault
        upload_files
        set_webhook
        verify
        echo "=== Deployment Complete ==="
        echo "Your Companion is live on Telegram."
        echo "Find your bot and send /start to begin."
        ;;
esac
