#!/bin/bash
#
# The Wisdom Canon — targeted deploy of the reading track to the Telegram surface.
#
# Decision #592: PHP-only edits deploy by targeted rsync of the changed files,
# never through the full build-and-deploy pipeline. This ships four files and
# nothing else — no vault-index.db (305 MB), no re-index, no website rebuild.
#
# Decision #1185: a green deploy proves nothing about a file no upload list
# names, so this script verifies server-side byte counts against local ones
# after the transfer and fails loudly on any mismatch.
#
# Usage:
#   cd ~/Documents/Vault/harmonia-astro/telegram-companion
#   ./deploy-reading.sh
#
# The queue worker is deliberately NOT restarted. process-queue.php is
# unchanged and never loads the reading engine; telegram-bot.php runs fresh per
# webhook. Killing the worker would abort in-flight jobs for no gain.
#
# After this runs, one manual step remains and the feature is inert without it:
# add the composer cron in Hostinger cPanel with interpreter php, not bash
# (Decision #797 — a bash-shebanged run under the wrong interpreter echoes the
# file and exits 0, which looks exactly like success):
#
#   20 * * * *  php /home/SERVER_USER/domains/harmonism.io/public_html/telegram-companion/reading-checkin.php
#
# Minute 20, so a slot composed this hour is delivered by outreach-deliver.php
# on its own hourly run within the same hour rather than the next.

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SERVER_USER="SERVER_USER"
SERVER_HOST="SERVER_HOST"
SERVER_PORT="SSH_PORT"
SERVER_PATH="domains/harmonism.io/public_html/telegram-companion"

CONTROL_SOCK="/tmp/.ssh-deploy-reading"
SSH_OPTS="-o ControlMaster=auto -o ControlPath=${CONTROL_SOCK} -o ControlPersist=120"
SSH_CMD="ssh -p ${SERVER_PORT} ${SSH_OPTS} ${SERVER_USER}@${SERVER_HOST}"
RSYNC_CMD="/opt/homebrew/bin/rsync"
RSYNC_SSH="ssh -p ${SERVER_PORT} ${SSH_OPTS}"

cleanup() {
    ssh -o ControlPath="${CONTROL_SOCK}" -O exit ${SERVER_USER}@${SERVER_HOST} 2>/dev/null || true
    rm -f "${CONTROL_SOCK}"
}
trap cleanup EXIT

FILES=(
    "telegram-bot.php"
    "companion-reading.php"
    "companion-reading-telegram.php"
    "reading-checkin.php"
)

echo "=== The Wisdom Canon — targeted deploy ==="
echo ""

# --- pre-flight: syntax ---
echo "Pre-flight: PHP syntax"
for f in "${FILES[@]}"; do
    if [ ! -f "${SCRIPT_DIR}/${f}" ]; then
        echo "  ABORT: ${f} not found locally."; exit 1
    fi
    if ! php -l "${SCRIPT_DIR}/${f}" > /dev/null 2>&1; then
        echo "  ABORT: ${f} has a syntax error:"; php -l "${SCRIPT_DIR}/${f}"; exit 1
    fi
    echo "  ok  ${f}"
done
echo ""

# --- upload ---
echo "Uploading"
${SSH_CMD} "mkdir -p ${SERVER_PATH}"
for f in "${FILES[@]}"; do
    echo "  ${f}"
    ${RSYNC_CMD} -avz --chmod=F644,D755 -e "${RSYNC_SSH}" \
        "${SCRIPT_DIR}/${f}" \
        "${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}/${f}" > /dev/null
done
${SSH_CMD} "chmod 644 ${SERVER_PATH}/companion-reading.php ${SERVER_PATH}/companion-reading-telegram.php ${SERVER_PATH}/reading-checkin.php ${SERVER_PATH}/telegram-bot.php"
echo ""

# --- verify: byte-for-byte, not "rsync said ok" (#1185) ---
echo "Verifying server byte counts"
FAIL=0
for f in "${FILES[@]}"; do
    LOCAL=$(wc -c < "${SCRIPT_DIR}/${f}" | tr -d ' ')
    REMOTE=$(${SSH_CMD} "wc -c < ${SERVER_PATH}/${f}" 2>/dev/null | tr -d ' ')
    if [ "${LOCAL}" = "${REMOTE}" ]; then
        printf "  ok   %-34s %s bytes\n" "${f}" "${LOCAL}"
    else
        printf "  GAP  %-34s local=%s server=%s\n" "${f}" "${LOCAL}" "${REMOTE}"
        FAIL=1
    fi
done
echo ""

if [ "${FAIL}" != "0" ]; then
    echo "DEPLOY INCOMPLETE — a file did not land. Do not treat this as shipped."
    exit 1
fi

# --- smoke: the engine parses on the server's own PHP, and the schema builds ---
echo "Smoke test on the server"
${SSH_CMD} "cd ${SERVER_PATH} && php -l companion-reading.php" | sed 's/^/  /'
${SSH_CMD} "cd ${SERVER_PATH} && php -r '
require \"companion-reading.php\";
\$db = new PDO(\"sqlite:companion-data.db\");
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
readingEnsureSchema(\$db);
\$t = \$db->query(\"SELECT name FROM sqlite_master WHERE type=(char(116)||char(97)||char(98)||char(108)||char(101)) AND name LIKE (char(114)||char(101)||char(97)||char(100)||char(105)||char(110)||char(103)||char(37))\")->fetchAll(PDO::FETCH_COLUMN);
echo \"  reading tables: \" . implode(\", \", \$t) . PHP_EOL;
echo \"  canon works: \" . count(readingCanon()) . PHP_EOL;
echo \"  quran blocked: \" . (readingResolveWork(\"quran\") === null ? \"yes\" : \"NO\") . PHP_EOL;
'"
echo ""

echo "=== Deployed ==="
echo ""
echo "Still required, or nothing fires:"
echo "  Hostinger cPanel -> Cron Jobs, interpreter php:"
echo "    20 * * * *  php /home/${SERVER_USER}/${SERVER_PATH}/reading-checkin.php"
echo ""
echo "Then, from Telegram: /read"
