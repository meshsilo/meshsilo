#!/bin/bash
set -e

# MeshSilo Docker Entrypoint Script
# Handles initialization and configuration from environment variables

# Set Docker environment flag for Logger to detect
export MESHSILO_DOCKER=true

CONFIG_FILE="/var/www/meshsilo/storage/db/config.local.php"

# Create supervisor log directory
mkdir -p /var/log/supervisor

# Ensure writable directories exist and have correct permissions
mkdir -p /var/www/meshsilo/storage/assets /var/www/meshsilo/storage/logs /var/www/meshsilo/storage/db /var/www/meshsilo/storage/cache
chown -R www-data:www-data /var/www/meshsilo/storage
chmod -R 775 /var/www/meshsilo/storage

# Create all log files if they don't exist (so tail doesn't fail)
LOG_FILES="php-error.log app.log security.log access.log database.log"
for logfile in $LOG_FILES; do
    touch "/var/www/meshsilo/storage/logs/$logfile"
done
chown -R www-data:www-data /var/www/meshsilo/storage/logs/
chmod 664 /var/www/meshsilo/storage/logs/*.log

# If no config exists, the install wizard will handle setup on first visit
if [ ! -f "$CONFIG_FILE" ]; then
    echo "No configuration found. The install wizard will run on first visit."
fi

# Export environment variables for PHP to read
export MESHSILO_SITE_NAME="${MESHSILO_SITE_NAME:-MeshSilo}"
export MESHSILO_SITE_DESCRIPTION="${MESHSILO_SITE_DESCRIPTION:-3D Print File Manager}"
export MESHSILO_SITE_URL="${MESHSILO_SITE_URL:-}"

# Initialize database settings from environment variables (only if already installed)
if [ -f "$CONFIG_FILE" ]; then
    # Wait for database to be ready (for MySQL)
    if [ "$MESHSILO_DB_TYPE" = "mysql" ]; then
        echo "Waiting for MySQL to be ready..."
        for i in $(seq 1 30); do
            if php -r "
                \$dsn = 'mysql:host=${MESHSILO_DB_HOST:-localhost};dbname=${MESHSILO_DB_NAME:-meshsilo}';
                try {
                    new PDO(\$dsn, '${MESHSILO_DB_USER:-meshsilo}', '${MESHSILO_DB_PASS:-}');
                    exit(0);
                } catch (Exception \$e) {
                    exit(1);
                }
            " 2>/dev/null; then
                echo "MySQL is ready."
                break
            fi
            echo "Waiting for MySQL... ($i/30)"
            sleep 2
        done
    fi

    # Run database migrations
    echo "Running database migrations..."
    su -s /bin/bash www-data -c "php /var/www/meshsilo/cli/migrate.php" || true

    # Initialize settings from environment variables
    if [ -f "/var/www/meshsilo/cli/init-settings.php" ]; then
        echo "Initializing database settings from environment variables..."
        php /var/www/meshsilo/cli/init-settings.php || true
    fi
fi

# Update PHP upload limits from environment if specified
if [ -n "$MESHSILO_MAX_UPLOAD_SIZE" ]; then
    # Convert to MB for PHP config
    UPLOAD_MB=$(($MESHSILO_MAX_UPLOAD_SIZE / 1048576))

    # Update the custom config file to override defaults
    sed -i "s/^upload_max_filesize = .*/upload_max_filesize = ${UPLOAD_MB}M/" /etc/php/8.1/fpm/conf.d/99-meshsilo.ini
    sed -i "s/^post_max_size = .*/post_max_size = ${UPLOAD_MB}M/" /etc/php/8.1/fpm/conf.d/99-meshsilo.ini

    # Update nginx client_max_body_size
    sed -i "s/client_max_body_size .*/client_max_body_size ${UPLOAD_MB}M;/" /etc/nginx/sites-available/default
fi

echo "MeshSilo Docker container starting..."
echo "Access the application at http://localhost:80"

# Execute the main command
exec "$@"
