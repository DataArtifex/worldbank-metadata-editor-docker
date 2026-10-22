<?php
/**
 * Docker Database Bootstrap & Migration Helper
 *
 * Responsibilities:
 * 1. Wait for MySQL/MariaDB server connectivity and ensure target database exists.
 * 2. Sync $config['migration_version'] in application/config/migration.php with the
 *    highest migration file present in application/migrations/*.php (future-proofs
 *    against schema.mysql.sql or migration.php lagging behind new migration files).
 * 3. If AUTO_INSTALL=true and database has no `configurations` table:
 *    - Import install/schema.mysql.sql
 *    - Run `php index.php cli/migrate latest`
 *    - Optionally create initial Site Administrator user and REST API key.
 * 4. If database is already initialized and AUTO_MIGRATE=true:
 *    - Run `php index.php cli/migrate latest` to apply any pending migrations.
 */

$app_root = getenv('APP_ROOT') ?: '/var/www/metadata-editor/editor';
$db_host  = getenv('DB_HOST') ?: 'db';
$db_port  = (int) (getenv('DB_PORT') ?: 3306);
$db_name  = getenv('DB_NAME') ?: 'metadata_editor';
$db_user  = getenv('DB_USER') ?: 'editor_user';
$db_pass  = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';

$auto_install = filter_var(getenv('AUTO_INSTALL') ?: 'true', FILTER_VALIDATE_BOOLEAN);
$auto_migrate = filter_var(getenv('AUTO_MIGRATE') ?: 'true', FILTER_VALIDATE_BOOLEAN);

function log_info($msg) {
    fwrite(STDOUT, "[bootstrap-db] {$msg}\n");
}

function log_err($msg) {
    fwrite(STDERR, "[bootstrap-db] ERROR: {$msg}\n");
}

/**
 * Step 1: Dynamically sync $config['migration_version'] in application/config/migration.php
 * to match the latest timestamp migration file in application/migrations/*.php.
 */
function sync_migration_config_version($app_root) {
    $migrations_dir = $app_root . '/application/migrations';
    $migration_cfg  = $app_root . '/application/config/migration.php';

    if (!is_dir($migrations_dir) || !is_writable($migration_cfg)) {
        return;
    }

    $files = glob($migrations_dir . '/*.php');
    $max_version = '0';
    foreach ($files as $file) {
        $base = basename($file, '.php');
        if (preg_match('/^(\d{14})_/', $base, $m)) {
            if (strcmp($m[1], $max_version) > 0) {
                $max_version = $m[1];
            }
        }
    }

    if ($max_version === '0') {
        return;
    }

    $contents = file_get_contents($migration_cfg);
    if ($contents === false) {
        return;
    }

    $updated = preg_replace(
        '/(\$config\[\'migration_version\'\]\s*=\s*)[0-9\'"]+(\s*;)/',
        '${1}' . $max_version . '${2}',
        $contents
    );

    if ($updated !== null && $updated !== $contents) {
        file_put_contents($migration_cfg, $updated);
        log_info("Synchronized migration.php version to latest migration file on disk ({$max_version}).");
    }
}

sync_migration_config_version($app_root);

/**
 * Step 2: Wait for database connectivity
 */
$max_retries = (int) (getenv('DB_WAIT_TIMEOUT') ?: 60);
$mysqli = null;

log_info("Waiting for database at {$db_host}:{$db_port}...");
for ($i = 1; $i <= $max_retries; $i++) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = @mysqli_init();
    if ($mysqli && @$mysqli->real_connect($db_host, $db_user, $db_pass, '', $db_port)) {
        log_info("Connected to database server ({$mysqli->server_info}).");
        break;
    }
    if ($i === $max_retries) {
        $err = $mysqli ? $mysqli->connect_error : 'mysqli_init failed';
        log_err("Could not connect to database after {$max_retries} attempts: {$err}");
        exit(1);
    }
    sleep(1);
}

// Ensure target database exists
$escaped_db = str_replace('`', '``', $db_name);
if (!$mysqli->select_db($db_name)) {
    log_info("Creating database `{$db_name}`...");
    if (!$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$escaped_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        log_err("Failed to create database `{$db_name}`: " . $mysqli->error);
        exit(1);
    }
    $mysqli->select_db($db_name);
}
$mysqli->set_charset('utf8mb4');

/**
 * Helper to check if a table exists
 */
function db_table_exists($mysqli, $table) {
    $esc = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '{$esc}'");
    return ($res && $res->num_rows > 0);
}

/**
 * Helper to execute `php index.php cli/migrate latest`
 */
function run_cli_migrations($app_root) {
    log_info("Running pending CodeIgniter database migrations (cli/migrate latest)...");
    $cmd = 'cd ' . escapeshellarg($app_root) . ' && php index.php cli/migrate latest 2>&1';
    $output = array();
    $return_var = 0;
    exec($cmd, $output, $return_var);
    foreach ($output as $line) {
        if (trim($line) !== '') {
            log_info("  [migrate] " . $line);
        }
    }
    if ($return_var !== 0) {
        log_err("cli/migrate latest exited with code {$return_var}");
        return false;
    }
    return true;
}

