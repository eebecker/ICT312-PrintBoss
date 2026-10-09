<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
$uid = (int)$user['id'];
$profile = get_profile($uid);
$currency = $profile['currency'] ?: 'AUD';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action', 20);
    $id = post_id('id');

    if ($action === 'delete' && $id) {
        q('DELETE FROM customers WHERE id = ? AND user_id = ?', [$id, $uid]);
        flash('success', 'Customer deleted. Their orders and invoices were kept.');
        redirect('customers.php');
    }

    if ($action === 'save') {
        $email = post_str('email', 190);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address.');
            redirect('customers.php');
        }
        $data = [
            'name' => post_str('name', 120), 'email' => nullable($email), 'phone' => nullable(post_str('phone', 40)),
            'preferred_product_type' => nullable(post_str('preferred_product_type', 120)),
            'address_line_1' => nullable(post_str('address_line_1', 120)), 'address_line_2' => nullable(post_str('address_line_2', 120)),
            'city' => nullable(post_str('city', 80)), 'state' => nullable(post_str('state', 40)), 'postcode' => nullable(post_str('postcode', 12)),
            'country' => nullable(post_str('country', 60)) ?? 'Australia', 'notes' => nullable(post_str('notes', 2000)),
        ];
        if ($data['name'] === '') {
            flash('error', 'Customer name is required.');
            redirect('customers.php');
        }
        if ($id) {
            q('UPDATE customers SET name=?, email=?, phone=?, preferred_product_type=?, address_line_1=?, address_line_2=?, city=?, state=?, postcode=?, country=?, notes=? WHERE id=? AND user_id=?',
                [...array_values($data), $id, $uid]);
            q('UPDATE orders SET customer_name = ? WHERE customer_id = ? AND user_id = ?', [$data['name'], $id, $uid]);
            flash('success', 'Customer updated.');
        } else {
            q('INSERT INTO customers (name, email, phone, preferred_product_type, address_line_1, address_line_2, city, state, postcode, country, notes, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [...array_values($data), $uid]);
            flash('success', 'Customer added.');
        }
        redirect('customers.php');
    }
}

$customers = rows(
    'SELECT c.*,
        (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.status <> "Cancelled") AS order_count,
        (SELECT COALESCE(SUM(o.total_selling_price),0) FROM orders o WHERE o.customer_id = c.id AND o.status IN ("Delivered","Ready","Printing","Post-processing","Approved")) AS lifetime_value,
        (SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = c.id) AS last_order
     FROM customers c WHERE c.user_id = ? ORDER BY c.name', [$uid]);
$states = ['NSW', 'VIC', 'QLD', 'WA', 'SA', 'TAS', 'ACT', 'NT'];

$pageTitle = 'Customer CRM';
$pageDescription = 'Build lasting relationships with your buyers.';
$pageActions = '<button class="btn" type="button" data-open="customerDialog">+ Add customer</button>';
require __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
  <input type="search" placeholder="Search name, email, suburb, postcode" data-search-table="customerTable">
  <span class="muted small"><?= count($customers) ?> customers</span>
</div>
<div class="table-wrap">
  <table id="customerTable">
    <thead><tr><th>Customer</th><th>Contact</th><th>Address</th><th>Prefers</th><th class="right">Orders</th><th class="right">Lifetime value</th><th class="right">Actions</th></tr></thead>
    <tbody>
      <?php if (!$customers): ?><tr><td colspan="7" class="empty">No customers yet. Add your first buyer.</td></tr><?php endif; ?>
      <?php foreach ($customers as $c):
          $addr = implode(', ', array_filter([$c['address_line_1'], $c['address_line_2'], trim(($c['city'] ?? '') . ' ' . ($c['state'] ?? '') . ' ' . ($c['postcode'] ?? ''))]));
          $json = json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
      ?>
        <tr data-search="<?= e($c['name'] . ' ' . $c['email'] . ' ' . $c['phone'] . ' ' . $c['city'] . ' ' . $c['state'] . ' ' . $c['postcode']) ?>">
          <td><div class="cell-main"><?= e($c['name']) ?></div><?php if ($c['notes']): ?><div class="cell-sub"><?= e(mb_strimwidth($c['notes'], 0, 60, '...')) ?></div><?php endif; ?></td>
          <td><?= e($c['email']) ?><div class="cell-sub"><?= e($c['phone']) ?></div></td>
          <td class="small"><?= e($addr) ?></td>
          <td class="small"><?= e($c['preferred_product_type']) ?></td>
          <td class="right"><?= (int)$c['order_count'] ?><div class="cell-sub"><?= $c['last_order'] ? 'last ' . fmt_date($c['last_order']) : '' ?></div></td>
          <td class="right"><?= money($c['lifetime_value'], $currency) ?></td>
          <td class="actions">
            <a class="btn btn-sm btn-outline" href="<?= BASE_PATH ?>/orders.php?customer=<?= (int)$c['id'] ?>">Orders</a>
            <button class="btn btn-sm btn-ghost" type="button" data-open="customerDialog" data-json='<?= $json ?>'>Edit</button>
            <form method="post" class="inline-form" data-confirm="Delete this customer? Orders and invoices will be kept but unlinked."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">Delete</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="empty" data-empty-filter style="display:none">Nothing matches your search.</div>
</div>

<dialog id="customerDialog">
  <form method="post" action="<?= BASE_PATH ?>/customers.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
    <div class="dialog-head"><h3 data-title-create="Add customer" data-title-edit="Edit customer">Add customer</h3><button type="button" class="icon-btn" data-close aria-label="Close">&times;</button></div>
    <div class="dialog-body">
      <div class="field-row">
        <div class="field"><label>Name</label><input type="text" name="name" required maxlength="120"></div>
        <div class="field"><label>Phone</label><input type="text" name="phone" maxlength="40"></div>
        <div class="field"><label>Email</label><input type="email" name="email" maxlength="190"></div>
        <div class="field"><label>Preferred product type</label><input type="text" name="preferred_product_type" maxlength="120" placeholder="Miniatures, prototypes, decor"></div>
      </div>
      <hr class="divider">
      <div class="field-row">
        <div class="field"><label>Address line 1</label><input type="text" name="address_line_1" maxlength="120"></div>
        <div class="field"><label>Address line 2</label><input type="text" name="address_line_2" maxlength="120"></div>
        <div class="field"><label>Suburb / city</label><input type="text" name="city" maxlength="80"></div>
        <div class="field"><label>State / territory</label><select name="state"><option value="">Select</option><?php foreach ($states as $s): ?><option><?= $s ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Postcode</label><input type="text" name="postcode" maxlength="12"></div>
        <div class="field"><label>Country</label><input type="text" name="country" maxlength="60" data-default="Australia"></div>
      </div>
      <div class="field"><label>Notes</label><textarea name="notes" maxlength="2000"></textarea></div>
      <p class="help">Customer details are used only for quotes, orders and invoices. See the privacy notice.</p>
    </div>
    <div class="dialog-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn" type="submit">Save customer</button></div>
  </form>
</dialog>

<?php require __DIR__ . '/includes/footer.php'; ?>
