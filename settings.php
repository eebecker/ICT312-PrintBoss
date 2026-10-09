<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
$uid = (int)$user['id'];
$profile = get_profile($uid);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action', 20);

    if ($action === 'profile') {
        $currency = strtoupper(post_str('currency', 3));
        if (!in_array($currency, ['AUD', 'USD', 'EUR', 'GBP', 'NZD', 'BRL'], true)) $currency = 'AUD';
        $email = post_str('business_email', 190);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Business email is not valid.';
        } else {
            q('UPDATE profiles SET business_name=?, business_abn=?, business_email=?, business_phone=?, business_address=?, country=?, currency=?, bank_name=?, bank_account_name=?, bank_bsb=?, bank_account_number=?, invoice_prefix=?, gst_rate=?, default_gst_enabled=?, default_payment_terms=?, default_payment_instructions=?, default_invoice_notes=?, default_electricity_cost=?, default_labour_rate=?, default_depreciation_rate=?, default_profit_margin=?, default_failed_risk=?, onboarding_completed=1 WHERE user_id=?', [
                post_str('business_name', 120), post_str('business_abn', 20), $email, post_str('business_phone', 40), post_str('business_address', 255),
                post_str('country', 60) ?: 'Australia', $currency, post_str('bank_name', 80), post_str('bank_account_name', 120), post_str('bank_bsb', 10), post_str('bank_account_number', 20),
                preg_replace('/[^A-Za-z0-9]/', '', post_str('invoice_prefix', 10)) ?: 'INV', min(100, max(0, post_num('gst_rate', 10))), isset($_POST['default_gst_enabled']) ? 1 : 0,
                post_str('default_payment_terms', 60), nullable(post_str('default_payment_instructions', 2000)), nullable(post_str('default_invoice_notes', 2000)),
                max(0, post_num('default_electricity_cost')), max(0, post_num('default_labour_rate')), max(0, post_num('default_depreciation_rate')),
                min(95, max(0, post_num('default_profit_margin'))), min(100, max(0, post_num('default_failed_risk'))), $uid,
            ]);
            q('UPDATE users SET full_name = ? WHERE id = ?', [post_str('full_name', 120) ?: $user['full_name'], $uid]);
            flash('success', 'Settings saved.');
            redirect('settings.php');
        }
    }

    if ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $u = row('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!password_verify($current, $u['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            $errors[] = 'New password must be at least 8 characters with letters and numbers.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
            flash('success', 'Password changed.');
            redirect('settings.php');
        }
    }

    if ($action === 'delete_account') {
        $pwd = (string)($_POST['confirm_delete_password'] ?? '');
        $u = row('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!password_verify($pwd, $u['password_hash'])) {
            $errors[] = 'Enter your current password to delete the account.';
        } else {
            q('DELETE FROM users WHERE id = ?', [$uid]); // cascades to every table
            logout_user();
            flash('success', 'Your account and all its data have been deleted.');
            redirect('login.php');
        }
    }
}
$profile = get_profile($uid);
$user = current_user();
$welcome = isset($_GET['welcome']);

$pageTitle = 'Settings';
$pageDescription = 'Business profile, invoice defaults and calculator defaults.';
require __DIR__ . '/includes/header.php';
?>