/**
 * Step 3: Check installation state
 */
$has_configurations = db_table_exists($mysqli, 'configurations');

if (!$has_configurations) {
    if (!$auto_install) {
        log_info("Database is empty and AUTO_INSTALL=false. Open /install in your browser to run the web installer.");
        $mysqli->close();
        exit(0);
    }

    $schema_file = $app_root . '/install/schema.mysql.sql';
    if (!file_exists($schema_file)) {
        log_err("Schema file not found at {$schema_file}");
        exit(1);
    }

    log_info("Initializing fresh database schema from {$schema_file}...");
    $lines = file($schema_file);
    $templine = '';
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
            continue;
        }
        $templine .= $line;
        if (substr($trimmed, -1) === ';') {
            if (!$mysqli->query($templine)) {
                log_err("SQL error executing statement: " . $mysqli->error);
            }
            $templine = '';
        }
    }
    log_info("Base schema imported successfully.");

    // Always run cli/migrate latest immediately after schema import so any migrations
    // newer than schema.mysql.sql (e.g., 20260911000001_collection_project_acl_table.php
    // or future release migrations) are applied automatically.
    run_cli_migrations($app_root);
} else {
    log_info("Existing database installation detected.");
    if ($auto_migrate) {
        run_cli_migrations($app_root);
    }
}

/**
 * Step 4: Optional Admin User & API Key Bootstrap
 * If AUTO_INSTALL=true and no user exists yet in `users`, create the initial Site Administrator
 * when ADMIN_EMAIL and ADMIN_PASSWORD are provided.
 */
if ($auto_install && db_table_exists($mysqli, 'users')) {
    $res = $mysqli->query("SELECT COUNT(*) AS cnt FROM `users`");
    $row = $res ? $res->fetch_assoc() : array('cnt' => 0);
    $user_count = (int) $row['cnt'];

    $admin_email = trim((string) (getenv('ADMIN_EMAIL') ?: ''));
    $admin_pass  = (string) (getenv('ADMIN_PASSWORD') ?: '');

    if ($user_count === 0 && $admin_email !== '' && $admin_pass !== '') {
        log_info("Creating initial Site Administrator account ({$admin_email})...");

        require_once $app_root . '/application/libraries/PasswordHash.php';
        $hasher = new PasswordHash(8, FALSE);
        $password_hash = $hasher->HashPassword($admin_pass);

        $first_name = trim((string) (getenv('ADMIN_FIRST_NAME') ?: 'Site'));
        $last_name  = trim((string) (getenv('ADMIN_LAST_NAME') ?: 'Administrator'));
        $username   = strtolower($first_name . ' ' . $last_name);
        $now        = time();

        $stmt = $mysqli->prepare(
            "INSERT INTO `users` (`ip_address`, `username`, `password`, `email`, `created_on`, `last_login`, `active`)
             VALUES ('127.0.0.1', ?, ?, ?, ?, ?, 1)"
        );
        $stmt->bind_param('sssii', $username, $password_hash, $admin_email, $now, $now);
        if ($stmt->execute()) {
            $user_id = (int) $mysqli->insert_id;
            $stmt->close();

            // Insert user metadata (`meta` table)
            $stmt_meta = $mysqli->prepare(
                "INSERT INTO `meta` (`user_id`, `first_name`, `last_name`, `company`, `phone`, `country`)
                 VALUES (?, ?, ?, 'World Bank Metadata Editor', '', '')"
            );
            $stmt_meta->bind_param('iss', $user_id, $first_name, $last_name);
            $stmt_meta->execute();
            $stmt_meta->close();

            // Assign Admin role (role_id = 1) in `user_roles`
            $mysqli->query("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES ({$user_id}, 1)");

            // Mark `app_installed` in `configurations`
            $mysqli->query("INSERT IGNORE INTO `configurations` (`name`, `value`) VALUES ('app_installed', '{$now}')");

            log_info("Site Administrator created (user_id={$user_id}, email={$admin_email}).");

            // Optional initial API key for immediate programmatic REST API usage
            $admin_api_key = trim((string) (getenv('ADMIN_API_KEY') ?: ''));
            if ($admin_api_key !== '') {
                $stmt_key = $mysqli->prepare(
                    "INSERT IGNORE INTO `api_keys` (`api_key`, `level`, `ignore_limits`, `date_created`, `user_id`, `is_private_key`)
                     VALUES (?, 0, 1, ?, ?, 0)"
                );
                $stmt_key->bind_param('sii', $admin_api_key, $now, $user_id);
                $stmt_key->execute();
                $stmt_key->close();
                log_info("Registered initial REST API key for {$admin_email}.");
            }
        } else {
            log_err("Failed to create admin user: " . $stmt->error);
            $stmt->close();
        }
    } elseif ($user_count === 0) {
        log_info("No users in database yet. Visit /install/create_user in your browser to create the Site Administrator account.");
    }
}

$mysqli->close();
log_info("Database bootstrap complete.");
