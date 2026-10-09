<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
$uid = (int)$user['id'];
$profile = get_profile($uid);
$currency = $profile['currency'] ?: 'AUD';

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$inv = row('SELECT * FROM invoices WHERE id = ? AND user_id = ?', [$id, $uid]);
if (!$inv) {
    flash('error', 'Invoice not found.');
    redirect('invoices.php');
}
$items = rows('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY position', [$id]);
$stamp = $inv['status'] === 'Paid' ? 'paid' : ($inv['status'] === 'Overdue' ? 'overdue' : 'due');

$pageTitle = 'Invoice ' . $inv['invoice_number'];
$pageDescription = $inv['customer_name'] . ' · ' . $inv['status'];
$pageActions = '<a class="btn btn-outline" href="' . BASE_PATH . '/invoices.php">Back</a> <a class="btn btn-outline" href="' . BASE_PATH . '/invoice_form.php?id=' . $id . '">Edit</a> <button class="btn" type="button" onclick="window.print()">Print / PDF</button>';
require __DIR__ . '/includes/header.php';
?>

<div class="invoice-sheet">
  <div class="row-between" style="align-items:flex-start">
    <div>
      <h1>TAX INVOICE</h1>
      <div class="meta"><?= e($inv['invoice_number']) ?></div>
      <div style="margin-top:12px"><span class="stamp <?= $stamp ?>"><?= $stamp === 'paid' ? 'PAID' : ($stamp === 'overdue' ? 'OVERDUE' : 'DUE ' . strtoupper(fmt_date($inv['due_date'] ?? $inv['issue_date']))) ?></span></div>
    </div>
    <div class="right">
      <div style="font-weight:800;font-size:1.1rem"><?= e($profile['business_name'] ?: $user['full_name']) ?></div>
      <?php if ($profile['business_abn']): ?><div class="meta">ABN <?= e($profile['business_abn']) ?></div><?php endif; ?>
      <?php if ($profile['business_address']): ?><div class="meta"><?= e($profile['business_address']) ?></div><?php endif; ?>
      <?php if ($profile['business_email']): ?><div class="meta"><?= e($profile['business_email']) ?></div><?php endif; ?>
      <?php if ($profile['business_phone']): ?><div class="meta"><?= e($profile['business_phone']) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="grid grid-2" style="margin:28px 0 20px">
    <div>
      <div class="meta small" style="text-transform:uppercase;letter-spacing:.08em">Bill to</div>
      <div style="font-weight:700"><?= e($inv['customer_name']) ?></div>
      <?php if ($inv['customer_address']): ?><div><?= e($inv['customer_address']) ?></div><?php endif; ?>
      <?php if ($inv['customer_email']): ?><div class="meta"><?= e($inv['customer_email']) ?></div><?php endif; ?>
      <?php if ($inv['customer_phone']): ?><div class="meta"><?= e($inv['customer_phone']) ?></div><?php endif; ?>
    </div>
    <div class="right">
      <div><span class="meta">Issue date:</span> <?= fmt_date($inv['issue_date']) ?></div>
      <div><span class="meta">Due date:</span> <?= $inv['due_date'] ? fmt_date($inv['due_date']) : 'On receipt' ?></div>
      <?php if ($inv['order_id']): ?><div><span class="meta">Order:</span> #<?= (int)$inv['order_id'] ?></div><?php endif; ?>
    </div>
  </div>

  <table>
    <thead><tr><th>Item</th><th>Description</th><th class="right">Qty</th><th class="right">Unit price</th><th class="right">Amount</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr><td style="font-weight:600"><?= e($it['product_name']) ?></td><td><?= e($it['description']) ?></td><td class="right"><?= e(rtrim(rtrim(number_format((float)$it['quantity'], 2), '0'), '.')) ?></td><td class="right"><?= money($it['unit_price'], $currency) ?></td><td class="right"><?= money($it['line_total'], $currency) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="totals">
    <div><span>Subtotal</span><span><?= money($inv['subtotal'], $currency) ?></span></div>
    <?php if ((float)$inv['discount'] > 0): ?><div><span>Discount</span><span>-<?= money($inv['discount'], $currency) ?></span></div><?php endif; ?>
    <?php if ((int)$inv['gst_enabled']): ?><div><span>GST (<?= e(rtrim(rtrim(number_format((float)$profile['gst_rate'], 2), '0'), '.')) ?>%)</span><span><?= money($inv['gst_amount'], $currency) ?></span></div><?php endif; ?>
    <div class="grand"><span>Total</span><span><?= money($inv['total_amount'], $currency) ?></span></div>
    <?php if ((float)$inv['amount_paid'] > 0): ?><div><span>Paid</span><span>-<?= money($inv['amount_paid'], $currency) ?></span></div><div style="font-weight:700"><span>Balance due</span><span><?= money($inv['balance_due'], $currency) ?></span></div><?php endif; ?>
  </div>

  <?php if ($inv['payment_instructions'] || $profile['bank_bsb']): ?>
    <div style="margin-top:28px"><div class="meta small" style="text-transform:uppercase;letter-spacing:.08em">Payment</div>
      <pre><?= e($inv['payment_instructions']) ?></pre>
      <?php if ($profile['bank_bsb'] || $profile['bank_account_number']): ?><pre>Bank: <?= e($profile['bank_name']) ?>
Account name: <?= e($profile['bank_account_name'] ?: $profile['business_name']) ?>
BSB: <?= e($profile['bank_bsb']) ?>   Account: <?= e($profile['bank_account_number']) ?></pre><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($inv['terms_conditions']): ?><div style="margin-top:16px"><div class="meta small" style="text-transform:uppercase;letter-spacing:.08em">Terms</div><pre><?= e($inv['terms_conditions']) ?></pre></div><?php endif; ?>
  <?php if ($inv['notes']): ?><div style="margin-top:16px"><div class="meta small" style="text-transform:uppercase;letter-spacing:.08em">Notes</div><pre><?= e($inv['notes']) ?></pre></div><?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
