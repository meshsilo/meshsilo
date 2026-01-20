# Silo - 3D Print File Manager
# Ubuntu-based Docker image with nginx and PHP-FPM

FROM ubuntu:24.04

LABEL maintainer="Azurith93"
LABEL description="Silo - Digital Asset Manager for 3D print files"

# Prevent interactive prompts during package installation
ENV DEBIAN_FRONTEND=noninteractive

# Install nginx, PHP, and required extensions
RUN apt-get update && apt-get install software-properties-common && sudo apt update && add-apt-repository ppa:ondrej/php && apt-get install -y \
    nginx \
    php8.1-fpm \
    php8.1-sqlite3 \
    php8.1-mysql \
    php8.1-zip \
    php8.1-mbstring \
    php8.1-curl \
    php8.1-xml \
    supervisor \
    curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Create application directory
WORKDIR /var/www/silo

# Copy application files
COPY --chown=www-data:www-data . /var/www/silo/

# Create required directories with correct permissions
RUN mkdir -p /var/www/silo/assets \
    /var/www/silo/logs \
    /var/www/silo/db \
    && chown -R www-data:www-data /var/www/silo \
    && chmod -R 755 /var/www/silo \
    && chmod -R 775 /var/www/silo/assets \
    /var/www/silo/logs \
    /var/www/silo/db

# Copy nginx configuration
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Copy PHP-FPM configuration
COPY docker/php-fpm.conf /etc/php/8.1/fpm/pool.d/www.conf

# Copy supervisor configuration
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy entrypoint script
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Configure PHP settings for file uploads
RUN sed -i 's/upload_max_filesize = .*/upload_max_filesize = 100M/' /etc/php/8.1/fpm/php.ini \
    && sed -i 's/post_max_size = .*/post_max_size = 100M/' /etc/php/8.1/fpm/php.ini \
    && sed -i 's/memory_limit = .*/memory_limit = 256M/' /etc/php/8.1/fpm/php.ini \
    && sed -i 's/max_execution_time = .*/max_execution_time = 300/' /etc/php/8.1/fpm/php.ini

# Create PHP-FPM socket directory
RUN mkdir -p /run/php && chown www-data:www-data /run/php

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Volume for persistent data
VOLUME ["/var/www/silo/assets", "/var/www/silo/db", "/var/www/silo/logs"]

# Start supervisor (manages nginx and php-fpm)
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
