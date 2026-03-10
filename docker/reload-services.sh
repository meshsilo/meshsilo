#!/bin/bash
# Reload PHP-FPM and nginx after configuration changes
# Called from the admin UI via sudo

STAGING_INI="/var/www/meshsilo/storage/cache/php-meshsilo.ini"
FPM_INI="/etc/php/8.1/fpm/conf.d/99-meshsilo.ini"
CLI_INI="/etc/php/8.1/cli/conf.d/99-meshsilo.ini"
NGINX_CONF="/etc/nginx/sites-available/default"

# Apply staged PHP config if it exists
if [ -f "$STAGING_INI" ]; then
    cp "$STAGING_INI" "$FPM_INI"
    cp "$STAGING_INI" "$CLI_INI"

    # Sync nginx client_max_body_size with upload_max_filesize
    UPLOAD_SIZE=$(grep -i '^upload_max_filesize' "$STAGING_INI" | sed 's/.*=\s*//' | tr -d ' ')
    if [ -n "$UPLOAD_SIZE" ] && [ -f "$NGINX_CONF" ]; then
        sed -i "s/client_max_body_size [^;]*;/client_max_body_size ${UPLOAD_SIZE};/" "$NGINX_CONF"
    fi
fi

# Reload nginx first (picks up client_max_body_size changes)
nginx -t 2>/dev/null && nginx -s reload 2>/dev/null
echo "nginx reloaded"

# Graceful PHP-FPM reload: finish existing requests, then apply new config
if [ -f /run/php/php8.1-fpm.pid ]; then
    # USR2 = graceful restart: spawns new workers with new config,
    # old workers finish current requests before exiting
    kill -USR2 "$(cat /run/php/php8.1-fpm.pid)" 2>/dev/null
    echo "PHP-FPM reloading gracefully"
else
    echo "PHP-FPM PID file not found"
    exit 1
fi
