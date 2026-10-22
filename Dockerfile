# syntax=docker/dockerfile:1

# ==============================================================================
# World Bank Metadata Editor — Web & Background Worker Image
# Linux (Debian Bookworm) + PHP 8.2-FPM + Nginx + Supervisor
# Always builds from the latest official GitHub release by default.
# ==============================================================================

FROM php:8.2-fpm-bookworm

LABEL org.opencontainers.image.title="World Bank Metadata Editor" \
      org.opencontainers.image.description="World Bank Metadata Editor web application, REST API, and background worker (PHP 8.2-FPM + Nginx)" \
      org.opencontainers.image.source="https://github.com/worldbank/metadata-editor" \
      org.opencontainers.image.licenses="MIT"

# Build arguments for source resolution
# Set EDITOR_VERSION=latest (default) to automatically pull the latest GitHub release tag,
# or pass a specific release tag/branch (e.g., --build-arg EDITOR_VERSION=v1.4.0).
ARG EDITOR_REPO="worldbank/metadata-editor"
ARG EDITOR_VERSION="latest"

# Runtime environment defaults (can be overridden in docker-compose.yml / .env)
ENV APP_ROOT="/var/www/metadata-editor/editor" \
    TZ="UTC" \
    PHP_MEMORY_LIMIT="512M" \
    PHP_MAX_EXECUTION_TIME="300" \
    PHP_POST_MAX_SIZE="2000M" \
    PHP_UPLOAD_MAX_FILESIZE="2000M" \
    EDITOR_STORAGE_PATH="/var/www/metadata-editor/editor/datafiles/editor" \
    EDITOR_DATA_API_URL="http://fastapi:8000/" \
    CLEAN_URLS="true" \
    AUTO_INSTALL="true" \
    AUTO_MIGRATE="true"

# 1. Install system dependencies, Nginx, Supervisor, MySQL client, and PHP build libs
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        default-mysql-client \
        git \
        jq \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libxml2-dev \
        libxslt1-dev \
        libzip-dev \
        nginx \
        procps \
        supervisor \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        mysqli \
        opcache \
        pcntl \
        xsl \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*

# Install Composer for dependency verification/updates if needed
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 2. Resolve and download the latest GitHub Release of worldbank/metadata-editor
WORKDIR /var/www/metadata-editor

RUN set -eux; \
    RESOLVED_TAG="${EDITOR_VERSION}"; \
    if [ "${RESOLVED_TAG}" = "latest" ]; then \
        echo "Resolving latest GitHub release for ${EDITOR_REPO}..."; \
        RESOLVED_TAG="$(curl -fsSL "https://api.github.com/repos/${EDITOR_REPO}/releases/latest" | jq -r '.tag_name // empty' || true)"; \
        if [ -z "${RESOLVED_TAG}" ] || [ "${RESOLVED_TAG}" = "null" ]; then \
            echo "Falling back to git ls-remote tag resolution..."; \
            RESOLVED_TAG="$(git ls-remote --tags --refs --sort='-v:refname' "https://github.com/${EDITOR_REPO}.git" | head -n1 | awk -F'/' '{print $NF}')"; \
        fi; \
        if [ -z "${RESOLVED_TAG}" ]; then \
            RESOLVED_TAG="main"; \
        fi; \
    fi; \
    echo "Downloading ${EDITOR_REPO} @ ${RESOLVED_TAG}..."; \
    git clone --depth 1 --branch "${RESOLVED_TAG}" "https://github.com/${EDITOR_REPO}.git" "${APP_ROOT}"; \
    echo "${RESOLVED_TAG}" > "${APP_ROOT}/RELEASE_VERSION"; \
    rm -rf "${APP_ROOT}/.git"

WORKDIR /var/www/metadata-editor/editor

# If vendor/autoload.php is missing in a future release archive, install Composer packages
RUN if [ ! -f vendor/autoload.php ] && [ -f composer.json ]; then \
        composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs; \
    fi

# 3. Copy Nginx, PHP-FPM, Supervisor, and CodeIgniter Docker configuration files
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-metadata-editor.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-docker-www.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Install environment-aware CodeIgniter configurations
COPY docker/config/database.docker.php /var/www/metadata-editor/editor/application/config/database.php
COPY docker/config/editor.docker.php /var/www/metadata-editor/editor/application/config/editor.php
COPY docker/config/email.docker.php /var/www/metadata-editor/editor/application/config/email.php
COPY docker/config/config-overrides.php /var/www/metadata-editor/editor/application/config/config-overrides.php

RUN echo "" >> /var/www/metadata-editor/editor/application/config/config.php \
    && echo "require __DIR__ . '/config-overrides.php';" >> /var/www/metadata-editor/editor/application/config/config.php

# 4. Copy entrypoint and database bootstrap scripts
COPY docker/scripts/bootstrap-db.php /usr/local/bin/bootstrap-db.php
COPY docker/scripts/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh /var/www/metadata-editor/editor/worker.sh \
    && mkdir -p \
        /run/php \
        /var/www/metadata-editor/editor/datafiles/editor/user-schemas \
        /var/www/metadata-editor/editor/datafiles/tmp/uploads \
        /var/www/metadata-editor/editor/datafiles/citations \
        /var/www/metadata-editor/editor/files/public \
        /var/www/metadata-editor/editor/files/sessions \
        /var/www/metadata-editor/editor/files/thumbnails \
        /var/www/metadata-editor/editor/files/captcha \
        /var/www/metadata-editor/editor/files/images \
        /var/www/metadata-editor/editor/logs \
    && chown -R www-data:www-data /var/www/metadata-editor/editor /run/php

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=45s --retries=3 \
    CMD curl -fsS http://127.0.0.1/auth/login >/dev/null || curl -fsS http://127.0.0.1/install >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
