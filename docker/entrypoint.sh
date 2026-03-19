#!/usr/bin/env bash
# =============================================================================
# Docker Entrypoint — Kutubxona.uz
# =============================================================================
set -euo pipefail

APP_DIR="/var/www/html"

log() {
    echo "[entrypoint] $(date -u +%Y-%m-%dT%H:%M:%SZ) $*"
}

# ---------------------------------------------------------------------------
# Wait for MySQL to be ready
# ---------------------------------------------------------------------------
wait_for_mysql() {
    log "Waiting for MySQL..."
    local max_attempts=30
    local attempt=0

    while ! php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge "$max_attempts" ]; then
            log "ERROR: MySQL not available after ${max_attempts} attempts. Exiting."
            exit 1
        fi
        log "MySQL not ready (attempt ${attempt}/${max_attempts}). Retrying in 3s..."
        sleep 3
    done

    log "MySQL is ready."
}

# ---------------------------------------------------------------------------
# Wait for Redis to be ready
# ---------------------------------------------------------------------------
wait_for_redis() {
    log "Waiting for Redis..."
    local max_attempts=20
    local attempt=0

    while ! redis-cli -h "${REDIS_HOST}" -p "${REDIS_PORT}" -a "${REDIS_PASSWORD}" ping 2>/dev/null | grep -q PONG; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge "$max_attempts" ]; then
            log "ERROR: Redis not available after ${max_attempts} attempts. Exiting."
            exit 1
        fi
        log "Redis not ready (attempt ${attempt}/${max_attempts}). Retrying in 2s..."
        sleep 2
    done

    log "Redis is ready."
}

# ---------------------------------------------------------------------------
# Bootstrap the application
# ---------------------------------------------------------------------------
bootstrap() {
    cd "$APP_DIR"

    log "Clearing expired cache..."
    php artisan cache:prune-stale-tags 2>/dev/null || true

    log "Running database migrations..."
    php artisan migrate --force --no-interaction

    log "Caching config..."
    php artisan config:cache

    log "Caching routes..."
    php artisan route:cache

    log "Caching views..."
    php artisan view:cache

    log "Caching events..."
    php artisan event:cache

    log "Linking storage..."
    php artisan storage:link --force 2>/dev/null || true

    log "Bootstrap complete."
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
main() {
    log "Starting Kutubxona.uz container (command: $*)"

    # Only wait for dependencies and bootstrap when running the app server
    case "${1:-php-fpm}" in
        php-fpm|php|artisan)
            wait_for_mysql
            wait_for_redis

            # Only run bootstrap on the main app container (not queue workers)
            # Queue workers share the same bootstrapped state
            if [ "${RUN_BOOTSTRAP:-true}" = "true" ]; then
                bootstrap
            fi
            ;;
        *)
            # For queue workers started directly (horizon, queue:work), skip bootstrap
            # Bootstrap is handled by the main app container
            wait_for_mysql
            wait_for_redis
            log "Skipping bootstrap for worker command: $*"
            ;;
    esac

    log "Executing command: $*"
    exec "$@"
}

main "$@"
