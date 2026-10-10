<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
$uid = (int)$user['id'];
$profile = get_profile($uid);
$currency = $profile['currency'] ?: 'AUD';
$STATUSES = ['Draft', 'Sent', 'Paid', 'Overdue', 'Cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action', 20);
    $id = post_id('id');
    $inv = $id ? row('SELECT * FROM invoices WHERE id = ? AND user_id = ?', [$id, $uid]) : null;

    if ($action === 'delete' && $inv) {
        q('DELETE FROM invoices WHERE id = ? AND user_id = ?', [$id, $uid]);
        flash('success', 'Invoice ' . $inv['invoice_number'] . ' deleted.');
    } elseif ($action === 'status' && $inv) {
        $status = post_str('status', 20);
        if (in_array($status, $STATUSES, true)) {
            if ($status === 'Paid') {
                q('UPDATE invoices SET status = "Paid", amount_paid = total_amount, balance_due = 0 WHERE id = ?', [$id]);
            } else {
                q('UPDATE invoices SET status = ? WHERE id = ?', [$status, $id]);
            }
            flash('success', 'Invoice ' . $inv['invoice_number'] . ' marked as ' . $status . '.');
        }
    } elseif ($action === 'payment' && $inv) {
        $amount = round(post_num('amount'), 2);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $inv = row('SELECT * FROM invoices WHERE id = ? AND user_id = ? FOR UPDATE', [$id, $uid]);
            $remaining = $inv ? round((float)$inv['total_amount'] - (float)$inv['amount_paid'], 2) : 0;
            if (!$inv || $amount <= 0 || $amount > $remaining) {
                $pdo->rollBack();
                flash('error', 'Payment must be greater than zero and cannot exceed the remaining balance.');
            } else {
                $paid = round((float)$inv['amount_paid'] + $amount, 2);
                $balance = round((float)$inv['total_amount'] - $paid, 2);
                q('UPDATE invoices SET amount_paid = ?, balance_due = ?, status = ? WHERE id = ? AND user_id = ?', [$paid, $balance, $balance <= 0 ? 'Paid' : $inv['status'], $id, $uid]);
                $pdo->commit();
                flash('success', 'Payment of ' . money($amount, $currency) . ' recorded.');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Invoice payment failed: ' . $e->getMessage());
            flash('error', 'Could not record the payment. Please try again.');
        }
    }
    redirect('invoices.php');
}

q('UPDATE invoices SET status = "Overdue" WHERE user_id = ? AND status = "Sent" AND due_date IS NOT NULL AND due_date < CURDATE()', [$uid]);
$invoices = rows('SELECT i.*, (SELECT COUNT(*) FROM invoice_items it WHERE it.invoice_id = i.id) AS line_count FROM invoices i WHERE i.user_id = ? ORDER BY i.issue_date DESC, i.id DESC', [$uid]);
$sum = ['outstanding' => 0.0, 'overdue' => 0, 'paid_month' => 0.0, 'total' => 0.0];
$monthStart = date('Y-m-01');
foreach ($invoices as $i) {
    if (!in_array($i['status'], ['Paid', 'Cancelled'], true)) $sum['outstanding'] += (float)$i['balance_due'];
    if ($i['status'] === 'Overdue') $sum['overdue']++;
    if ($i['status'] === 'Paid' && substr($i['updated_at'], 0, 10) >= $monthStart) $sum['paid_month'] += (float)$i['total_amount'];
    if ($i['status'] !== 'Cancelled') $sum['total'] += (float)$i['total_amount'];
}

$pageTitle = 'Invoices';
$pageDescription = 'Bill your customers and track what is owed.';
$pageActions = '<a class="btn" href="' . BASE_PATH . '/invoice_form.php">+ New invoice</a>';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4">
  <div class="stat accent"><div class="stat-label">Outstanding</div><div class="stat-value"><?= money($sum['outstanding'], $currency) ?></div></div>
  <div class="stat"><div class="stat-label">Overdue</div><div class="stat-value <?= $sum['overdue'] ? 'text-danger' : '' ?>"><?= $sum['overdue'] ?></div></div>
  <div class="stat"><div class="stat-label">Paid this month</div><div class="stat-value"><?= money($sum['paid_month'], $currency) ?></div></div>
  <div class="stat"><div class="stat-label">Total invoiced</div><div class="stat-value"><?= money($sum['total'], $currency) ?></div></div>
</div>

