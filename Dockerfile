# MeshSilo - 3D Model Asset Manager
# Ubuntu-based Docker image with nginx and PHP-FPM

FROM ubuntu:24.04

LABEL maintainer="Azurith93"
LABEL description="MeshSilo - Digital Asset Manager for 3D model files"

# Prevent interactive prompts during package installation
ENV DEBIAN_FRONTEND=noninteractive

# Set Docker environment flag for application
ENV MESHSILO_DOCKER=true

# Install nginx, PHP, and required extensions
RUN apt-get update && apt-get install -y software-properties-common && apt update && add-apt-repository ppa:ondrej/php && apt-get install -y \
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
WORKDIR /var/www/meshsilo

# Copy application files
COPY --chown=www-data:www-data . /var/www/meshsilo/

# Create required directories with correct permissions
RUN mkdir -p /var/www/meshsilo/assets \
    /var/www/meshsilo/logs \
    /var/www/meshsilo/db \
    && chown -R www-data:www-data /var/www/meshsilo \
    && chmod -R 755 /var/www/meshsilo \
    && chmod -R 775 /var/www/meshsilo/assets \
    /var/www/meshsilo/logs \
    /var/www/meshsilo/db

# Copy nginx configuration
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Copy PHP-FPM configuration
COPY docker/php-fpm.conf /etc/php/8.1/fpm/pool.d/www.conf

# Copy supervisor configuration
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy entrypoint and demo cron scripts
COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/demo-cron.sh /demo-cron.sh
RUN chmod +x /entrypoint.sh /demo-cron.sh

# Configure PHP settings for file uploads (both FPM and CLI)
# Create custom config files in conf.d to override defaults reliably
RUN echo "upload_max_filesize = 100M" > /etc/php/8.1/fpm/conf.d/99-meshsilo.ini \
    && echo "post_max_size = 105M" >> /etc/php/8.1/fpm/conf.d/99-meshsilo.ini \
    && echo "memory_limit = 2G" >> /etc/php/8.1/fpm/conf.d/99-meshsilo.ini \
    && echo "max_execution_time = 300" >> /etc/php/8.1/fpm/conf.d/99-meshsilo.ini \
    && echo "upload_max_filesize = 100M" > /etc/php/8.1/cli/conf.d/99-meshsilo.ini \
    && echo "post_max_size = 105M" >> /etc/php/8.1/cli/conf.d/99-meshsilo.ini \
    && echo "memory_limit = 2G" >> /etc/php/8.1/cli/conf.d/99-meshsilo.ini \
    && echo "max_execution_time = 300" >> /etc/php/8.1/cli/conf.d/99-meshsilo.ini

# Create PHP-FPM socket directory
RUN mkdir -p /run/php && chown www-data:www-data /run/php

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Volume for persistent data
VOLUME ["/var/www/meshsilo/assets", "/var/www/meshsilo/db", "/var/www/meshsilo/logs"]

# Start supervisor (manages nginx and php-fpm)
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
