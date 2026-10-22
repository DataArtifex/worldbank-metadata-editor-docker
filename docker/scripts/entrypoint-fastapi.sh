#!/usr/bin/env bash
set -euo pipefail

FASTAPI_ROOT="${FASTAPI_ROOT:-/var/www/metadata-editor/fastapi}"
STORAGE_PATH="${STORAGE_PATH:-/var/www/metadata-editor/editor/datafiles}"
HOST="${HOST:-0.0.0.0}"
PORT="${PORT:-8000}"

cd "${FASTAPI_ROOT}"

# Ensure .env exists with explicit STORAGE_PATH (mandatory for FastAPI service startup)
if [ ! -f "${FASTAPI_ROOT}/.env" ]; then
    if [ -f "${FASTAPI_ROOT}/.env.example" ]; then
        cp "${FASTAPI_ROOT}/.env.example" "${FASTAPI_ROOT}/.env"
    else
        touch "${FASTAPI_ROOT}/.env"
    fi
fi

# Update or append STORAGE_PATH, HOST, and PORT in .env
if grep -q "^STORAGE_PATH=" "${FASTAPI_ROOT}/.env"; then
    sed -i "s|^STORAGE_PATH=.*|STORAGE_PATH=${STORAGE_PATH}|" "${FASTAPI_ROOT}/.env"
else
    echo "STORAGE_PATH=${STORAGE_PATH}" >> "${FASTAPI_ROOT}/.env"
fi

if grep -q "^HOST=" "${FASTAPI_ROOT}/.env"; then
    sed -i "s|^HOST=.*|HOST=${HOST}|" "${FASTAPI_ROOT}/.env"
else
    echo "HOST=${HOST}" >> "${FASTAPI_ROOT}/.env"
fi

if grep -q "^PORT=" "${FASTAPI_ROOT}/.env"; then
    sed -i "s|^PORT=.*|PORT=${PORT}|" "${FASTAPI_ROOT}/.env"
else
    echo "PORT=${PORT}" >> "${FASTAPI_ROOT}/.env"
fi

mkdir -p "${STORAGE_PATH}" "${FASTAPI_ROOT}/logs" 2>/dev/null || true

echo "[entrypoint-fastapi] Starting Metadata Editor FastAPI service on ${HOST}:${PORT}..."
echo "[entrypoint-fastapi] STORAGE_PATH=${STORAGE_PATH}"

exec "$@"
