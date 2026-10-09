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
        q('DELETE FROM stock_items WHERE id = ? AND user_id = ?', [$id, $uid]);
        flash('success', 'Stock product deleted.');
        redirect('stock.php');
    }

    if ($action === 'adjust' && $id) {
        $qty = max(0, post_int('quantity', 0));
        $mode = post_str('mode', 10) === 'reduce' ? 'reduce' : 'add';
        $item = row('SELECT * FROM stock_items WHERE id = ? AND user_id = ?', [$id, $uid]);
        if (!$item || $qty <= 0) {
            flash('error', 'Enter a quantity greater than 0.');
        } elseif ($mode === 'reduce' && $qty > (int)$item['current_quantity']) {
            flash('error', 'Cannot reduce more than the available quantity.');
        } else {
            $reason = post_str('reason', 60);
            $type = $mode === 'add' ? 'Stock Added' : ($reason === 'Manual Adjustment' ? 'Manual Adjustment' : 'Stock Reduced');
            $note = trim($reason . ($reason && post_str('note', 255) ? ': ' : '') . post_str('note', 255));
            move_stock($uid, $id, $mode === 'add' ? $qty : -$qty, $type, [], $note ?: null);
            flash('success', $mode === 'add' ? "Added {$qty} units." : "Removed {$qty} units.");
        }
        redirect('stock.php');
    }

    if ($action === 'save') {
        $data = [
            'product_name' => post_str('product_name', 120), 'sku' => nullable(post_str('sku', 40)), 'category' => nullable(post_str('category', 60)),
            'description' => nullable(post_str('description', 2000)), 'minimum_quantity' => max(0, post_int('minimum_quantity', 0)),
            'unit_cost' => max(0, post_num('unit_cost')), 'selling_price' => max(0, post_num('selling_price')),
            'location' => nullable(post_str('location', 60)), 'notes' => nullable(post_str('notes', 2000)),
        ];
        $inactive = post_str('status', 20) === 'Inactive';
        if ($data['product_name'] === '') {
            flash('error', 'Product name is required.');
            redirect('stock.php');
        }
        if ($id) {
            $item = row('SELECT * FROM stock_items WHERE id = ? AND user_id = ?', [$id, $uid]);
            if ($item) {
                $status = derive_stock_status((int)$item['current_quantity'], $data['minimum_quantity'], $inactive ? 'Inactive' : 'In Stock');
                q('UPDATE stock_items SET product_name=?, sku=?, category=?, description=?, minimum_quantity=?, unit_cost=?, selling_price=?, location=?, notes=?, status=? WHERE id=? AND user_id=?',
                    [...array_values($data), $status, $id, $uid]);
                flash('success', 'Stock product updated.');
            }
        } else {
            $qty = max(0, post_int('current_quantity', 0));
            $status = derive_stock_status($qty, $data['minimum_quantity'], $inactive ? 'Inactive' : 'In Stock');
            q('INSERT INTO stock_items (product_name, sku, category, description, minimum_quantity, unit_cost, selling_price, location, notes, current_quantity, status, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [...array_values($data), $qty, $status, $uid]);
            $newId = (int)db()->lastInsertId();
            if ($qty > 0) {
                q('INSERT INTO stock_movements (user_id, stock_item_id, movement_type, quantity_changed, previous_quantity, new_quantity, note) VALUES (?,?,"Stock Added",?,0,?,"Opening quantity")', [$uid, $newId, $qty, $qty]);
            }
            flash('success', 'Stock product created.');
        }
        redirect('stock.php');
    }
}

$items = rows('SELECT * FROM stock_items WHERE user_id = ? ORDER BY product_name', [$uid]);
$categories = array_values(array_unique(array_filter(array_column($items, 'category'))));
sort($categories);
$totals = ['units' => 0, 'value' => 0.0, 'retail' => 0.0, 'low' => 0];
foreach ($items as $i) {
    $totals['units'] += (int)$i['current_quantity'];
    $totals['value'] += (int)$i['current_quantity'] * (float)$i['unit_cost'];
    $totals['retail'] += (int)$i['current_quantity'] * (float)$i['selling_price'];
    if (in_array($i['status'], ['Low Stock', 'Out of Stock'], true)) $totals['low']++;
}
$movements = rows('SELECT m.*, s.product_name FROM stock_movements m LEFT JOIN stock_items s ON s.id = m.stock_item_id WHERE m.user_id = ? ORDER BY m.created_at DESC, m.id DESC LIMIT 25', [$uid]);

