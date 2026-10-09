<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    redirect('dashboard.php');
}

$errors = [];
$form = ['full_name' => '', 'email' => '', 'business_name' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form['full_name'] = post_str('full_name', 120);
    $form['email'] = mb_strtolower(post_str('email', 190));
    $form['business_name'] = post_str('business_name', 120);
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');
    $consent = isset($_POST['privacy_consent']);

    if ($form['full_name'] === '') {
        $errors[] = 'Your name is required.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $errors[] = 'Password must be at least 8 characters and include letters and numbers.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (!$consent) {
        $errors[] = 'Please accept the privacy notice to create an account.';
    }
    if (!$errors && row('SELECT id FROM users WHERE email = ?', [$form['email']])) {
        $errors[] = 'An account with that email already exists.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        q('INSERT INTO users (email, password_hash, full_name) VALUES (?,?,?)', [
            $form['email'], password_hash($password, PASSWORD_DEFAULT), $form['full_name'],
        ]);
        $uid = (int)$pdo->lastInsertId();
        q('INSERT INTO profiles (user_id, business_name, business_email) VALUES (?,?,?)', [$uid, $form['business_name'], $form['email']]);
        $pdo->commit();
        login_user(['id' => $uid]);
        flash('success', 'Account created. Welcome to PrintBoss!');
        redirect('settings.php?welcome=1');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create account | PrintBoss</title>
<link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/img/favicon-32.png">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-brand">
      <img src="<?= BASE_PATH ?>/assets/img/icon-192.png" alt="PrintBoss logo">
      <div><h1>Create your account</h1><p>Free for makers. Takes one minute.</p></div>
    </div>
    <?php if ($errors): ?><div class="error-box"><ul style="margin:0;padding-left:18px"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" action="<?= BASE_PATH ?>/register.php" novalidate>
      <?= csrf_field() ?>
      <div class="field"><label for="full_name">Your name</label><input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" required maxlength="120"></div>
      <div class="field"><label for="business_name">Business name (optional)</label><input type="text" id="business_name" name="business_name" value="<?= e($form['business_name']) ?>" maxlength="120" placeholder="e.g. Layer Lab 3D"></div>
      <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($form['email']) ?>" required autocomplete="email"></div>
      <div class="field-row">
        <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" required minlength="8" autocomplete="new-password"></div>
        <div class="field"><label for="password_confirm">Confirm password</label><input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password"></div>
      </div>
      <p class="help">At least 8 characters with letters and numbers. Passwords are stored as bcrypt hashes, never in plain text.</p>
      <div class="field"><label class="check"><input type="checkbox" name="privacy_consent" value="1"> I have read the <a href="<?= BASE_PATH ?>/privacy.php" target="_blank">privacy notice</a> and agree to PrintBoss storing my business data.</label></div>
      <button class="btn btn-block" type="submit">Create account</button>
    </form>
    <div class="auth-footer">Already registered? <a href="<?= BASE_PATH ?>/login.php">Sign in</a></div>
  </div>
</div>
</body>
</html>
