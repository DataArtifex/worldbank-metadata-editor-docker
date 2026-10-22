<?php

/*
|--------------------------------------------------------------------------
| Docker Environment Overrides (appended to application/config/config.php)
|--------------------------------------------------------------------------
*/
if (!defined('DOCKER_CONFIG_OVERRIDES_APPLIED')) {
    define('DOCKER_CONFIG_OVERRIDES_APPLIED', true);

    // 1. Reverse Proxy HTTPS detection (e.g., behind Nginx/Traefik/Cloudflare/ALB)
    if (
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
        (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
    ) {
        $_SERVER['HTTPS'] = 'on';
    }

    // 2. Explicit Base URL override (optional; auto-detected when empty)
    if ($env_base_url = getenv('APP_BASE_URL')) {
        $config['base_url'] = rtrim($env_base_url, '/') . '/';
    }

    // 3. Clean URLs (hide index.php by default since Nginx rewrite rules are active)
    $clean_urls = getenv('CLEAN_URLS');
    if ($clean_urls === false || $clean_urls === '' || filter_var($clean_urls, FILTER_VALIDATE_BOOLEAN)) {
        $config['index_page'] = '';
    } else {
        $config['index_page'] = 'index.php';
    }

    // 4. Encryption key override
    if ($env_enc_key = getenv('APP_ENCRYPTION_KEY')) {
        $config['encryption_key'] = $env_enc_key;
    }

    // 5. Cookie security and reverse proxy IPs
    if ($env_cookie_secure = getenv('COOKIE_SECURE')) {
        $config['cookie_secure'] = filter_var($env_cookie_secure, FILTER_VALIDATE_BOOLEAN);
    }
    if ($env_proxy_ips = getenv('TRUSTED_PROXY_IPS')) {
        $config['proxy_ips'] = $env_proxy_ips;
    }

    // 6. Log threshold override (1 = Error, 2 = Debug, 3 = Info, 4 = All)
    if (($env_log_threshold = getenv('LOG_THRESHOLD')) !== false && $env_log_threshold !== '') {
        $config['log_threshold'] = (int) $env_log_threshold;
    }
}
