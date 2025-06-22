FROM php:8.2-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    mysql-client \
    git \
    curl \
    redis \
    libpq \
    libzip \
    libpng \
    libjpeg-turbo \
    onig \
    libxml2-dev \
    freetype-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    gmp-dev \
    icu-dev \
    zlib-dev \
    openssl-dev \
    composer \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        bcmath \
        gmp \
        intl \
        zip \
        exif \
        pcntl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install -j$(nproc) opcache \
    && docker-php-ext-enable opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/* /var/cache/apk/*

# Set working directory
WORKDIR /var/www/html

# Copy application code
COPY . /var/www/html

# Configure Nginx and Supervisor
COPY nginx/default.conf /etc/nginx/conf.d/default.conf
COPY supervisor/laravel-worker.conf /etc/supervisor/conf.d/laravel-worker.conf

# Configure PHP-FPM
RUN sed -i 's/user = www-data/user = root/g' /etc/php82/php-fpm.d/www.conf \
    && sed -i 's/group = www-data/group = root/g' /etc/php82/php-fpm.d/www.conf \
    && sed -i 's/;listen.mode = 0660/listen.mode = 0666/g' /etc/php82/php-fpm.d/www.conf \
    && sed -i 's/;daemonize = yes/daemonize = no/g' /etc/php82/php-fpm.conf

# Set directory permissions
RUN chown -R root:root /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Composer install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --ignore-platform-reqs

# Expose port
EXPOSE 80

# Start Nginx and Supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/laravel-worker.conf"]
