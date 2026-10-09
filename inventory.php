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
        q('DELETE FROM inventory WHERE id = ? AND user_id = ?', [$id, $uid]);
        flash('success', 'Filament roll deleted.');
        redirect('inventory.php');
    }

    if ($action === 'use' && $id) {
        $grams = max(0, post_int('grams', 0));
        $roll = row('SELECT * FROM inventory WHERE id = ? AND user_id = ?', [$id, $uid]);
        if ($roll && $grams > 0) {
            $new = max(0, (int)$roll['remaining_weight'] - $grams);
            q('UPDATE inventory SET remaining_weight = ? WHERE id = ?', [$new, $id]);
            flash('success', "Recorded {$grams} g used. {$new} g remaining.");
        } else {
            flash('error', 'Enter a number of grams greater than 0.');
        }
        redirect('inventory.php');
    }

    if ($action === 'save') {
        $data = [
            'material_type' => post_str('material_type', 40), 'colour' => nullable(post_str('colour', 40)),
            'brand' => nullable(post_str('brand', 60)), 'supplier' => nullable(post_str('supplier', 80)),
            'purchase_price' => max(0, post_num('purchase_price')), 'total_weight' => max(1, post_int('total_weight', 1000)),
            'remaining_weight' => max(0, post_int('remaining_weight', 1000)), 'min_stock_alert' => max(0, post_int('min_stock_alert', 200)),
            'notes' => nullable(post_str('notes', 1000)),
        ];
        if ($data['material_type'] === '') {
            flash('error', 'Material type is required.');
            redirect('inventory.php');
        }
        $data['remaining_weight'] = min($data['remaining_weight'], $data['total_weight']);
        if ($id) {
            q('UPDATE inventory SET material_type=?, colour=?, brand=?, supplier=?, purchase_price=?, total_weight=?, remaining_weight=?, min_stock_alert=?, notes=? WHERE id=? AND user_id=?',
                [...array_values($data), $id, $uid]);
            flash('success', 'Roll updated.');
        } else {
            q('INSERT INTO inventory (material_type, colour, brand, supplier, purchase_price, total_weight, remaining_weight, min_stock_alert, notes, user_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [...array_values($data), $uid]);
            flash('success', 'Roll added.');
        }
        redirect('inventory.php');
    }
}

$rolls = rows('SELECT * FROM inventory WHERE user_id = ? ORDER BY material_type, colour', [$uid]);
$totals = ['rolls' => count($rolls), 'grams' => 0, 'value' => 0.0, 'low' => 0];
foreach ($rolls as $r) {
    $totals['grams'] += (int)$r['remaining_weight'];
    $totals['value'] += (int)$r['total_weight'] > 0 ? (float)$r['purchase_price'] * ((int)$r['remaining_weight'] / (int)$r['total_weight']) : 0;
    if ((int)$r['remaining_weight'] <= (int)$r['min_stock_alert']) $totals['low']++;
}

$pageTitle = 'Filament Inventory';
$pageDescription = 'Track your filament rolls and material levels.';
$pageActions = '<button class="btn" type="button" data-open="rollDialog">+ Add roll</button>';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4">
  <div class="stat"><div class="stat-label">Rolls</div><div class="stat-value"><?= $totals['rolls'] ?></div></div>
  <div class="stat"><div class="stat-label">Material on hand</div><div class="stat-value"><?= number_format($totals['grams'] / 1000, 2) ?> kg</div></div>
  <div class="stat"><div class="stat-label">Stock value</div><div class="stat-value"><?= money($totals['value'], $currency) ?></div><div class="stat-sub">pro rata of purchase price</div></div>
  <div class="stat"><div class="stat-label">Low rolls</div><div class="stat-value <?= $totals['low'] ? 'text-warning' : '' ?>"><?= $totals['low'] ?></div></div>
</div>

<div class="toolbar" style="margin-top:16px">
  <input type="search" placeholder="Search material, colour, brand" data-search-table="rollTable">
