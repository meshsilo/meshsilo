# MeshSilo - 3D Model Asset Manager
# Ubuntu-based Docker image with nginx and PHP-FPM

FROM ubuntu:24.04

LABEL maintainer="MeshSilo"
LABEL description="MeshSilo - Digital Asset Manager for 3D model files"

# Prevent interactive prompts during package installation
ENV DEBIAN_FRONTEND=noninteractive

# Set Docker environment flag for application
ENV MESHSILO_DOCKER=true

# Install nginx, PHP, and required extensions
RUN apt-get update && apt-get install -y software-properties-common && apt update && add-apt-repository ppa:ondrej/php && apt-get install -y \
    nginx \
    libbrotli-dev \
    php8.1-fpm \
    php8.1-sqlite3 \
    php8.1-mysql \
    php8.1-zip \
    php8.1-mbstring \
    php8.1-curl \
    php8.1-xml \
    php8.1-opcache \
    php8.1-apcu \
    php8.1-redis \
    php8.1-gd \
    supervisor \
    sudo \
    curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Create application directory
WORKDIR /var/www/meshsilo

# Version — set via --build-arg or auto-detected from git
ARG MESHSILO_VERSION=dev
RUN echo "${MESHSILO_VERSION}" > /tmp/meshsilo-version

# Copy application files
COPY --chown=www-data:www-data . /var/www/meshsilo/

# Write VERSION file into the image
RUN mv /tmp/meshsilo-version /var/www/meshsilo/VERSION && chown www-data:www-data /var/www/meshsilo/VERSION

# Create required directories with correct permissions
RUN mkdir -p /var/www/meshsilo/storage/assets \
    /var/www/meshsilo/storage/logs \
    /var/www/meshsilo/storage/db \
    /var/www/meshsilo/storage/cache \
    && chown -R www-data:www-data /var/www/meshsilo \
    && chmod -R 755 /var/www/meshsilo \
    && chmod -R 775 /var/www/meshsilo/storage/assets \
    /var/www/meshsilo/storage/logs \
    /var/www/meshsilo/storage/db \
    /var/www/meshsilo/storage/cache

# Copy nginx configuration (writable by www-data for admin UI upload size sync)
COPY docker/nginx.conf /etc/nginx/sites-available/default
RUN chown www-data:www-data /etc/nginx/sites-available/default

# Copy PHP-FPM configuration
COPY docker/php-fpm.conf /etc/php/8.1/fpm/pool.d/www.conf

# Copy supervisor configuration
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy entrypoint and reload scripts
COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/reload-services.sh /usr/local/bin/meshsilo-reload
RUN chmod +x /entrypoint.sh /usr/local/bin/meshsilo-reload

# Allow www-data to reload services via admin UI without password
RUN echo "www-data ALL=(ALL) NOPASSWD: /usr/local/bin/meshsilo-reload" > /etc/sudoers.d/meshsilo \
    && chmod 440 /etc/sudoers.d/meshsilo

# Configure PHP settings for file uploads (both FPM and CLI)
# Create custom config files in conf.d to override defaults reliably
# Owned by www-data so the admin UI can update them at runtime
RUN echo "upload_max_filesize = 100M" > /etc/php/8.1/fpm/conf.d/99-meshsilo.ini \
    && echo "post_max_size = 105M" >> /etc/php/8.1/fpm/conf.d/99-meshsilo.ini \
    && echo "memory_limit = 2G" >> /etc/php/8.1/fpm/conf.d/99-meshsilo.ini \
    && echo "max_execution_time = 300" >> /etc/php/8.1/fpm/conf.d/99-meshsilo.ini \
    && echo "upload_max_filesize = 100M" > /etc/php/8.1/cli/conf.d/99-meshsilo.ini \
    && echo "post_max_size = 105M" >> /etc/php/8.1/cli/conf.d/99-meshsilo.ini \
    && echo "memory_limit = 4G" >> /etc/php/8.1/cli/conf.d/99-meshsilo.ini \
    && echo "max_execution_time = 300" >> /etc/php/8.1/cli/conf.d/99-meshsilo.ini \
    && chown www-data:www-data /etc/php/8.1/fpm/conf.d/99-meshsilo.ini \
    && chown www-data:www-data /etc/php/8.1/cli/conf.d/99-meshsilo.ini

# Configure OPcache for production performance with JIT and preloading
RUN echo "opcache.enable=1" > /etc/php/8.1/fpm/conf.d/10-opcache-meshsilo.ini \
    && echo "opcache.enable_cli=1" >> /etc/php/8.1/fpm/conf.d/10-opcache-meshsilo.ini \
    && echo "opcache.memory_consumption=256" >> /etc/php/8.1/fpm/conf.d/10-opcache-meshsilo.ini \
    && echo "opcache.interned_strings_buffer=32" >> /etc/php/8.1/fpm/conf.d/10-opcache-meshsilo.ini \
    && echo "opcache.max_accelerated_files=10000" >> /etc/php/8.1/fpm/conf.d/10-opcache-meshsilo.ini \
    && echo "opcache.validate_timestamps=0" >> /etc/php/8.1/fpm/conf.d/10-opcache-meshsilo.ini \
    && echo "opcache.save_comments=1" >> /etc/php/8.1/fpm/conf.d/10-opcache-meshsilo.ini \
    && echo "opcache.fast_shutdown=1" >> /etc/php/8.1/fpm/conf.d/10-opcache-meshsilo.ini \
    && echo "opcache.preload=/var/www/meshsilo/includes/preload.php" >> /etc/php/8.1/fpm/conf.d/10-opcache-meshsilo.ini \
    && echo "opcache.preload_user=www-data" >> /etc/php/8.1/fpm/conf.d/10-opcache-meshsilo.ini \
    && echo "opcache.jit=1255" >> /etc/php/8.1/fpm/conf.d/10-opcache-meshsilo.ini \
    && echo "opcache.jit_buffer_size=128M" >> /etc/php/8.1/fpm/conf.d/10-opcache-meshsilo.ini \
    && cp /etc/php/8.1/fpm/conf.d/10-opcache-meshsilo.ini /etc/php/8.1/cli/conf.d/10-opcache-meshsilo.ini

# Configure APCu for in-memory caching
RUN echo "apc.enabled=1" > /etc/php/8.1/fpm/conf.d/20-apcu-meshsilo.ini \
    && echo "apc.shm_size=128M" >> /etc/php/8.1/fpm/conf.d/20-apcu-meshsilo.ini \
    && echo "apc.ttl=7200" >> /etc/php/8.1/fpm/conf.d/20-apcu-meshsilo.ini \
    && echo "apc.enable_cli=1" >> /etc/php/8.1/fpm/conf.d/20-apcu-meshsilo.ini \
    && cp /etc/php/8.1/fpm/conf.d/20-apcu-meshsilo.ini /etc/php/8.1/cli/conf.d/20-apcu-meshsilo.ini

# Create PHP-FPM socket directory
RUN mkdir -p /run/php && chown www-data:www-data /run/php

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

# Volume for persistent data
VOLUME ["/var/www/meshsilo/storage/assets", "/var/www/meshsilo/storage/db", "/var/www/meshsilo/storage/logs", "/var/www/meshsilo/plugins"]

# Start supervisor (manages nginx and php-fpm)
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
