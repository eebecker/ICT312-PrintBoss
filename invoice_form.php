<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
$uid = (int)$user['id'];
$profile = get_profile($uid);
$currency = $profile['currency'] ?: 'AUD';
$gstRate = (float)$profile['gst_rate'];
$STATUSES = ['Draft', 'Sent', 'Paid', 'Overdue', 'Cancelled'];

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : null;
$fromOrder = isset($_GET['order']) && ctype_digit((string)$_GET['order']) ? (int)$_GET['order'] : null;

/* ---------- defaults for a new invoice ---------- */
$inv = [
    'id' => null, 'invoice_number' => next_invoice_number($uid, $profile['invoice_prefix']), 'customer_id' => null, 'order_id' => null,
    'customer_name' => '', 'customer_email' => '', 'customer_phone' => '', 'customer_address' => '',
    'status' => 'Draft', 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+14 days')),
    'discount' => 0, 'gst_enabled' => (int)$profile['default_gst_enabled'], 'amount_paid' => 0,
    'payment_instructions' => $profile['default_payment_instructions'] ?: '', 'terms_conditions' => $profile['default_payment_terms'], 'notes' => $profile['default_invoice_notes'] ?: '',
];
$items = [];

if ($id) {
    $existing = row('SELECT * FROM invoices WHERE id = ? AND user_id = ?', [$id, $uid]);
    if (!$existing) { flash('error', 'Invoice not found.'); redirect('invoices.php'); }
    $inv = $existing;
    $items = rows('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY position', [$id]);
} elseif ($fromOrder) {
    $o = row('SELECT o.*, c.email, c.phone, c.address_line_1, c.address_line_2, c.city, c.state, c.postcode FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ? AND o.user_id = ?', [$fromOrder, $uid]);
    if ($o) {
        $inv['order_id'] = (int)$o['id'];
        $inv['customer_id'] = $o['customer_id'];
        $inv['customer_name'] = $o['customer_name'];
        $inv['customer_email'] = $o['email'] ?? '';
        $inv['customer_phone'] = $o['phone'] ?? '';
        $inv['customer_address'] = implode(', ', array_filter([$o['address_line_1'] ?? '', $o['address_line_2'] ?? '', trim(($o['city'] ?? '') . ' ' . ($o['state'] ?? '') . ' ' . ($o['postcode'] ?? ''))]));
        $items[] = ['product_name' => $o['product_name'], 'description' => trim(($o['material_used'] ?? '') . ' print'), 'quantity' => $o['order_quantity'], 'unit_price' => $o['unit_selling_price']];
    }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $inv['invoice_number'] = post_str('invoice_number', 20);
    $inv['customer_id'] = post_id('customer_id');
    if ($inv['customer_id'] && !row('SELECT id FROM customers WHERE id = ? AND user_id = ?', [$inv['customer_id'], $uid])) $inv['customer_id'] = null;
    $inv['order_id'] = post_id('order_id');
    if ($inv['order_id'] && !row('SELECT id FROM orders WHERE id = ? AND user_id = ?', [$inv['order_id'], $uid])) $inv['order_id'] = null;
    $inv['customer_name'] = post_str('customer_name', 120);
    $inv['customer_email'] = post_str('customer_email', 190);
    $inv['customer_phone'] = post_str('customer_phone', 40);
    $inv['customer_address'] = post_str('customer_address', 255);
    $inv['status'] = in_array(post_str('status', 20), $STATUSES, true) ? post_str('status', 20) : 'Draft';
    $inv['issue_date'] = post_date('issue_date') ?? date('Y-m-d');
    $inv['due_date'] = post_date('due_date');
    $inv['discount'] = max(0, post_num('discount'));
    $inv['gst_enabled'] = isset($_POST['gst_enabled']) ? 1 : 0;
    $inv['amount_paid'] = max(0, post_num('amount_paid'));
    $inv['payment_instructions'] = post_str('payment_instructions', 2000);
    $inv['terms_conditions'] = post_str('terms_conditions', 2000);
    $inv['notes'] = post_str('notes', 2000);

    $items = [];
    $names = $_POST['item_name'] ?? [];
    if (is_array($names)) {
        foreach ($names as $k => $name) {
            $name = trim((string)$name);
            if ($name === '') continue;
            $items[] = [
                'product_name' => mb_substr($name, 0, 120),
                'description' => mb_substr(trim((string)($_POST['item_desc'][$k] ?? '')), 0, 255),
                'quantity' => max(0, (float)($_POST['item_qty'][$k] ?? 0)),
                'unit_price' => max(0, (float)($_POST['item_price'][$k] ?? 0)),
            ];
        }
    }
    if ($inv['invoice_number'] === '') $errors[] = 'Invoice number is required.';
    if ($inv['customer_name'] === '') $errors[] = 'Customer name is required.';
    if ($inv['customer_email'] !== '' && !filter_var($inv['customer_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Customer email is not valid.';
    if (!$items) $errors[] = 'Add at least one line item.';
    $dupe = row('SELECT id FROM invoices WHERE user_id = ? AND invoice_number = ? AND id <> ?', [$uid, $inv['invoice_number'], $id ?? 0]);
    if ($dupe) $errors[] = 'That invoice number is already used.';

    if (!$errors) {
        $t = invoice_totals($items, (float)$inv['discount'], (bool)$inv['gst_enabled'], (float)$inv['amount_paid'], $gstRate);
        if ($inv['status'] === 'Paid') { $t['amount_paid'] = $t['total_amount']; $t['balance_due'] = 0.0; }
        elseif ($t['balance_due'] <= 0 && $t['total_amount'] > 0) { $inv['status'] = 'Paid'; }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $params = [
                $inv['invoice_number'], $inv['customer_id'], $inv['order_id'], $inv['customer_name'], nullable($inv['customer_email']), nullable($inv['customer_phone']), nullable($inv['customer_address']),
                $inv['status'], $inv['issue_date'], $inv['due_date'], $t['subtotal'], $t['discount'], $inv['gst_enabled'], $t['gst_amount'], $t['total_amount'], $t['amount_paid'], $t['balance_due'],
                nullable($inv['payment_instructions']), nullable($inv['terms_conditions']), nullable($inv['notes']),
            ];
            if ($id) {
                q('UPDATE invoices SET invoice_number=?, customer_id=?, order_id=?, customer_name=?, customer_email=?, customer_phone=?, customer_address=?, status=?, issue_date=?, due_date=?, subtotal=?, discount=?, gst_enabled=?, gst_amount=?, total_amount=?, amount_paid=?, balance_due=?, payment_instructions=?, terms_conditions=?, notes=? WHERE id=? AND user_id=?', [...$params, $id, $uid]);
                q('DELETE FROM invoice_items WHERE invoice_id = ?', [$id]);
                $invoiceId = $id;
            } else {
                q('INSERT INTO invoices (invoice_number, customer_id, order_id, customer_name, customer_email, customer_phone, customer_address, status, issue_date, due_date, subtotal, discount, gst_enabled, gst_amount, total_amount, amount_paid, balance_due, payment_instructions, terms_conditions, notes, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$params, $uid]);
                $invoiceId = (int)$pdo->lastInsertId();
            }
            $pos = 1;
            foreach ($items as $it) {
                q('INSERT INTO invoice_items (user_id, invoice_id, position, product_name, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?,?,?)',
                    [$uid, $invoiceId, $pos++, $it['product_name'], nullable($it['description']), $it['quantity'], $it['unit_price'], round($it['quantity'] * $it['unit_price'], 2)]);
            }
            $pdo->commit();
            flash('success', 'Invoice ' . $inv['invoice_number'] . ' saved.');
            redirect('invoice_view.php?id=' . $invoiceId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Could not save the invoice: ' . $e->getMessage();
        }
    }
}
if (!$items) {
    $items[] = ['product_name' => '', 'description' => '', 'quantity' => 1, 'unit_price' => 0];
}
$customers = rows('SELECT * FROM customers WHERE user_id = ? ORDER BY name', [$uid]);
$orders = rows('SELECT id, product_name, customer_name FROM orders WHERE user_id = ? AND status <> "Cancelled" ORDER BY created_at DESC LIMIT 100', [$uid]);
$stockItems = rows('SELECT product_name, selling_price FROM stock_items WHERE user_id = ? AND status <> "Inactive" ORDER BY product_name', [$uid]);

$pageTitle = $id ? 'Edit invoice ' . $inv['invoice_number'] : 'New invoice';
$pageDescription = 'Line items, GST and payment details.';
$pageActions = '<a class="btn btn-outline" href="' . BASE_PATH . '/invoices.php">Back to invoices</a>';
$pageScripts = ['invoice.js'];
require __DIR__ . '/includes/header.php';
?>

<?php if ($errors): ?><div class="error-box"><ul style="margin:0;padding-left:18px"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" action="<?= BASE_PATH ?>/invoice_form.php<?= $id ? '?id=' . $id : '' ?>" id="invoiceForm" data-currency="<?= e($currency) ?>" data-gst="<?= e($gstRate) ?>">
<?= csrf_field() ?>
<div class="grid grid-main">
  <div class="stack">
    <div class="card">
      <h3>Customer</h3>
      <div class="field-row">
        <div class="field"><label>Pick a customer</label><select name="customer_id" id="customerPick"><option value="">Manual entry</option><?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$inv['customer_id'] === (int)$c['id'] ? 'selected' : '' ?> data-json='<?= json_encode(['name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'], 'address' => implode(', ', array_filter([$c['address_line_1'], $c['address_line_2'], trim(($c['city'] ?? '') . ' ' . ($c['state'] ?? '') . ' ' . ($c['postcode'] ?? ''))]))], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>'><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Linked order</label><select name="order_id"><option value="">None</option><?php foreach ($orders as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$inv['order_id'] === (int)$o['id'] ? 'selected' : '' ?>>#<?= (int)$o['id'] ?> <?= e($o['product_name']) ?> (<?= e($o['customer_name']) ?>)</option><?php endforeach; ?></select></div>
        <div class="field"><label>Customer name</label><input type="text" name="customer_name" required maxlength="120" value="<?= e($inv['customer_name']) ?>"></div>
        <div class="field"><label>Email</label><input type="email" name="customer_email" maxlength="190" value="<?= e($inv['customer_email']) ?>"></div>
        <div class="field"><label>Phone</label><input type="text" name="customer_phone" maxlength="40" value="<?= e($inv['customer_phone']) ?>"></div>
        <div class="field"><label>Address</label><input type="text" name="customer_address" maxlength="255" value="<?= e($inv['customer_address']) ?>"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-title"><h3>Line items</h3><button type="button" class="btn btn-sm btn-outline" id="addLine">+ Add line</button></div>
      <datalist id="stockList"><?php foreach ($stockItems as $s): ?><option value="<?= e($s['product_name']) ?>" data-price="<?= e($s['selling_price']) ?>"></option><?php endforeach; ?></datalist>
      <div class="table-wrap" style="border:0">
        <table id="lineTable">
          <thead><tr><th style="width:30%">Product</th><th>Description</th><th style="width:90px">Qty</th><th style="width:120px">Unit price</th><th class="right" style="width:110px">Total</th><th style="width:40px"></th></tr></thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <tr class="line">
                <td><input type="text" name="item_name[]" list="stockList" value="<?= e($it['product_name']) ?>" maxlength="120" placeholder="Product" data-line-name></td>
                <td><input type="text" name="item_desc[]" value="<?= e($it['description']) ?>" maxlength="255" placeholder="Optional"></td>
                <td><input type="number" name="item_qty[]" value="<?= e($it['quantity']) ?>" min="0" step="0.01" data-line></td>
                <td><input type="number" name="item_price[]" value="<?= e($it['unit_price']) ?>" min="0" step="0.01" data-line></td>
                <td class="right nowrap" data-line-total><?= money((float)$it['quantity'] * (float)$it['unit_price'], $currency) ?></td>
                <td><button type="button" class="icon-btn danger" data-remove-line aria-label="Remove line">&times;</button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h3>Notes and terms</h3>
      <div class="field"><label>Payment instructions</label><textarea name="payment_instructions" maxlength="2000"><?= e($inv['payment_instructions']) ?></textarea></div>
      <div class="field-row">
        <div class="field"><label>Terms</label><textarea name="terms_conditions" maxlength="2000"><?= e($inv['terms_conditions']) ?></textarea></div>
        <div class="field"><label>Notes to customer</label><textarea name="notes" maxlength="2000"><?= e($inv['notes']) ?></textarea></div>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="card">
      <h3>Details</h3>
      <div class="field"><label>Invoice number</label><input type="text" name="invoice_number" required maxlength="20" value="<?= e($inv['invoice_number']) ?>"></div>
      <div class="field-row">
        <div class="field"><label>Issue date</label><input type="date" name="issue_date" value="<?= e($inv['issue_date']) ?>" required></div>
        <div class="field"><label>Due date</label><input type="date" name="due_date" value="<?= e($inv['due_date']) ?>"></div>
      </div>
      <div class="field"><label>Status</label><select name="status"><?php foreach ($STATUSES as $s): ?><option <?= $s === $inv['status'] ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="card card-highlight">
      <h3>Totals</h3>
      <div class="field"><label>Discount (amount)</label><input type="number" name="discount" min="0" step="0.01" value="<?= e($inv['discount']) ?>" data-line></div>
      <label class="check" style="margin-bottom:10px"><input type="checkbox" name="gst_enabled" value="1" <?= (int)$inv['gst_enabled'] ? 'checked' : '' ?> data-line> Add GST (<?= e(rtrim(rtrim(number_format($gstRate, 2), '0'), '.')) ?>%)</label>
      <div class="field"><label>Amount already paid</label><input type="number" name="amount_paid" min="0" step="0.01" value="<?= e($inv['amount_paid']) ?>" data-line></div>
      <ul class="list">
        <li><span class="muted">Subtotal</span><span id="s_subtotal">-</span></li>
        <li><span class="muted">Discount</span><span id="s_discount">-</span></li>
        <li><span class="muted">GST</span><span id="s_gst">-</span></li>
        <li><span class="cell-main">Total</span><span class="cell-main" id="s_total">-</span></li>
        <li><span class="muted">Balance due</span><span id="s_balance">-</span></li>
      </ul>
      <button class="btn btn-block" type="submit" style="margin-top:12px">Save invoice</button>
    </div>
  </div>
</div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
