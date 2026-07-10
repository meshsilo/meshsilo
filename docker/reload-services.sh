#!/bin/bash
# Reload PHP-FPM and nginx after configuration changes
# Called from the admin UI via sudo

STAGING_INI="/var/www/meshsilo/storage/cache/php-meshsilo.ini"
FPM_INI="/etc/php/8.1/fpm/conf.d/99-meshsilo.ini"
CLI_INI="/etc/php/8.1/cli/conf.d/99-meshsilo.ini"
NGINX_CONF="/etc/nginx/sites-available/default"

# Always regenerate the applied FPM/CLI config deterministically. www-data can
# only write the staging file (storage/cache/php-meshsilo.ini); this root-owned
# script is the SOLE writer of the applied ini. Regenerating on every run (rather
# than only when a staging file exists) guarantees www-data can never leave
# arbitrary directives sitting in the applied ini from a previous state.
#
# Defense-in-depth: only an allowlist of safe size/time tuning directives is
# copied into the applied FPM/CLI config. The admin UI (app/admin/settings.php)
# is the primary control and already rejects anything else, but filtering here
# as well ensures a hand-placed staging file can never inject dangerous
# directives (auto_prepend_file, disable_functions, extension, etc.) into PHP.
ALLOWED_KEYS='^[[:space:]]*(upload_max_filesize|post_max_size|memory_limit|max_execution_time|max_input_time|max_file_uploads|max_input_vars)[[:space:]]*='
if [ -f "$STAGING_INI" ]; then
    grep -iE "$ALLOWED_KEYS" "$STAGING_INI" > "$FPM_INI"
    grep -iE "$ALLOWED_KEYS" "$STAGING_INI" > "$CLI_INI"

    # Sync nginx client_max_body_size with upload_max_filesize
    UPLOAD_SIZE=$(grep -i '^upload_max_filesize' "$STAGING_INI" | sed 's/.*=\s*//' | tr -d ' ')
    if [ -n "$UPLOAD_SIZE" ] && [ -f "$NGINX_CONF" ]; then
        sed -i "s/client_max_body_size [^;]*;/client_max_body_size ${UPLOAD_SIZE};/" "$NGINX_CONF"
    fi
else
    # No staging file: regenerate the deterministic image-default applied config
    # so the applied ini can never retain stale or hand-injected directives while
    # still preserving the app's default upload limits.
    printf 'upload_max_filesize = 100M\npost_max_size = 105M\nmemory_limit = 4G\nmax_execution_time = 300\n' > "$FPM_INI"
    printf 'upload_max_filesize = 100M\npost_max_size = 105M\nmemory_limit = 4G\nmax_execution_time = 300\n' > "$CLI_INI"
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