$pageTitle = 'Stock';
$pageDescription = 'Manage your finished products ready to sell.';
$pageActions = '<button class="btn" type="button" data-open="itemDialog">+ Add product</button>';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4">
  <div class="stat"><div class="stat-label">Products</div><div class="stat-value"><?= count($items) ?></div></div>
  <div class="stat"><div class="stat-label">Units on hand</div><div class="stat-value"><?= $totals['units'] ?></div></div>
  <div class="stat"><div class="stat-label">Stock value (cost)</div><div class="stat-value"><?= money($totals['value'], $currency) ?></div><div class="stat-sub">retail <?= money($totals['retail'], $currency) ?></div></div>
  <div class="stat"><div class="stat-label">Low or out</div><div class="stat-value <?= $totals['low'] ? 'text-warning' : '' ?>"><?= $totals['low'] ?></div></div>
</div>

<div class="toolbar" style="margin-top:16px">
  <input type="search" placeholder="Search by product name or SKU" data-search-table="stockTable">
  <select data-filter-table="stockTable" data-filter-attr="category"><option value="">All categories</option><?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?></select>
  <select data-filter-table="stockTable" data-filter-attr="status"><option value="">All statuses</option><?php foreach (['In Stock', 'Low Stock', 'Out of Stock', 'Inactive'] as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?></select>
</div>
<div class="table-wrap">
  <table id="stockTable">
    <thead><tr><th>Product</th><th>Category</th><th class="right">Qty</th><th>Status</th><th class="right">Unit cost</th><th class="right">Price</th><th class="right">Margin</th><th>Location</th><th class="right">Actions</th></tr></thead>
    <tbody>
      <?php if (!$items): ?><tr><td colspan="9" class="empty">No products yet. Create one here or from the calculator.</td></tr><?php endif; ?>
      <?php foreach ($items as $i):
          $margin = (float)$i['selling_price'] > 0 ? ((float)$i['selling_price'] - (float)$i['unit_cost']) / (float)$i['selling_price'] * 100 : 0;
          $json = json_encode($i, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
      ?>
        <tr data-search="<?= e($i['product_name'] . ' ' . $i['sku']) ?>" data-category="<?= e($i['category']) ?>" data-status="<?= e($i['status']) ?>">
          <td><div class="cell-main"><?= e($i['product_name']) ?></div><div class="cell-sub mono"><?= e($i['sku']) ?></div></td>
          <td><?= e($i['category']) ?></td>
          <td class="right cell-main"><?= (int)$i['current_quantity'] ?><div class="cell-sub">min <?= (int)$i['minimum_quantity'] ?></div></td>
          <td><span class="badge <?= badge_class($i['status']) ?>"><?= e($i['status']) ?></span></td>
          <td class="right"><?= money($i['unit_cost'], $currency) ?></td>
          <td class="right"><?= money($i['selling_price'], $currency) ?></td>
          <td class="right <?= $margin < 20 ? 'text-warning' : 'text-success' ?>"><?= pct($margin) ?></td>
          <td class="small"><?= e($i['location']) ?></td>
          <td class="actions">
            <button class="btn btn-sm btn-success" type="button" data-open="addDialog" data-json='<?= $json ?>' title="Add stock">+</button>
            <button class="btn btn-sm btn-danger" type="button" data-open="reduceDialog" data-json='<?= $json ?>' title="Reduce stock">&minus;</button>
            <button class="btn btn-sm btn-ghost" type="button" data-open="itemDialog" data-json='<?= $json ?>'>Edit</button>
            <form method="post" class="inline-form" data-confirm="Delete this product and its movement history?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">Delete</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="empty" data-empty-filter style="display:none">Nothing matches your filters.</div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-title"><h3>Recent stock movements</h3><span class="muted small">audit trail, last 25</span></div>
  <div class="table-wrap" style="border:0">
    <table>
      <thead><tr><th>When</th><th>Product</th><th>Type</th><th class="right">Change</th><th class="right">Before</th><th class="right">After</th><th>Note</th></tr></thead>
      <tbody>
        <?php if (!$movements): ?><tr><td colspan="7" class="empty">No movements recorded yet.</td></tr><?php endif; ?>
        <?php foreach ($movements as $m): ?>
          <tr>
            <td class="small nowrap"><?= fmt_date($m['created_at'], 'd M Y H:i') ?></td>
            <td><?= e($m['product_name'] ?? '(deleted)') ?></td>
            <td><span class="badge <?= (int)$m['quantity_changed'] >= 0 ? 'badge-success' : 'badge-danger' ?>"><?= e($m['movement_type']) ?></span></td>
            <td class="right cell-main"><?= (int)$m['quantity_changed'] >= 0 ? '+' : '' ?><?= (int)$m['quantity_changed'] ?></td>
            <td class="right muted"><?= (int)$m['previous_quantity'] ?></td>
            <td class="right"><?= (int)$m['new_quantity'] ?></td>
            <td class="small"><?= e($m['note']) ?><?= $m['order_id'] ? ' <span class="muted">order #' . (int)$m['order_id'] . '</span>' : '' ?><?= $m['print_job_id'] ? ' <span class="muted">job #' . (int)$m['print_job_id'] . '</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<dialog id="itemDialog">
  <form method="post" action="<?= BASE_PATH ?>/stock.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
    <div class="dialog-head"><h3 data-title-create="Add stock product" data-title-edit="Edit stock product">Add stock product</h3><button type="button" class="icon-btn" data-close aria-label="Close">&times;</button></div>
    <div class="dialog-body">
      <div class="field-row">
        <div class="field"><label>Product name</label><input type="text" name="product_name" required maxlength="120" placeholder="Minimalist Lamp"></div>
        <div class="field"><label>SKU / product code</label><input type="text" name="sku" maxlength="40" placeholder="LAMP-001"></div>
        <div class="field"><label>Category</label><input type="text" name="category" maxlength="60" placeholder="Home decor"></div>
        <div class="field"><label>Location</label><input type="text" name="location" maxlength="60" placeholder="Shelf A2"></div>
        <div class="field" id="openingQtyField"><label>Opening quantity</label><input type="number" name="current_quantity" min="0" step="1" data-default="0"><p class="help">After creation, change quantity with the + and &minus; buttons so every change is logged.</p></div>
        <div class="field"><label>Minimum quantity alert</label><input type="number" name="minimum_quantity" min="0" step="1" data-default="0"></div>
        <div class="field"><label>Unit cost</label><input type="number" name="unit_cost" min="0" step="0.01" data-default="0"></div>
        <div class="field"><label>Selling price</label><input type="number" name="selling_price" min="0" step="0.01" data-default="0"></div>
        <div class="field"><label>Status</label><select name="status"><option value="Active">Active (auto: in / low / out of stock)</option><option value="Inactive">Inactive (hidden from quotes)</option></select></div>
      </div>
      <div class="field"><label>Description</label><textarea name="description" maxlength="2000"></textarea></div>
      <div class="field"><label>Notes</label><textarea name="notes" maxlength="2000"></textarea></div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn" type="submit">Save product</button></div>
  </form>
</dialog>

<dialog id="addDialog" class="dialog-sm">
  <form method="post" action="<?= BASE_PATH ?>/stock.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="mode" value="add"><input type="hidden" name="id" value="">
    <div class="dialog-head"><h3>Add stock</h3><button type="button" class="icon-btn" data-close aria-label="Close">&times;</button></div>
    <div class="dialog-body">
      <p class="muted small">Product: <strong data-show="product_name"></strong></p>
      <div class="field"><label>Quantity to add</label><input type="number" name="quantity" min="1" step="1" required></div>
      <div class="field"><label>Note (optional)</label><input type="text" name="note" maxlength="255" placeholder="Printed overnight batch"></div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn" type="submit">Add units</button></div>
  </form>
</dialog>

<dialog id="reduceDialog" class="dialog-sm">
  <form method="post" action="<?= BASE_PATH ?>/stock.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="mode" value="reduce"><input type="hidden" name="id" value="">
    <div class="dialog-head"><h3>Reduce stock</h3><button type="button" class="icon-btn" data-close aria-label="Close">&times;</button></div>
    <div class="dialog-body">
      <p class="muted small">Product: <strong data-show="product_name"></strong> (<span data-show="current_quantity"></span> on hand)</p>
      <div class="field"><label>Quantity to remove</label><input type="number" name="quantity" min="1" step="1" required></div>
      <div class="field"><label>Reason</label><select name="reason"><option>Sold outside the system</option><option>Damaged</option><option>Gift or sample</option><option>Manual Adjustment</option></select></div>
      <div class="field"><label>Note (optional)</label><input type="text" name="note" maxlength="255"></div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn btn-danger" type="submit">Remove units</button></div>
  </form>
</dialog>

<script>
// Show the product name inside the adjust dialogs and hide the opening quantity when editing.
document.addEventListener('pb:filled', function (ev) {
  const form = ev.target, dlg = form.closest('dialog');
  const data = ev.detail || {};
  dlg.querySelectorAll('[data-show]').forEach(function (el) { el.textContent = data[el.getAttribute('data-show')] || ''; });
  if (dlg.id === 'itemDialog') {
    document.getElementById('openingQtyField').classList.add('hidden');
    form.elements.status.value = data.status === 'Inactive' ? 'Inactive' : 'Active';
  }
});
document.addEventListener('pb:reset', function (ev) {
  if (ev.target.closest('dialog').id === 'itemDialog') document.getElementById('openingQtyField').classList.remove('hidden');
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
