<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------
| DOCKER ENVIRONMENT DATABASE CONFIGURATION
| -------------------------------------------------------------------
| Reads database connection settings from container environment variables.
*/
$active_group = 'default';
$query_builder = TRUE;

$db_host = getenv('DB_HOST') ?: 'db';
$db_port = getenv('DB_PORT') ?: '3306';
$db_hostname = (strpos($db_host, ':') === false && $db_port !== '3306')
    ? $db_host . ':' . $db_port
    : $db_host;

$db_encrypt = FALSE;
if (filter_var(getenv('DB_SSL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
    $db_encrypt = array(
        'ssl_key'    => getenv('DB_SSL_KEY') ?: NULL,
        'ssl_cert'   => getenv('DB_SSL_CERT') ?: NULL,
        'ssl_ca'     => getenv('DB_SSL_CA') ?: NULL,
        'ssl_capath' => getenv('DB_SSL_CAPATH') ?: NULL,
        'ssl_cipher' => getenv('DB_SSL_CIPHER') ?: NULL,
        'ssl_verify' => filter_var(getenv('DB_SSL_VERIFY') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    );
}

$db['default'] = array(
    'dsn'          => '',
    'hostname'     => $db_hostname,
    'port'         => (int) $db_port,
    'username'     => getenv('DB_USER') ?: 'editor_user',
    'password'     => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '',
    'database'     => getenv('DB_NAME') ?: 'metadata_editor',
    'dbdriver'     => getenv('DB_DRIVER') ?: 'mysqli',
    'dbprefix'     => '',
    'pconnect'     => FALSE,
    'db_debug'     => filter_var(getenv('DB_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'cache_on'     => FALSE,
    'cachedir'     => '',
    'char_set'     => 'utf8mb4',
    'dbcollat'     => 'utf8mb4_unicode_ci',
    'swap_pre'     => '',
    'encrypt'      => $db_encrypt,
    'compress'     => FALSE,
    'stricton'     => FALSE,
    'failover'     => array(),
    'save_queries' => FALSE
);
