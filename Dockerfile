# =============================================================================
# Stage 1: Composer dependencies
# =============================================================================
FROM composer:2.7 AS composer-deps

WORKDIR /app

COPY composer.json composer.lock ./

# Install production dependencies only
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY . .

RUN composer dump-autoload \
    --optimize \
    --no-dev \
    --classmap-authoritative

# =============================================================================
# Stage 2: Node / Frontend assets (if building frontend in same repo)
# =============================================================================
FROM node:20-alpine AS node-deps

WORKDIR /app

# Only run if package.json exists (for asset compilation)
COPY --from=composer-deps /app/package*.json ./
RUN if [ -f "package.json" ]; then npm ci --omit=dev; fi

COPY --from=composer-deps /app/ ./

RUN if [ -f "package.json" ] && grep -q '"build"' package.json; then \
        npm run build; \
    fi

# =============================================================================
# Stage 3: Production PHP-FPM image
# =============================================================================
FROM php:8.3-fpm-alpine AS production

LABEL maintainer="Kutubxona.uz <dev@kutubxona.uz>"
LABEL version="1.0.0"
LABEL description="Kutubxona.uz Multi-Tenant Digital Library Platform"

# ---------------------------------------------------------------------------
# System dependencies
# ---------------------------------------------------------------------------
RUN apk add --no-cache \
    # Core utilities
    bash \
    curl \
    git \
    unzip \
    # Image processing (for WebP thumbnail generation)
    imagemagick \
    imagemagick-dev \
    # Audio processing binaries (called via exec)
    ffmpeg \
    # File identification
    libmagic \
    file \
    # ClamAV for optional virus scanning
    clamav \
    clamav-daemon \
    # PDF processing
    poppler-utils \
    # MySQL / Redis clients
    mysql-client \
    redis \
    # Process supervisor
    supervisor \
    # Timezone data
    tzdata

# ---------------------------------------------------------------------------
# PHP extensions
# ---------------------------------------------------------------------------
RUN apk add --no-cache \
    ${PHPIZE_DEPS} \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    imagemagick-dev \
    libmagickwand-dev \
    openssl-dev \
    pcre-dev \
    linux-headers

RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg

RUN docker-php-ext-install \
    bcmath \
    exif \
    gd \
    intl \
    mbstring \
    opcache \
    pcntl \
    pdo \
    pdo_mysql \
    sockets \
    xml \
    zip

# PECL extensions
RUN pecl install \
    imagick \
    igbinary \
    redis \
    && docker-php-ext-enable \
        imagick \
        igbinary \
        redis

# Cleanup build dependencies
RUN apk del ${PHPIZE_DEPS}

# ---------------------------------------------------------------------------
# PHP configuration
# ---------------------------------------------------------------------------
COPY docker/php/php.ini /usr/local/etc/php/php.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# ---------------------------------------------------------------------------
# Application
# ---------------------------------------------------------------------------
WORKDIR /var/www/html

# Create non-root user for running the app
RUN addgroup -g 1000 -S appgroup && \
    adduser -u 1000 -S appuser -G appgroup

# Copy application files from composer stage
COPY --from=composer-deps --chown=appuser:appgroup /app /var/www/html

# Storage directories
RUN mkdir -p \
    storage/app/public \
    storage/app/temp \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R appuser:appgroup \
        storage \
        bootstrap/cache \
    && chmod -R 775 \
        storage \
        bootstrap/cache

# ---------------------------------------------------------------------------
# Supervisor configuration
# ---------------------------------------------------------------------------
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# ---------------------------------------------------------------------------
# Health check
# ---------------------------------------------------------------------------
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/api/health || exit 1

USER appuser

EXPOSE 9000

# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------
COPY --chown=appuser:appgroup docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]

# =============================================================================
# Stage 4: Queue worker image (extends production)
# =============================================================================
FROM production AS worker

USER root

# Override CMD for queue workers
CMD ["php", "artisan", "horizon"]

# =============================================================================
# Stage 5: Scheduler image (extends production)
# =============================================================================
FROM production AS scheduler

USER root

COPY docker/cron/laravel-scheduler /etc/cron.d/laravel-scheduler
RUN chmod 0644 /etc/cron.d/laravel-scheduler && \
    crontab /etc/cron.d/laravel-scheduler

CMD ["crond", "-f", "-d", "8"]

# =============================================================================
# Stage 6: Development image (with dev tools)
# =============================================================================
FROM php:8.3-fpm-alpine AS development

RUN apk add --no-cache \
    bash \
    curl \
    git \
    unzip \
    imagemagick \
    imagemagick-dev \
    ffmpeg \
    libmagic \
    file \
    mysql-client \
    tzdata \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    imagemagick-dev \
    libmagickwand-dev \
    openssl-dev \
    pcre-dev \
    linux-headers \
    ${PHPIZE_DEPS}

RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install bcmath exif gd intl mbstring opcache pcntl pdo pdo_mysql sockets xml zip && \
    pecl install imagick igbinary redis xdebug && \
    docker-php-ext-enable imagick igbinary redis xdebug && \
    apk del ${PHPIZE_DEPS}

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN addgroup -g 1000 -S appgroup && \
    adduser -u 1000 -S appuser -G appgroup

# Xdebug configuration
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

EXPOSE 9000 9003

USER appuser

CMD ["php-fpm"]