</div>
<div class="table-wrap">
  <table id="rollTable">
    <thead><tr><th>Material</th><th>Brand / supplier</th><th style="width:220px">Remaining</th><th class="right">Cost / kg</th><th>Alert</th><th class="right">Actions</th></tr></thead>
    <tbody>
      <?php if (!$rolls): ?><tr><td colspan="6" class="empty">No filament yet. Add your first roll.</td></tr><?php endif; ?>
      <?php foreach ($rolls as $r):
          $pctLeft = (int)$r['total_weight'] > 0 ? (int)$r['remaining_weight'] / (int)$r['total_weight'] * 100 : 0;
          $low = (int)$r['remaining_weight'] <= (int)$r['min_stock_alert'];
          $perKg = (int)$r['total_weight'] > 0 ? (float)$r['purchase_price'] / ((int)$r['total_weight'] / 1000) : 0;
          $json = json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
      ?>
        <tr data-search="<?= e($r['material_type'] . ' ' . $r['colour'] . ' ' . $r['brand'] . ' ' . $r['supplier']) ?>">
          <td><div class="cell-main"><?= e($r['material_type']) ?> <?= e($r['colour']) ?></div><div class="cell-sub"><?= e($r['notes']) ?></div></td>
          <td><?= e($r['brand']) ?><div class="cell-sub"><?= e($r['supplier']) ?></div></td>
          <td><div class="row-between small"><span><?= (int)$r['remaining_weight'] ?> g of <?= (int)$r['total_weight'] ?> g</span><span class="<?= $low ? 'text-warning' : 'muted' ?>"><?= number_format($pctLeft) ?>%</span></div><div class="bar <?= $low ? 'warning' : 'success' ?>"><span style="width:<?= max(0, min(100, $pctLeft)) ?>%"></span></div></td>
          <td class="right"><?= money($perKg, $currency) ?><div class="cell-sub">roll <?= money($r['purchase_price'], $currency) ?></div></td>
          <td><?= $low ? '<span class="badge badge-warning">Low</span>' : '<span class="badge badge-success">OK</span>' ?><div class="cell-sub">alert at <?= (int)$r['min_stock_alert'] ?> g</div></td>
          <td class="actions">
            <button class="btn btn-sm btn-outline" type="button" data-open="useDialog" data-json='<?= $json ?>'>Use</button>
            <button class="btn btn-sm btn-ghost" type="button" data-open="rollDialog" data-json='<?= $json ?>'>Edit</button>
            <form method="post" class="inline-form" data-confirm="Delete this roll?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">Delete</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="empty" data-empty-filter style="display:none">Nothing matches your search.</div>
</div>

<dialog id="rollDialog">
  <form method="post" action="<?= BASE_PATH ?>/inventory.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
    <div class="dialog-head"><h3 data-title-create="Add filament roll" data-title-edit="Edit filament roll">Add filament roll</h3><button type="button" class="icon-btn" data-close aria-label="Close">&times;</button></div>
    <div class="dialog-body">
      <div class="field-row">
        <div class="field"><label>Material type</label><input type="text" name="material_type" required maxlength="40" placeholder="PLA" list="materialList"><datalist id="materialList"><option>PLA</option><option>PETG</option><option>ABS</option><option>TPU</option><option>ASA</option><option>Resin</option></datalist></div>
        <div class="field"><label>Colour</label><input type="text" name="colour" maxlength="40" placeholder="Black"></div>
        <div class="field"><label>Brand</label><input type="text" name="brand" maxlength="60"></div>
        <div class="field"><label>Supplier</label><input type="text" name="supplier" maxlength="80"></div>
        <div class="field"><label>Purchase price (per roll)</label><input type="number" name="purchase_price" min="0" step="0.01" data-default="0"></div>
        <div class="field"><label>Total weight (g)</label><input type="number" name="total_weight" min="1" step="1" data-default="1000"></div>
        <div class="field"><label>Remaining weight (g)</label><input type="number" name="remaining_weight" min="0" step="1" data-default="1000"></div>
        <div class="field"><label>Min stock alert (g)</label><input type="number" name="min_stock_alert" min="0" step="1" data-default="200"></div>
      </div>
      <div class="field"><label>Notes</label><textarea name="notes" maxlength="1000"></textarea></div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn" type="submit">Save roll</button></div>
  </form>
</dialog>

<dialog id="useDialog" class="dialog-sm">
  <form method="post" action="<?= BASE_PATH ?>/inventory.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="use"><input type="hidden" name="id" value="">
    <div class="dialog-head"><h3>Record material used</h3><button type="button" class="icon-btn" data-close aria-label="Close">&times;</button></div>
    <div class="dialog-body">
      <p class="muted small">Deducts grams from the roll. Print jobs linked to a roll deduct automatically when confirmed.</p>
      <div class="field"><label>Grams used</label><input type="number" name="grams" min="1" step="1" required></div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn" type="submit">Deduct</button></div>
  </form>
</dialog>

<?php require __DIR__ . '/includes/footer.php'; ?>
