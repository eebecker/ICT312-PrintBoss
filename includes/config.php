<?php
/**
 * PrintBoss configuration.
 * Default values match a standard WAMP / XAMPP install (root, no password).
 * Change DB_PASS if your MySQL root user has a password.
 */
declare(strict_types=1);

define('APP_NAME', 'PrintBoss');
define('APP_VERSION', '1.0.0');

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'printboss');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Base URL of the app, relative to the web root. Leave empty when the
// folder is served at the root of a virtual host.
define('BASE_PATH', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/'));

// Session hardening
define('SESSION_NAME', 'printboss_session');
define('SESSION_LIFETIME', 60 * 60 * 8); // 8 hours
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCK_SECONDS', 300);

date_default_timezone_set('Australia/Sydney');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
