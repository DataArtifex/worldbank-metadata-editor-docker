#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/metadata-editor/editor}"
STORAGE_PATH="${EDITOR_STORAGE_PATH:-${APP_ROOT}/datafiles/editor}"

echo "[entrypoint] Starting World Bank Metadata Editor container..."
echo "[entrypoint] App root: ${APP_ROOT}"

# 1. Ensure runtime & persistent directories exist with proper permissions
mkdir -p \
    /run/php \
    /run/nginx \
    "${APP_ROOT}/datafiles/editor/user-schemas" \
    "${APP_ROOT}/datafiles/tmp/uploads" \
    "${APP_ROOT}/datafiles/citations" \
    "${APP_ROOT}/files/public" \
    "${APP_ROOT}/files/sessions" \
    "${APP_ROOT}/files/thumbnails" \
    "${APP_ROOT}/files/captcha" \
    "${APP_ROOT}/files/images" \
    "${APP_ROOT}/logs" \
    "${STORAGE_PATH}/tmp" \
    "${STORAGE_PATH}/user-schemas"

# Create protective index.html files if volumes were mounted empty
for dir in "${APP_ROOT}/datafiles" "${APP_ROOT}/files" "${APP_ROOT}/logs"; do
    if [ ! -f "${dir}/index.html" ]; then
        echo "<html><head><title>403 Forbidden</title></head><body><p>Directory access is forbidden.</p></body></html>" > "${dir}/index.html"
    fi
done

chown -R www-data:www-data \
    /run/php \
    "${APP_ROOT}/datafiles" \
    "${APP_ROOT}/files" \
    "${APP_ROOT}/logs" \
    "${APP_ROOT}/application/config/migration.php" 2>/dev/null || true

chmod -R 775 \
    "${APP_ROOT}/datafiles" \
    "${APP_ROOT}/files" \
    "${APP_ROOT}/logs" 2>/dev/null || true

# 2. Run database bootstrap & migrations unless explicitly skipped
if [ "${SKIP_DB_BOOTSTRAP:-false}" != "true" ]; then
    php /usr/local/bin/bootstrap-db.php
fi

# Re-ensure ownership on logs and tmp after CLI bootstrap runs as root
chown -R www-data:www-data "${APP_ROOT}/datafiles" "${APP_ROOT}/files" "${APP_ROOT}/logs" 2>/dev/null || true

# 3. Execute requested command (web supervisord or CLI worker)
if [ "$#" -gt 0 ] && [ "$1" = "worker" ]; then
    shift
    POLL_INTERVAL="${WORKER_POLL_INTERVAL:-5}"
    MAX_JOBS="${WORKER_MAX_JOBS:-50}"
    echo "[entrypoint] Starting background queue worker as www-data (poll=${POLL_INTERVAL}s, max-jobs=${MAX_JOBS})..."
    cd "${APP_ROOT}"
    exec su -s /bin/bash www-data -c "php index.php cli/worker/run --poll-interval=${POLL_INTERVAL} --max-jobs=${MAX_JOBS} $*"
fi

echo "[entrypoint] Launching command: $*"
exec "$@"