<div class="toolbar" style="margin-top:16px">
  <input type="search" placeholder="Search number or customer" data-search-table="invoiceTable">
  <select data-filter-table="invoiceTable" data-filter-attr="status"><option value="">All statuses</option><?php foreach ($STATUSES as $s): ?><option><?= $s ?></option><?php endforeach; ?></select>
</div>
<div class="table-wrap">
  <table id="invoiceTable">
    <thead><tr><th>Invoice</th><th>Customer</th><th>Issued</th><th>Due</th><th>Status</th><th class="right">Total</th><th class="right">Balance</th><th class="right">Actions</th></tr></thead>
    <tbody>
      <?php if (!$invoices): ?><tr><td colspan="8" class="empty">No invoices yet. Create one from an order or from scratch.</td></tr><?php endif; ?>
      <?php foreach ($invoices as $i): ?>
        <tr data-search="<?= e($i['invoice_number'] . ' ' . $i['customer_name']) ?>" data-status="<?= e($i['status']) ?>">
          <td><a class="cell-main" href="<?= BASE_PATH ?>/invoice_view.php?id=<?= (int)$i['id'] ?>"><?= e($i['invoice_number']) ?></a><div class="cell-sub"><?= (int)$i['line_count'] ?> line<?= (int)$i['line_count'] === 1 ? '' : 's' ?><?= $i['order_id'] ? ' &middot; order #' . (int)$i['order_id'] : '' ?></div></td>
          <td><?= e($i['customer_name']) ?></td>
          <td class="small nowrap"><?= fmt_date($i['issue_date']) ?></td>
          <td class="small nowrap <?= $i['status'] === 'Overdue' ? 'text-danger' : '' ?>"><?= $i['due_date'] ? fmt_date($i['due_date']) : '-' ?></td>
          <td><span class="badge <?= badge_class($i['status']) ?>"><?= e($i['status']) ?></span></td>
          <td class="right"><?= money($i['total_amount'], $currency) ?></td>
          <td class="right <?= (float)$i['balance_due'] > 0 ? 'cell-main' : 'muted' ?>"><?= money($i['balance_due'], $currency) ?></td>
          <td class="actions">
            <a class="btn btn-sm btn-outline" href="<?= BASE_PATH ?>/invoice_view.php?id=<?= (int)$i['id'] ?>">View</a>
            <?php if (!in_array($i['status'], ['Paid', 'Cancelled'], true)): ?>
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><input type="hidden" name="status" value="<?= $i['status'] === 'Draft' ? 'Sent' : 'Paid' ?>"><button class="btn btn-sm <?= $i['status'] === 'Draft' ? 'btn-outline' : 'btn-success' ?>" type="submit"><?= $i['status'] === 'Draft' ? 'Mark sent' : 'Mark paid' ?></button></form>
              <button class="btn btn-sm btn-ghost" type="button" data-open="paymentDialog" data-json='<?= json_encode(['id' => $i['id'], 'balance' => $i['balance_due'], 'number' => $i['invoice_number']], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>Payment</button>
            <?php endif; ?>
            <a class="btn btn-sm btn-ghost" href="<?= BASE_PATH ?>/invoice_form.php?id=<?= (int)$i['id'] ?>">Edit</a>
            <form method="post" class="inline-form" data-confirm="Delete invoice <?= e($i['invoice_number']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">Delete</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="empty" data-empty-filter style="display:none">Nothing matches your filters.</div>
</div>

<dialog id="paymentDialog" class="dialog-sm">
  <form method="post" action="<?= BASE_PATH ?>/invoices.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="payment"><input type="hidden" name="id" value="">
    <div class="dialog-head"><h3>Record payment</h3><button type="button" class="icon-btn" data-close aria-label="Close">&times;</button></div>
    <div class="dialog-body">
      <p class="muted small">Invoice <strong data-show="number"></strong>. Balance due: <strong data-show="balance"></strong></p>
      <div class="field"><label>Amount received</label><input type="number" name="amount" min="0.01" step="0.01" required></div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn" type="submit">Save payment</button></div>
  </form>
</dialog>
<script>
document.getElementById('paymentDialog').addEventListener('pb:filled', function (ev) {
  const d = ev.detail || {};
  this.querySelectorAll('[data-show]').forEach(function (el) { el.textContent = d[el.getAttribute('data-show')] || ''; });
  this.querySelector('[name=amount]').value = d.balance || '';
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
