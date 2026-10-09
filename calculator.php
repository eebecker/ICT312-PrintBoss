<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
$uid = (int)$user['id'];
$profile = get_profile($uid);
$currency = $profile['currency'] ?: 'AUD';

$stockItems = rows('SELECT id, product_name, sku FROM stock_items WHERE user_id = ? ORDER BY product_name', [$uid]);
$customers = rows('SELECT id, name FROM customers WHERE user_id = ? ORDER BY name', [$uid]);
$printers = rows('SELECT id, name, average_power_consumption_watts, depreciation_per_hour FROM printers WHERE user_id = ? AND is_active = 1 ORDER BY name', [$uid]);
$filaments = rows('SELECT id, material_type, colour, brand, purchase_price, total_weight FROM inventory WHERE user_id = ? ORDER BY material_type, colour', [$uid]);

$inputs = [
    'filament_cost_per_kg' => 25, 'grams_used' => 100, 'print_time_hours' => 5,
    'electricity_cost_per_kwh' => (float)$profile['default_electricity_cost'], 'printer_power_watts' => 200,
    'depreciation_per_hour' => (float)$profile['default_depreciation_rate'], 'labour_time_hours' => 0.5,
    'labour_hourly_rate' => (float)$profile['default_labour_rate'], 'packaging_cost' => 1,
    'failed_risk_percent' => (float)$profile['default_failed_risk'], 'profit_margin_percent' => (float)$profile['default_profit_margin'],
];
$productName = '';
$filamentType = 'PLA';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($inputs as $k => $v) {
        $inputs[$k] = max(0, post_num($k, (float)$v));
    }
    $productName = post_str('product_name', 120);
    $filamentType = post_str('filament_type', 40) ?: 'PLA';
    $result = calculate_cost($inputs);
    $action = post_str('action', 30);
    $qty = max(1, post_int('quantity', 1));

    if ($productName === '') {
        $errors[] = 'Add a product name before saving.';
    } elseif ($action === 'save_quote') {
        $customerId = post_id('customer_id');
        $customerName = 'Walk-in';
        if ($customerId) {
            $c = row('SELECT name FROM customers WHERE id = ? AND user_id = ?', [$customerId, $uid]);
            if ($c) {
                $customerName = $c['name'];
            } else {
                $customerId = null;
            }
        }
        $stockId = post_id('stock_item_id');
        if ($stockId && !row('SELECT id FROM stock_items WHERE id = ? AND user_id = ?', [$stockId, $uid])) {
            $stockId = null;
        }
        $unitCost = round($result['total_real_cost'], 2);
        $unitPrice = round($result['suggested_price'], 2);
        q('INSERT INTO orders (user_id, customer_id, customer_name, product_name, status, stock_item_id, order_quantity, material_used, grams_used, print_time, labour_time,
             unit_cost, unit_selling_price, total_cost, total_selling_price, total_profit, profit_margin, notes)
           VALUES (?,?,?,?,"Quote",?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $uid, $customerId, $customerName, $productName, $stockId, $qty, $filamentType, $inputs['grams_used'], $inputs['print_time_hours'], $inputs['labour_time_hours'],
            $unitCost, $unitPrice, $unitCost * $qty, $unitPrice * $qty, ($unitPrice - $unitCost) * $qty,
            $unitPrice > 0 ? round(($unitPrice - $unitCost) / $unitPrice * 100, 2) : 0,
            'Quote created from the cost calculator.',
        ]);
        flash('success', 'Saved as a new quote in Orders.');
        redirect('orders.php');
    } elseif ($action === 'create_stock') {
        $norm = mb_strtolower(trim($productName));
        $dup = row('SELECT id, product_name FROM stock_items WHERE user_id = ? AND LOWER(product_name) = ?', [$uid, $norm]);
        if ($dup && !isset($_POST['force_duplicate'])) {
            $errors[] = 'A stock product called "' . $dup['product_name'] . '" already exists. Tick "Create anyway" to add a separate product, or link the existing one when saving a quote.';
        } else {
            $notes = sprintf('Material: %s · Grams: %sg · Print time: %sh · Labour: %sh · Packaging: %s',
                $filamentType, $inputs['grams_used'], $inputs['print_time_hours'], $inputs['labour_time_hours'], money($inputs['packaging_cost'], $currency));
            q('INSERT INTO stock_items (user_id, product_name, category, current_quantity, minimum_quantity, unit_cost, selling_price, status, notes)
               VALUES (?,?,?,0,?,?,?,"Out of Stock",?)', [
                $uid, $productName, post_str('category', 60) ?: null, max(0, post_int('minimum_quantity', 0)),
                round($result['total_real_cost'], 2), round($result['suggested_price'], 2), $notes,
            ]);
            flash('success', 'Stock product created with quantity 0. Add units through a print job or a stock adjustment.');
            redirect('stock.php');
        }
    }
}
$result = calculate_cost($inputs);

