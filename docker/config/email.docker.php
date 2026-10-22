<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Outbound Email Configuration (Docker Environment)
|--------------------------------------------------------------------------
*/

$smtp_user = getenv('SMTP_USER') !== false ? getenv('SMTP_USER') : '';
$smtp_pass = getenv('SMTP_PASS') !== false ? getenv('SMTP_PASS') : '';
$smtp_auth_env = getenv('SMTP_AUTH');
$smtp_auth = ($smtp_auth_env !== false && $smtp_auth_env !== '')
    ? filter_var($smtp_auth_env, FILTER_VALIDATE_BOOLEAN)
    : ($smtp_user !== '' && $smtp_pass !== '');

$config['useragent']         = 'PHPMailer';
$config['protocol']          = getenv('SMTP_PROTOCOL') ?: 'smtp';
$config['mailpath']          = '/usr/sbin/sendmail';
$config['smtp_host']         = getenv('SMTP_HOST') ?: 'localhost';
$config['smtp_auth']         = $smtp_auth;
$config['smtp_user']         = $smtp_user;
$config['smtp_email']        = getenv('SMTP_EMAIL') ?: ($smtp_user ?: 'noreply@localhost.local');
$config['smtp_pass']         = $smtp_pass;
$config['smtp_port']         = (int) (getenv('SMTP_PORT') ?: 25);
$config['smtp_timeout']      = (int) (getenv('SMTP_TIMEOUT') ?: 30);
$config['smtp_crypto']       = getenv('SMTP_CRYPTO') !== false ? getenv('SMTP_CRYPTO') : '';
$config['smtp_debug']        = (int) (getenv('SMTP_DEBUG') ?: 0);
$config['debug_output']      = 'error_log';
$config['smtp_auto_tls']     = filter_var(getenv('SMTP_AUTO_TLS') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$config['smtp_conn_options'] = array();
$config['wordwrap']          = true;
$config['wrapchars']         = 76;
$config['mailtype']          = 'html';
$config['charset']           = null;
$config['validate']          = true;
$config['priority']          = 3;
$config['crlf']              = "\n";
$config['newline']           = "\n";
$config['bcc_batch_mode']    = false;
$config['bcc_batch_size']    = 200;
$config['encoding']          = '8bit';
