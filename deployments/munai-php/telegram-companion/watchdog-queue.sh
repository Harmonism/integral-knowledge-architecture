#!/bin/bash
# Watchdog for Companion queue worker (Decision #XXX)
#
# Checks if the persistent worker is running. If not, starts it.
# Designed to run via cron every 5 minutes:
#   */5 * * * * /path/to/watchdog-queue.sh >> /path/to/watchdog.log 2>&1
#
# The worker self-terminates after MAX_RUNTIME (1 hour) to prevent memory leaks
# and ensure clean restarts. The watchdog picks it back up within 5 minutes.

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOCK_FILE="$SCRIPT_DIR/queue-processor.lock"
LOG_FILE="$SCRIPT_DIR/companion-errors.log"
PHP_BIN="/usr/bin/php"

# Check if lock file exists and process is actually running
if [ -f "$LOCK_FILE" ]; then
    PID=$(cat "$LOCK_FILE" 2>/dev/null)
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
        # Worker is running, nothing to do
        exit 0
    else
        # Stale lock file — process died without cleanup
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Stale lock file found (PID $PID not running), removing"
        rm -f "$LOCK_FILE"
    fi
fi

# Start the worker in the background
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting queue worker"
cd "$SCRIPT_DIR"
nohup "$PHP_BIN" process-queue.php >> "$LOG_FILE" 2>&1 &
NEW_PID=$!
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Worker started with PID $NEW_PID"