<?php if ($welcome): ?><div class="toast toast-info" style="margin:0 0 14px">Welcome! Fill in your business details so invoices and the calculator use the right defaults.</div><?php endif; ?>
<?php if ($errors): ?><div class="error-box"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<form method="post" action="<?= BASE_PATH ?>/settings.php">
<?= csrf_field() ?><input type="hidden" name="action" value="profile">
<div class="grid grid-2">
  <div class="card">
    <h3>Business profile</h3>
    <div class="field-row">
      <div class="field"><label>Your name</label><input type="text" name="full_name" value="<?= e($user['full_name']) ?>" maxlength="120" required></div>
      <div class="field"><label>Business name</label><input type="text" name="business_name" value="<?= e($profile['business_name']) ?>" maxlength="120"></div>
      <div class="field"><label>ABN</label><input type="text" name="business_abn" value="<?= e($profile['business_abn']) ?>" maxlength="20"></div>
      <div class="field"><label>Business email</label><input type="email" name="business_email" value="<?= e($profile['business_email']) ?>" maxlength="190"></div>
      <div class="field"><label>Phone</label><input type="text" name="business_phone" value="<?= e($profile['business_phone']) ?>" maxlength="40"></div>
      <div class="field"><label>Currency</label><select name="currency"><?php foreach (['AUD', 'USD', 'EUR', 'GBP', 'NZD', 'BRL'] as $c): ?><option <?= $profile['currency'] === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field"><label>Address</label><input type="text" name="business_address" value="<?= e($profile['business_address']) ?>" maxlength="255"></div>
    <div class="field"><label>Country</label><input type="text" name="country" value="<?= e($profile['country']) ?>" maxlength="60"></div>
  </div>

  <div class="card">
    <h3>Invoice defaults</h3>
    <div class="field-row">
      <div class="field"><label>Invoice prefix</label><input type="text" name="invoice_prefix" value="<?= e($profile['invoice_prefix']) ?>" maxlength="10"><p class="help">Numbers continue from the highest existing invoice.</p></div>
      <div class="field"><label>GST rate (%)</label><input type="number" name="gst_rate" value="<?= e($profile['gst_rate']) ?>" min="0" max="100" step="0.01"></div>
      <div class="field"><label>Payment terms</label><input type="text" name="default_payment_terms" value="<?= e($profile['default_payment_terms']) ?>" maxlength="60"></div>
      <div class="field" style="padding-top:22px"><label class="check"><input type="checkbox" name="default_gst_enabled" value="1" <?= (int)$profile['default_gst_enabled'] ? 'checked' : '' ?>> Add GST by default</label></div>
    </div>
    <div class="field"><label>Payment instructions</label><textarea name="default_payment_instructions" maxlength="2000"><?= e($profile['default_payment_instructions']) ?></textarea></div>
    <div class="field"><label>Default notes to customer</label><textarea name="default_invoice_notes" maxlength="2000"><?= e($profile['default_invoice_notes']) ?></textarea></div>
    <h3 style="margin-top:8px">Bank details (printed on invoices)</h3>
    <div class="field-row">
      <div class="field"><label>Bank</label><input type="text" name="bank_name" value="<?= e($profile['bank_name']) ?>" maxlength="80"></div>
      <div class="field"><label>Account name</label><input type="text" name="bank_account_name" value="<?= e($profile['bank_account_name']) ?>" maxlength="120"></div>
      <div class="field"><label>BSB</label><input type="text" name="bank_bsb" value="<?= e($profile['bank_bsb']) ?>" maxlength="10"></div>
      <div class="field"><label>Account number</label><input type="text" name="bank_account_number" value="<?= e($profile['bank_account_number']) ?>" maxlength="20"></div>
    </div>
  </div>

  <div class="card span-2">
    <h3>Calculator defaults</h3>
    <div class="field-row-3">
      <div class="field"><label>Electricity cost per kWh</label><input type="number" name="default_electricity_cost" value="<?= e($profile['default_electricity_cost']) ?>" min="0" step="0.0001"></div>
      <div class="field"><label>Labour hourly rate</label><input type="number" name="default_labour_rate" value="<?= e($profile['default_labour_rate']) ?>" min="0" step="0.01"></div>
      <div class="field"><label>Depreciation per hour</label><input type="number" name="default_depreciation_rate" value="<?= e($profile['default_depreciation_rate']) ?>" min="0" step="0.01"></div>
      <div class="field"><label>Profit margin (%)</label><input type="number" name="default_profit_margin" value="<?= e($profile['default_profit_margin']) ?>" min="0" max="95" step="1"></div>
      <div class="field"><label>Failed print risk (%)</label><input type="number" name="default_failed_risk" value="<?= e($profile['default_failed_risk']) ?>" min="0" max="100" step="1"></div>
    </div>
    <button class="btn" type="submit">Save settings</button>
  </div>
</div>
</form>

<div class="grid grid-2" style="margin-top:16px">
  <div class="card">
    <h3>Change password</h3>
    <form method="post" action="<?= BASE_PATH ?>/settings.php">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <div class="field"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
      <div class="field-row">
        <div class="field"><label>New password</label><input type="password" name="new_password" required minlength="8" autocomplete="new-password"></div>
        <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
      </div>
      <button class="btn btn-outline" type="submit">Update password</button>
    </form>
  </div>
  <div class="card" style="border-color:rgba(239,106,90,.4)">
    <h3 class="text-danger">Danger zone</h3>
    <p class="muted small">Deleting your account removes every customer, order, invoice, stock, filament and printer record linked to it. This cannot be undone. Signed in as <strong><?= e($user['email']) ?></strong>.</p>
    <form method="post" action="<?= BASE_PATH ?>/settings.php" data-confirm="Permanently delete your account and all data?">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_account">
      <div class="field"><label>Confirm with your password</label><input type="password" name="confirm_delete_password" required autocomplete="current-password"></div>
      <button class="btn btn-danger" type="submit">Delete my account and data</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
