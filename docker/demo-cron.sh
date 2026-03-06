#!/bin/bash
# Demo mode periodic reset
# Runs demo-reset.php every hour when demo mode is enabled

RESET_INTERVAL=${MESHSILO_DEMO_RESET_INTERVAL:-3600}

echo "Demo cron started. Reset interval: ${RESET_INTERVAL}s"

while true; do
    sleep "$RESET_INTERVAL"
    echo "[$(date)] Running scheduled demo reset..."
    php /var/www/meshsilo/cli/demo-reset.php --force 2>&1
    echo "[$(date)] Demo reset complete."
done
