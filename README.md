# World Bank Metadata Editor — Docker Deployment

Complete Docker packaging and orchestration for the [World Bank Metadata Editor](https://github.com/worldbank/metadata-editor) and its companion [FastAPI Data Processing Service](https://github.com/worldbank/metadata-editor-fastapi).

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

---

## Architecture Overview

This deployment stack provides a complete, production-ready environment with all required services, REST APIs, and background workers enabled out of the box:

```
                   ┌──────────────────────────────────────────────┐
                   │              editor (:8080 -> :80)           │
                   │        Nginx + PHP 8.2-FPM (Bookworm)        │
  HTTP / REST API  │  • Web UI (/index.php/editor, /auth)         │
 ─────────────────►│  • REST API (/index.php/api/*, X-API-KEY)    │
                   │  • Swagger OpenAPI UI (/api-documentation)   │
                   └──────┬────────────────┬───────────────┬──────┘
                          │                │               │
                          │ MySQL 3306     │ HTTP 8000     │ Shared Volume
                          ▼                ▼               ▼
                   ┌──────────────┐ ┌──────────────┐ ┌─────────────────────────┐
                   │   db (:3306) │ │   fastapi    │ │  editor_datafiles       │
                   │  MySQL 8.0   │ │ Python 3.12  │ │  /var/www/metadata-     │
                   │  (or MariaDB)│ │ Uvicorn/R/Py │ │  editor/editor/datafiles│
                   └──────▲───────┘ └──────▲───────┘ └────────────▲────────────┘
                          │                │                      │
                          │ MySQL 3306     │ HTTP 8000            │ Shared Volume
                   ┌──────┴────────────────┴──────────────────────┴──────┐
                   │                       worker                        │
                   │        PHP 8.2 CLI (`php index.php cli/worker/run`) │
                   │  • Async job queue processor & heartbeat monitor    │
                   └─────────────────────────────────────────────────────┘
```

### Key Features
- **Always Builds from Latest Official Releases**: Both [`Dockerfile`](Dockerfile) and [`Dockerfile.fastapi`](Dockerfile.fastapi) query the GitHub Releases API (with a `git ls-remote --tags` fallback) at build time when `EDITOR_VERSION=latest` / `FASTAPI_VERSION=latest`, ensuring new builds always pull the newest upstream release while also supporting version pinning.
- **Zero-Touch Database Bootstrap & Future-Proof Migrations**:
  - Automatically initializes the database schema (`install/schema.mysql.sql`), creates the default administrator account, and optionally provisions an initial `X-API-KEY`.
  - Automatically synchronizes `$config['migration_version']` with the newest timestamp file in `application/migrations/*.php` and runs `php index.php cli/migrate latest` on every startup (`AUTO_MIGRATE=true`), ensuring seamless upgrades even if upstream `schema.mysql.sql` or `migration.php` lags behind migration files.
- **Full API & Background Processing Support**:
  - **Editor REST API** (`/index.php/api/*`) with `X-API-KEY` header authentication.
  - **FastAPI Microservice** (Python 3.12 + Pyreadstat, Pandas, DuckDB, R `haven`) for Stata/SPSS/CSV metadata extraction, summary statistics, and data exports.
  - **PHP Queue Worker** (`cli/worker/run`) sharing the `editor_datafiles` storage path so job heartbeats (`GET /index.php/api/jobs/worker_status`) and file operations work seamlessly across containers.

---

## Prerequisites

- **Docker Engine** 24.0+ (or Docker Desktop 4.25+)
- **Docker Compose** v2.20+
- At least **4 GB RAM** allocated to Docker (recommended for large Stata/SPSS file processing in FastAPI)

---

## Quick Start (Local Evaluation & Development)

### 1. Configure Environment Variables

Copy the example environment file:

```bash
cp .env.example .env
```

*(Optional)* Edit `.env` to customize ports, credentials, or set `ADMIN_API_KEY` if you want a pre-configured REST API key right away.

### 2. Build and Start the Stack

```bash
docker compose up -d --build
```

Monitor the startup and automatic database initialization:

```bash
docker compose logs -f editor
```

Wait until you see:
```text
[bootstrap-db] Connected to MySQL server.
[bootstrap-db] Importing install/schema.mysql.sql ...
[entrypoint] Running pending CodeIgniter database migrations (cli/migrate latest)...
[entrypoint] Initialization complete. Handing off to command: /usr/bin/supervisord ...
```

### 3. Access the Application

| Service | URL | Notes |
| :--- | :--- | :--- |
| **Metadata Editor Web UI** | [http://localhost:8080](http://localhost:8080) | Default login: `admin@example.com` / `AdminPassword123!` (or values in `.env`) |
| **Interactive API Docs** | [http://localhost:8080/index.php/api-documentation/editor](http://localhost:8080/index.php/api-documentation/editor) | Built-in OpenAPI / Redocly documentation |
| **Editor REST API** | `http://localhost:8080/index.php/api` | Authenticate with `X-API-KEY` header |
| **FastAPI Status** | `docker compose exec editor curl -s http://fastapi:8000/status` | Internal network (`http://fastapi:8000`) |

> **Alternative Interactive Web Installer**: If you prefer to create the initial admin user via the browser instead of automatic CLI provisioning, set `AUTO_INSTALL=false` in `.env` before starting, then navigate to `http://localhost:8080/index.php/install`.

---

## Using the REST APIs

### 1. Obtaining an API Key

You can generate or retrieve an `X-API-KEY` in two ways:

1. **Automated at Startup**: Set `ADMIN_API_KEY=your-secret-api-key` in `.env` prior to first startup. The bootstrap script automatically registers it in the `api_keys` table for the admin user.
2. **Via Web UI**: Log in at `http://localhost:8080`, click your profile menu in the top right, or go to **Site Administration → Users**, and generate an API key.

### 2. Testing REST API Endpoints

```bash
# 1. List projects in the Metadata Editor
curl -s -H "X-API-KEY: your-secret-api-key" \
  http://localhost:8080/index.php/api/editor

# 2. Check background queue worker status & FastAPI jobs
curl -s -H "X-API-KEY: your-secret-api-key" \
  http://localhost:8080/index.php/api/jobs/worker_status

# 3. Check FastAPI health from the editor container
docker compose exec editor curl -s http://fastapi:8000/status
```

---

## Building from Latest GitHub Releases vs. Pinning Versions

By default, `.env.example` sets:

```dotenv
EDITOR_VERSION=latest
FASTAPI_VERSION=latest
```

When set to `latest`, the Dockerfiles query the GitHub API (`https://api.github.com/repos/worldbank/.../releases/latest`) and fall back to sorting semantic version tags via `git ls-remote --tags` if rate-limited.

### Forcing a Fresh Build When a New Upstream Release Is Published

Because Docker caches build layers, pass `--no-cache` (or `--pull`) when rebuilding after a new upstream release is published on GitHub:

```bash
docker compose build --no-cache
docker compose up -d
```

Whenever the new container starts with `AUTO_MIGRATE=true`, it will automatically detect and apply any new database migrations introduced in the upstream release.

### Pinning Specific Versions for Reproducible Builds

To pin specific tags, releases, or commit SHAs in `.env`:

```dotenv
EDITOR_VERSION=v1.2.0
FASTAPI_VERSION=main
```

---

## Production Deployment with Persistent Resource Storage

For production deployments, use [`docker-compose.prod.yml`](docker-compose.prod.yml) on top of [`docker-compose.yml`](docker-compose.yml). This binds all stateful directories to a dedicated host path (`HOST_STORAGE_ROOT`, e.g., `/mnt/storage/metadata-editor`), adds container memory/CPU limits, and configures JSON log rotation.

### 1. Prepare Host Persistent Directories

Choose a persistent storage mount point on your server (e.g., `/mnt/storage/metadata-editor`) and create the required subdirectories:

```bash
export HOST_STORAGE_ROOT=/mnt/storage/metadata-editor

sudo mkdir -p \
  "${HOST_STORAGE_ROOT}/mysql" \
  "${HOST_STORAGE_ROOT}/datafiles" \
  "${HOST_STORAGE_ROOT}/files" \
  "${HOST_STORAGE_ROOT}/logs"

# Set ownership for the container www-data user (UID/GID 33 on Debian Bookworm)
sudo chown -R 33:33 \
  "${HOST_STORAGE_ROOT}/datafiles" \
  "${HOST_STORAGE_ROOT}/files" \
  "${HOST_STORAGE_ROOT}/logs"
```

| Host Directory | Container Path | Purpose |
| :--- | :--- | :--- |
| `${HOST_STORAGE_ROOT}/mysql` | `/var/lib/mysql` | MySQL 8.0 database tables and InnoDB logs |
| `${HOST_STORAGE_ROOT}/datafiles` | `/var/www/metadata-editor/editor/datafiles` | **Critical**: Uploaded microdata files, project packages, schemas, tmp files, and worker heartbeats (shared across `editor`, `worker`, and `fastapi`) |
| `${HOST_STORAGE_ROOT}/files` | `/var/www/metadata-editor/files` | Sessions, cache, public downloads, and thumbnails |
| `${HOST_STORAGE_ROOT}/logs` | `/var/www/metadata-editor/application/logs` | CodeIgniter application and audit logs |

### 2. Production `.env` Checklist

Before starting in production, update `.env` with strong secrets and your public HTTPS domain:

```dotenv
# Public HTTPS URL (behind your TLS reverse proxy / load balancer)
APP_BASE_URL=https://editor.yourdomain.org/
APP_HTTPS_ENABLED=true

# Host bind-mount root for docker-compose.prod.yml
HOST_STORAGE_ROOT=/mnt/storage/metadata-editor

# Generate strong secrets (e.g., `openssl rand -hex 32`)
ENCRYPTION_KEY=<64-char-hex-secret>
JWT_SECRET=<64-char-hex-secret>
FASTAPI_AUTH_TOKEN=<64-char-hex-secret>

# Database credentials
DB_ROOT_PASSWORD=<strong-root-password>
DB_PASSWORD=<strong-app-db-password>

# Initial administrator account
ADMIN_EMAIL=admin@yourdomain.org
ADMIN_PASSWORD=<strong-admin-password>
ADMIN_API_KEY=<optional-initial-api-key>
```

### 3. Launch Production Stack

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

### 4. Reverse Proxy / TLS Termination

In production, place Nginx, Traefik, Caddy, or an AWS Application Load Balancer in front of `127.0.0.1:8080` to terminate TLS (HTTPS). Ensure your proxy forwards standard headers and allows large microdata uploads:

```nginx
# Example upstream Nginx TLS Reverse Proxy block
server {
    listen 443 ssl http2;
    server_name editor.yourdomain.org;

    client_max_body_size 2048M;
    proxy_read_timeout 600s;
    proxy_send_timeout 600s;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

---

## Advanced Configuration

### MySQL vs. MariaDB

By default, this project uses **MySQL 8.0** (`mysql:8.0`) because the upstream database schema (`install/schema.mysql.sql`) is generated from MySQL 8.x with `utf8mb4_0900_ai_ci` collations and native JSON columns.

If your organization standardizes on **MariaDB**, you can switch `db.image` in `docker-compose.yml` to `mariadb:10.11` or `mariadb:11.4` (**MariaDB 10.10+** is required for `utf8mb4_0900_ai_ci` collation compatibility).

### OpenID Connect (OIDC) / OAuth2 Authentication

To enable single sign-on (Azure AD / Entra ID, Okta, Google, Keycloak), configure the `OIDC_*` variables in `.env`:

```dotenv
OIDC_ENABLED=true
OIDC_ISSUER=https://login.microsoftonline.com/<tenant-id>/v2.0
OIDC_CLIENT_ID=<client-id>
OIDC_CLIENT_SECRET=<client-secret>
OIDC_REDIRECT_URI=https://editor.yourdomain.org/index.php/auth/oidc_callback
OIDC_SCOPES=openid profile email
OIDC_AUTO_CREATE_USER=true
```

### AI Metadata Reviewer Service (Optional)

If you use the external AI Metadata Reviewer service:

```dotenv
METADATA_AI_SERVICE_ENABLED=true
METADATA_AI_SERVICE_URL=https://your-ai-reviewer-service/api/v1
METADATA_AI_SERVICE_API_KEY=<your-api-key>
```

---

## Backup, Restore & Upgrades

### Backing Up Persistent Data

1. **Database Backup**:
   ```bash
   docker compose exec -T db mysqldump -u root -p"$DB_ROOT_PASSWORD" --single-transaction metadata_editor > backup_db_$(date +%F).sql
   ```
2. **Project & Microdata Files Backup**:
   Archive the `datafiles` and `files` directories (or volumes):
   ```bash
   tar -czf backup_storage_$(date +%F).tar.gz -C "${HOST_STORAGE_ROOT}" datafiles files
   ```

### Upgrading to a Newer Release

1. Back up your database and persistent storage.
2. Rebuild the images (fetching the latest GitHub releases or your updated `EDITOR_VERSION` / `FASTAPI_VERSION` in `.env`):
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.prod.yml build --no-cache
   ```
3. Restart the containers:
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
   ```
   The `editor` container will automatically synchronize `migration.php` and execute `php index.php cli/migrate latest` to apply any new schema changes.

---

## License

This Docker packaging project is licensed under the [MIT License](LICENSE).