$pageTitle = 'Real Cost Calculator';
$pageDescription = 'Know your true cost and the right price for every print.';
$pageScripts = ['calculator.js'];
require __DIR__ . '/includes/header.php';
?>

<form method="post" action="<?= BASE_PATH ?>/calculator.php" id="calcForm">
<?= csrf_field() ?>
<?php if ($errors): ?><div class="error-box"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
<div class="grid grid-main">
  <div class="card">
    <div class="field-row">
      <div class="field"><label for="product_name">Product name</label><input type="text" id="product_name" name="product_name" value="<?= e($productName) ?>" placeholder="e.g. Dragon figurine" maxlength="120"></div>
      <div class="field"><label for="filament_type">Filament type</label><input type="text" id="filament_type" name="filament_type" value="<?= e($filamentType) ?>" placeholder="PLA, PETG, ABS" maxlength="40" list="materialList">
        <datalist id="materialList"><option>PLA</option><option>PETG</option><option>ABS</option><option>TPU</option><option>ASA</option><option>Resin</option></datalist></div>
    </div>
    <div class="field-row">
      <div class="field"><label for="filamentPick">Load cost from a filament roll</label>
        <select id="filamentPick"><option value="">Choose a roll (optional)</option>
          <?php foreach ($filaments as $f): $perKg = (int)$f['total_weight'] > 0 ? (float)$f['purchase_price'] / ((int)$f['total_weight'] / 1000) : 0; ?>
            <option value="<?= e(number_format($perKg, 2, '.', '')) ?>" data-material="<?= e($f['material_type']) ?>"><?= e($f['material_type'] . ' ' . $f['colour'] . ' (' . $f['brand'] . ') ' . money($perKg, $currency) . '/kg') ?></option>
          <?php endforeach; ?></select></div>
      <div class="field"><label for="printerPick">Load power and depreciation from a printer</label>
        <select id="printerPick"><option value="">Choose a printer (optional)</option>
          <?php foreach ($printers as $p): ?>
            <option value="<?= (int)$p['id'] ?>" data-watts="<?= (int)$p['average_power_consumption_watts'] ?>" data-dep="<?= e($p['depreciation_per_hour']) ?>"><?= e($p['name']) ?></option>
          <?php endforeach; ?></select></div>
    </div>
    <hr class="divider">
    <div class="field-row-3">
      <?php
      $fields = [
          ['filament_cost_per_kg', 'Filament cost per kg', '/kg', '0.01'], ['grams_used', 'Grams used', 'g', '1'], ['print_time_hours', 'Print time', 'h', '0.1'],
          ['electricity_cost_per_kwh', 'Electricity cost per kWh', '/kWh', '0.0001'], ['printer_power_watts', 'Printer power', 'W', '1'], ['depreciation_per_hour', 'Depreciation per hour', '/h', '0.01'],
          ['labour_time_hours', 'Labour time', 'h', '0.05'], ['labour_hourly_rate', 'Labour hourly rate', '/h', '0.01'], ['packaging_cost', 'Packaging cost', '', '0.01'],
          ['failed_risk_percent', 'Failed print risk', '%', '1'], ['profit_margin_percent', 'Desired profit margin', '%', '1'],
      ];
      foreach ($fields as [$name, $label, $suffix, $step]): ?>
        <div class="field"><label for="<?= $name ?>"><?= $label ?></label><div class="input-suffix"><input type="number" id="<?= $name ?>" name="<?= $name ?>" value="<?= e($inputs[$name]) ?>" min="0" step="<?= $step ?>" data-calc><?php if ($suffix): ?><span><?= $suffix ?></span><?php endif; ?></div></div>
      <?php endforeach; ?>
      <div class="field"><label for="quantity">Quantity for the quote</label><input type="number" id="quantity" name="quantity" value="1" min="1" step="1"></div>
    </div>
  </div>

  <div class="stack">
    <div class="card">
      <h3>Cost breakdown</h3>
      <ul class="list" data-currency="<?= e($currency) ?>">
        <li><span class="muted">Material cost</span><span id="r_material"><?= money($result['material_cost'], $currency) ?></span></li>
        <li><span class="muted">Electricity cost</span><span id="r_electricity"><?= money($result['electricity_cost'], $currency) ?></span></li>
        <li><span class="muted">Depreciation cost</span><span id="r_depreciation"><?= money($result['depreciation_cost'], $currency) ?></span></li>
        <li><span class="muted">Labour cost</span><span id="r_labour"><?= money($result['labour_cost'], $currency) ?></span></li>
        <li><span class="muted">Packaging cost</span><span id="r_packaging"><?= money($result['packaging_cost'], $currency) ?></span></li>
        <li><span class="muted">Failed print buffer</span><span id="r_buffer"><?= money($result['total_real_cost'] - $result['base_cost'], $currency) ?></span></li>
        <li><span class="cell-main">Total real cost</span><span class="cell-main" id="r_total"><?= money($result['total_real_cost'], $currency) ?></span></li>
      </ul>
    </div>
    <div class="card card-highlight">
      <div class="stat-label text-primary">Suggested selling price</div>
      <div class="stat-value" id="r_price" style="font-size:2rem"><?= money($result['suggested_price'], $currency) ?></div>
      <div class="grid grid-2" style="margin-top:10px">
        <div><div class="stat-label">Expected profit</div><div class="cell-main text-success" id="r_profit"><?= money($result['expected_profit'], $currency) ?></div></div>
        <div><div class="stat-label">Profit margin</div><div class="cell-main" id="r_margin"><?= pct($result['profit_margin_percent']) ?></div></div>
      </div>
      <hr class="divider">
      <div class="field"><label for="customer_id">Customer for the quote (optional)</label>
        <select id="customer_id" name="customer_id"><option value="">Walk-in / no customer</option>
          <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="stock_item_id">Linked stock product (optional)</label>
        <select id="stock_item_id" name="stock_item_id"><option value="">No stock product</option>
          <?php foreach ($stockItems as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['product_name']) ?><?= $s['sku'] ? ' (' . e($s['sku']) . ')' : '' ?></option><?php endforeach; ?></select>
        <p class="help">A saved quote will deduct from this product when it moves past the quote stage.</p></div>
      <div class="row" style="margin-top:6px">
        <button class="btn" type="submit" name="action" value="save_quote">Save as quote</button>
        <button class="btn btn-outline" type="button" data-open="stockDialog">Create stock product</button>
      </div>
    </div>
  </div>
</div>

<dialog id="stockDialog" class="dialog-sm">
  <div class="dialog-head"><h3>Create stock product from this calculation</h3><button type="button" class="icon-btn" data-close aria-label="Close">&times;</button></div>
  <div class="dialog-body">
    <p class="muted small">The product starts with quantity 0. Unit cost and selling price come from the calculator; you can edit them later on the Stock page.</p>
    <div class="field"><label for="category">Category</label><input type="text" id="category" name="category" placeholder="Home decor" form="calcForm" maxlength="60"></div>
    <div class="field"><label for="minimum_quantity">Minimum quantity alert</label><input type="number" id="minimum_quantity" name="minimum_quantity" value="0" min="0" step="1" form="calcForm"></div>
    <label class="check"><input type="checkbox" name="force_duplicate" value="1" form="calcForm"> Create anyway if a product with the same name exists</label>
  </div>
  <div class="dialog-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn" type="submit" name="action" value="create_stock" form="calcForm">Create product</button></div>
</dialog>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
