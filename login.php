<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    redirect('dashboard.php');
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = mb_strtolower(post_str('email', 190));
    $password = (string)($_POST['password'] ?? '');

    if (login_is_locked()) {
        $errors[] = 'Too many failed attempts. Please wait a few minutes and try again.';
    } elseif ($email === '' || $password === '') {
        $errors[] = 'Email and password are required.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Lock the account row so separate browsers share one failure counter.
            $u = row('SELECT *, (login_locked_until > NOW()) AS account_locked FROM users WHERE email = ? FOR UPDATE', [$email]);
            if ($u && (int)$u['account_locked'] === 1) {
                $pdo->commit();
                $errors[] = 'Too many failed attempts. Please wait a few minutes and try again.';
            } elseif ($u && (int)$u['is_active'] === 1 && password_verify($password, $u['password_hash'])) {
                if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
                }
                q('UPDATE users SET failed_login_attempts = 0, login_locked_until = NULL WHERE id = ?', [$u['id']]);
                $pdo->commit();
                login_user($u);
                flash('success', 'Welcome back, ' . $u['full_name'] . '!');
                redirect('dashboard.php');
            } else {
                if ($u) {
                    // An expired lock starts a fresh set of attempts.
                    $attempts = $u['login_locked_until'] !== null ? 1 : (int)$u['failed_login_attempts'] + 1;
                    q('UPDATE users SET failed_login_attempts = ?, login_locked_until = CASE WHEN ? THEN DATE_ADD(NOW(), INTERVAL ? SECOND) ELSE NULL END WHERE id = ?', [
                        $attempts, $attempts >= LOGIN_MAX_ATTEMPTS ? 1 : 0, LOGIN_LOCK_SECONDS, $u['id'],
                    ]);
                }
                $pdo->commit();
                login_record_failure();
                $errors[] = 'Incorrect email or password.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Login failed: ' . $e->getMessage());
            $errors[] = 'Could not sign in. Please try again.';
        }
    }
}
$flash = take_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in | PrintBoss</title>
<link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/img/favicon-32.png">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-brand">
      <img src="<?= BASE_PATH ?>/assets/img/icon-192.png" alt="PrintBoss logo">
      <div><h1>PrintBoss</h1><p>Run your 3D printing business like a boss.</p></div>
    </div>
    <?php if ($flash): ?><div class="toast toast-<?= e($flash['type']) ?>" style="margin:0 0 14px"><?= e($flash['text']) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="error-box"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
    <form method="post" action="<?= BASE_PATH ?>/login.php" novalidate>
      <?= csrf_field() ?>
      <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($email) ?>" required autocomplete="email" autofocus></div>
      <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" required autocomplete="current-password"></div>
      <button class="btn btn-block" type="submit">Sign in</button>
    </form>
    <div class="auth-footer">
      New here? <a href="<?= BASE_PATH ?>/register.php">Create an account</a>
      <div class="small" style="margin-top:10px">Demo account: <span class="mono">demo@printboss.local</span> / <span class="mono">Demo1234!</span></div>
    </div>
  </div>
</div>
</body>
</html>
