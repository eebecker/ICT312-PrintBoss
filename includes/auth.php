<?php
/**
 * Session, authentication and CSRF helpers.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();

    // Expire idle sessions
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $user = row('SELECT id, email, full_name, role, is_active FROM users WHERE id = ?', [(int)$_SESSION['user_id']]);
        if (!$user || (int)$user['is_active'] !== 1) {
            logout_user();
            return null;
        }
    }
    return $user;
}

function user_id(): int
{
    $u = current_user();
    return $u ? (int)$u['id'] : 0;
}

/** Redirect to the login page when nobody is signed in. */
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'Please sign in to continue.'];
        header('Location: ' . BASE_PATH . '/login.php');
        exit;
    }
    return $u;
}

function login_user(array $user): void
{
    start_session();
    session_regenerate_id(true);          // prevent session fixation
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['login_attempts'] = 0;
    unset($_SESSION['locked_until']);
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [(int)$user['id']]);
}

function logout_user(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** CSRF token stored in the session and embedded in every form. */
function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Abort the request when the token is missing or wrong. */
function csrf_check(): void
{
    start_session();
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(403);
        exit('Invalid or missing security token. Please go back and try again.');
    }
}

/** Simple brute-force protection for the login form. */
function login_is_locked(): bool
{
    start_session();
    return isset($_SESSION['locked_until']) && time() < (int)$_SESSION['locked_until'];
}

function login_record_failure(): void
{
    start_session();
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    if ($_SESSION['login_attempts'] >= LOGIN_MAX_ATTEMPTS) {
        $_SESSION['locked_until'] = time() + LOGIN_LOCK_SECONDS;
        $_SESSION['login_attempts'] = 0;
    }
}
