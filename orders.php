<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
$uid = (int)$user['id'];
$profile = get_profile($uid);
$currency = $profile['currency'] ?: 'AUD';

$STATUSES = ['Quote', 'Approved', 'Printing', 'Post-processing', 'Ready', 'Delivered', 'Cancelled'];
$DEDUCTING = ['Approved', 'Printing', 'Post-processing', 'Ready', 'Delivered']; // statuses that reserve stock

/**
 * Keep the stock reservation in sync with the order.
 * Stock is deducted once the order leaves the Quote stage and returned when it is cancelled.
 */
function sync_order_stock(int $uid, int $orderId, ?int $stockId, string $status, int $qty, int $oldDeducted, ?int $oldStockId, string $customer): int
{
    global $DEDUCTING;
    $wanted = ($stockId && in_array($status, $DEDUCTING, true)) ? $qty : 0;
    // Product changed: give the old reservation back first
    if ($oldStockId && $oldDeducted > 0 && $oldStockId !== $stockId) {
        move_stock($uid, $oldStockId, $oldDeducted, 'Order Updated', ['order_id' => $orderId], "Order #{$orderId} moved to another product");
        $oldDeducted = 0;
    }
    if (!$stockId) {
        return 0;
    }
    $delta = $wanted - $oldDeducted;
    if ($delta !== 0) {
        $type = $status === 'Cancelled' ? 'Order Cancelled' : ($oldDeducted === 0 ? 'Order Created' : 'Order Updated');
        move_stock($uid, $stockId, -$delta, $type, ['order_id' => $orderId], "Order #{$orderId} {$customer}");
    }
    return $wanted;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action', 20);
    $id = post_id('id');

    if ($action === 'delete' && $id) {
        $o = row('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$id, $uid]);
        if ($o) {
            if ($o['stock_item_id'] && (int)$o['stock_deducted'] > 0) {
                move_stock($uid, (int)$o['stock_item_id'], (int)$o['stock_deducted'], 'Order Cancelled', [], "Order #{$id} deleted");
            }
            q('DELETE FROM orders WHERE id = ? AND user_id = ?', [$id, $uid]);
            flash('success', 'Order deleted and any reserved stock returned.');
        }
        redirect('orders.php');
    }

    if ($action === 'status' && $id) {
        $o = row('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$id, $uid]);
        $status = post_str('status', 20);
        if ($o && in_array($status, $STATUSES, true)) {
            $pdo = db();
            $pdo->beginTransaction();
            $deducted = sync_order_stock($uid, $id, $o['stock_item_id'] ? (int)$o['stock_item_id'] : null, $status, (int)$o['order_quantity'], (int)$o['stock_deducted'], $o['stock_item_id'] ? (int)$o['stock_item_id'] : null, $o['customer_name']);
            q('UPDATE orders SET status = ?, stock_deducted = ? WHERE id = ?', [$status, $deducted, $id]);
            $pdo->commit();
            flash('success', "Order #{$id} is now {$status}.");
        }
        redirect('orders.php');
    }

    if ($action === 'save') {
        $customerId = post_id('customer_id');
        $customerName = post_str('customer_name', 120);
        if ($customerId) {
            $c = row('SELECT name FROM customers WHERE id = ? AND user_id = ?', [$customerId, $uid]);
            if ($c) { $customerName = $c['name']; } else { $customerId = null; }
        }
        $stockId = post_id('stock_item_id');
        if ($stockId && !row('SELECT id FROM stock_items WHERE id = ? AND user_id = ?', [$stockId, $uid])) $stockId = null;
        $printerId = post_id('printer_id');
        if ($printerId && !row('SELECT id FROM printers WHERE id = ? AND user_id = ?', [$printerId, $uid])) $printerId = null;
        $status = in_array(post_str('status', 20), $STATUSES, true) ? post_str('status', 20) : 'Quote';
        $qty = max(1, post_int('order_quantity', 1));
        $unitCost = max(0, post_num('unit_cost'));
        $unitPrice = max(0, post_num('unit_selling_price'));
        $data = [
            'customer_id' => $customerId, 'customer_name' => $customerName ?: 'Walk-in', 'product_name' => post_str('product_name', 120),
            'status' => $status, 'stock_item_id' => $stockId, 'printer_id' => $printerId, 'order_quantity' => $qty,
            'material_used' => nullable(post_str('material_used', 40)), 'grams_used' => max(0, post_num('grams_used')),
            'print_time' => max(0, post_num('print_time')), 'labour_time' => max(0, post_num('labour_time')),
            'unit_cost' => $unitCost, 'unit_selling_price' => $unitPrice,
            'total_cost' => round($unitCost * $qty, 2), 'total_selling_price' => round($unitPrice * $qty, 2),
            'total_profit' => round(($unitPrice - $unitCost) * $qty, 2),
            'profit_margin' => $unitPrice > 0 ? round(($unitPrice - $unitCost) / $unitPrice * 100, 2) : 0,
            'delivery_date' => post_date('delivery_date'), 'notes' => nullable(post_str('notes', 2000)),
        ];
        if ($data['product_name'] === '') {
            flash('error', 'Product name is required.');
            redirect('orders.php');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $old = row('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$id, $uid]);
                if ($old) {
                    $deducted = sync_order_stock($uid, $id, $stockId, $status, $qty, (int)$old['stock_deducted'], $old['stock_item_id'] ? (int)$old['stock_item_id'] : null, $data['customer_name']);
                    q('UPDATE orders SET customer_id=?, customer_name=?, product_name=?, status=?, stock_item_id=?, printer_id=?, order_quantity=?, material_used=?, grams_used=?, print_time=?, labour_time=?, unit_cost=?, unit_selling_price=?, total_cost=?, total_selling_price=?, total_profit=?, profit_margin=?, delivery_date=?, notes=?, stock_deducted=? WHERE id=? AND user_id=?',
                        [...array_values($data), $deducted, $id, $uid]);
                    flash('success', 'Order updated.');
                }
            } else {
                q('INSERT INTO orders (customer_id, customer_name, product_name, status, stock_item_id, printer_id, order_quantity, material_used, grams_used, print_time, labour_time, unit_cost, unit_selling_price, total_cost, total_selling_price, total_profit, profit_margin, delivery_date, notes, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [...array_values($data), $uid]);
                $newId = (int)$pdo->lastInsertId();
                $deducted = sync_order_stock($uid, $newId, $stockId, $status, $qty, 0, null, $data['customer_name']);
                q('UPDATE orders SET stock_deducted = ? WHERE id = ?', [$deducted, $newId]);
                flash('success', 'Order created.');
            }
            $pdo->commit();
        } catch (Throwable $t) {
            $pdo->rollBack();
            flash('error', 'Could not save the order: ' . $t->getMessage());
        }
        redirect('orders.php');
    }
}

$customerFilter = isset($_GET['customer']) && ctype_digit((string)$_GET['customer']) ? (int)$_GET['customer'] : null;
$orders = rows('SELECT o.*, s.product_name AS stock_name, s.current_quantity AS stock_qty, p.name AS printer_name,
                  (SELECT COUNT(*) FROM invoices i WHERE i.order_id = o.id) AS invoice_count
                FROM orders o LEFT JOIN stock_items s ON s.id = o.stock_item_id LEFT JOIN printers p ON p.id = o.printer_id
                WHERE o.user_id = ?' . ($customerFilter ? ' AND o.customer_id = ?' : '') . ' ORDER BY o.created_at DESC',
    $customerFilter ? [$uid, $customerFilter] : [$uid]);
$customers = rows('SELECT id, name FROM customers WHERE user_id = ? ORDER BY name', [$uid]);
$stockItems = rows('SELECT id, product_name, sku, current_quantity, unit_cost, selling_price FROM stock_items WHERE user_id = ? AND status <> "Inactive" ORDER BY product_name', [$uid]);
$printers = rows('SELECT id, name, status FROM printers WHERE user_id = ? AND is_active = 1 ORDER BY name', [$uid]);
$sum = ['open' => 0, 'open_value' => 0.0, 'quotes' => 0, 'delivered_value' => 0.0];
foreach ($orders as $o) {
    if ($o['status'] === 'Quote') $sum['quotes']++;
    elseif (!in_array($o['status'], ['Delivered', 'Cancelled'], true)) { $sum['open']++; $sum['open_value'] += (float)$o['total_selling_price']; }
    elseif ($o['status'] === 'Delivered') $sum['delivered_value'] += (float)$o['total_selling_price'];
}

$pageTitle = 'Orders';
$pageDescription = 'Quotes, production and deliveries in one pipeline.';
$pageActions = '<button class="btn" type="button" data-open="orderDialog">+ New order</button>';
$pageScripts = ['orders.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4">
  <div class="stat"><div class="stat-label">Open quotes</div><div class="stat-value"><?= $sum['quotes'] ?></div></div>
  <div class="stat"><div class="stat-label">In production</div><div class="stat-value"><?= $sum['open'] ?></div><div class="stat-sub">worth <?= money($sum['open_value'], $currency) ?></div></div>
  <div class="stat"><div class="stat-label">Delivered value</div><div class="stat-value"><?= money($sum['delivered_value'], $currency) ?></div></div>
  <div class="stat"><div class="stat-label">All orders</div><div class="stat-value"><?= count($orders) ?></div></div>
</div>

<div class="toolbar" style="margin-top:16px">
  <input type="search" placeholder="Search customer or product" data-search-table="orderTable">
  <select data-filter-table="orderTable" data-filter-attr="status"><option value="">All statuses</option><?php foreach ($STATUSES as $s): ?><option><?= $s ?></option><?php endforeach; ?></select>
  <?php if ($customerFilter): ?><a class="btn btn-sm btn-outline" href="<?= BASE_PATH ?>/orders.php">Clear customer filter</a><?php endif; ?>
</div>
<div class="table-wrap">
  <table id="orderTable">
    <thead><tr><th>#</th><th>Customer</th><th>Product</th><th class="right">Qty</th><th>Status</th><th>Delivery</th><th class="right">Total</th><th class="right">Profit</th><th class="right">Actions</th></tr></thead>
    <tbody>
      <?php if (!$orders): ?><tr><td colspan="9" class="empty">No orders yet. Save a quote from the calculator or create one here.</td></tr><?php endif; ?>
      <?php foreach ($orders as $o): $json = json_encode($o, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>
        <tr data-search="<?= e($o['customer_name'] . ' ' . $o['product_name']) ?>" data-status="<?= e($o['status']) ?>">
          <td class="mono"><?= (int)$o['id'] ?></td>
          <td><?= e($o['customer_name']) ?></td>
          <td><div class="cell-main"><?= e($o['product_name']) ?></div><div class="cell-sub"><?= $o['stock_name'] ? 'stock: ' . e($o['stock_name']) : e($o['material_used'] ?: '') ?><?= $o['printer_name'] ? ' &middot; ' . e($o['printer_name']) : '' ?></div></td>
          <td class="right"><?= (int)$o['order_quantity'] ?></td>
          <td>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <select name="status" class="status-select <?= badge_class($o['status']) ?>" onchange="this.form.submit()" aria-label="Order status"><?php foreach ($STATUSES as $s): ?><option <?= $s === $o['status'] ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
            </form>
          </td>
          <td class="small nowrap"><?= $o['delivery_date'] ? fmt_date($o['delivery_date']) : '<span class="muted">-</span>' ?></td>
          <td class="right"><?= money($o['total_selling_price'], $currency) ?></td>
          <td class="right text-success"><?= money($o['total_profit'], $currency) ?><div class="cell-sub"><?= pct($o['profit_margin']) ?></div></td>
          <td class="actions">
            <?php if ((int)$o['invoice_count'] === 0 && $o['status'] !== 'Cancelled'): ?><a class="btn btn-sm btn-outline" href="<?= BASE_PATH ?>/invoice_form.php?order=<?= (int)$o['id'] ?>">Invoice</a><?php endif; ?>
            <button class="btn btn-sm btn-ghost" type="button" data-open="orderDialog" data-json='<?= $json ?>'>Edit</button>
            <form method="post" class="inline-form" data-confirm="Delete this order?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">Delete</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="empty" data-empty-filter style="display:none">Nothing matches your filters.</div>
</div>

<dialog id="orderDialog" class="dialog-lg">
  <form method="post" action="<?= BASE_PATH ?>/orders.php" id="orderForm" data-currency="<?= e($currency) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
    <div class="dialog-head"><h3 data-title-create="New order" data-title-edit="Edit order">New order</h3><button type="button" class="icon-btn" data-close aria-label="Close">&times;</button></div>
    <div class="dialog-body">
      <div class="field-row-3">
        <div class="field"><label>Customer</label><select name="customer_id"><option value="">Walk-in (type a name)</option><?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Customer name</label><input type="text" name="customer_name" maxlength="120" placeholder="Walk-in"></div>
        <div class="field"><label>Status</label><select name="status"><?php foreach ($STATUSES as $s): ?><option><?= $s ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Stock product (optional)</label><select name="stock_item_id" id="orderStock"><option value="">None (custom print)</option><?php foreach ($stockItems as $s): ?><option value="<?= (int)$s['id'] ?>" data-name="<?= e($s['product_name']) ?>" data-cost="<?= e($s['unit_cost']) ?>" data-price="<?= e($s['selling_price']) ?>" data-qty="<?= (int)$s['current_quantity'] ?>"><?= e($s['product_name']) ?> (<?= (int)$s['current_quantity'] ?> on hand)</option><?php endforeach; ?></select><p class="help">Stock is reserved when the order leaves the Quote stage and returned if cancelled.</p></div>
        <div class="field"><label>Printer (optional)</label><select name="printer_id"><option value="">Not assigned</option><?php foreach ($printers as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['status']) ?>)</option><?php endforeach; ?></select></div>
        <div class="field"><label>Delivery date</label><input type="date" name="delivery_date"></div>
        <div class="field"><label>Product name</label><input type="text" name="product_name" required maxlength="120"></div>
        <div class="field"><label>Quantity</label><input type="number" name="order_quantity" min="1" step="1" data-default="1" data-total></div>
        <div class="field"><label>Material</label><input type="text" name="material_used" maxlength="40" placeholder="PLA"></div>
        <div class="field"><label>Grams used (per unit)</label><input type="number" name="grams_used" min="0" step="1" data-default="0"></div>
        <div class="field"><label>Print time (h, per unit)</label><input type="number" name="print_time" min="0" step="0.1" data-default="0"></div>
        <div class="field"><label>Labour time (h, per unit)</label><input type="number" name="labour_time" min="0" step="0.05" data-default="0"></div>
        <div class="field"><label>Unit cost</label><input type="number" name="unit_cost" min="0" step="0.01" data-default="0" data-total></div>
        <div class="field"><label>Unit selling price</label><input type="number" name="unit_selling_price" min="0" step="0.01" data-default="0" data-total></div>
        <div class="field"><label>Totals</label><div class="card" style="padding:9px 12px;font-size:.88rem"><div class="row-between"><span class="muted">Cost</span><span id="t_cost">-</span></div><div class="row-between"><span class="muted">Price</span><span id="t_price">-</span></div><div class="row-between"><span class="muted">Profit</span><span class="text-success" id="t_profit">-</span></div></div></div>
      </div>
      <div class="field"><label>Notes</label><textarea name="notes" maxlength="2000"></textarea></div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn" type="submit">Save order</button></div>
  </form>
</dialog>

<style>
.status-select { width: auto; padding: 3px 26px 3px 9px; border-radius: 999px; font-size: .74rem; font-weight: 600; border: 0; cursor: pointer; }
</style>
<?php require __DIR__ . '/includes/footer.php'; ?>
