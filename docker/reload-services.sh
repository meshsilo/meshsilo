#!/bin/bash
# Reload PHP-FPM and nginx after configuration changes
# Called from the admin UI via sudo

# Reload PHP-FPM (graceful restart)
if [ -f /run/php/php8.1-fpm.pid ]; then
    kill -USR2 "$(cat /run/php/php8.1-fpm.pid)" 2>/dev/null
    echo "PHP-FPM reloaded"
else
    echo "PHP-FPM PID file not found"
    exit 1
fi

# Reload nginx
nginx -s reload 2>/dev/null
if [ $? -eq 0 ]; then
    echo "nginx reloaded"
else
    echo "nginx reload failed"
    exit 1
fi
