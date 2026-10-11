<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
$uid = (int)$user['id'];
$profile = get_profile($uid);
$currency = $profile['currency'] ?: 'AUD';

/* ---------- orders ---------- */
$monthStart = date('Y-m-01 00:00:00');
$orderStats = row(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status NOT IN ("Delivered","Cancelled","Quote") THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status = "Quote" THEN 1 ELSE 0 END) AS quotes,
        SUM(CASE WHEN status = "Delivered" THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN created_at >= ? AND status NOT IN ("Quote","Cancelled") THEN total_selling_price ELSE 0 END) AS month_revenue,
        SUM(CASE WHEN created_at >= ? AND status NOT IN ("Quote","Cancelled") THEN total_profit ELSE 0 END) AS month_profit,
        AVG(CASE WHEN status NOT IN ("Quote","Cancelled") AND total_selling_price > 0 THEN total_profit / total_selling_price * 100 END) AS avg_margin
     FROM orders WHERE user_id = ?',
    [$monthStart, $monthStart, $uid]
);
$topSelling = row('SELECT product_name, SUM(order_quantity) AS qty FROM orders WHERE user_id = ? AND status NOT IN ("Quote","Cancelled") GROUP BY product_name ORDER BY qty DESC LIMIT 1', [$uid]);
$mostProfitable = row('SELECT product_name, SUM(total_profit) AS p FROM orders WHERE user_id = ? AND status NOT IN ("Quote","Cancelled") GROUP BY product_name ORDER BY p DESC LIMIT 1', [$uid]);

/* ---------- 6 month series ---------- */
$series = [];
for ($k = 5; $k >= 0; $k--) {
    $start = new DateTime("first day of -$k month");
    $start->setTime(0, 0);
    $end = (clone $start)->modify('+1 month');
    $r = row('SELECT COALESCE(SUM(total_selling_price),0) AS revenue, COALESCE(SUM(total_profit),0) AS profit, COUNT(*) AS n
              FROM orders WHERE user_id = ? AND status NOT IN ("Quote","Cancelled") AND created_at >= ? AND created_at < ?',
        [$uid, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
    $series[] = ['month' => $start->format('M'), 'revenue' => (float)$r['revenue'], 'profit' => (float)$r['profit'], 'orders' => (int)$r['n']];
}

/* ---------- invoices ---------- */
q('UPDATE invoices SET status = "Overdue" WHERE user_id = ? AND status = "Sent" AND due_date IS NOT NULL AND due_date < CURDATE()', [$uid]);
$inv = row(
    'SELECT
        SUM(CASE WHEN status IN ("Draft","Sent","Overdue") THEN 1 ELSE 0 END) AS unpaid,
        SUM(CASE WHEN status = "Overdue" THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN status = "Paid" AND updated_at >= ? THEN 1 ELSE 0 END) AS paid_month,
        SUM(CASE WHEN status <> "Cancelled" THEN total_amount ELSE 0 END) AS invoiced,
        SUM(CASE WHEN status NOT IN ("Paid","Cancelled") THEN balance_due ELSE 0 END) AS outstanding
     FROM invoices WHERE user_id = ?',
    [$monthStart, $uid]
);

/* ---------- printers & jobs ---------- */
$printers = rows('SELECT * FROM printers WHERE user_id = ? AND is_active = 1 ORDER BY name', [$uid]);
$jobs = rows('SELECT j.*, s.product_name AS stock_name, o.product_name AS order_product, p.name AS printer_name
              FROM print_jobs j
              LEFT JOIN stock_items s ON s.id = j.stock_item_id
              LEFT JOIN orders o ON o.id = j.order_id
              JOIN printers p ON p.id = j.printer_id
              WHERE j.user_id = ? AND j.status IN ("Scheduled","Printing","Paused","Awaiting Confirmation")
              ORDER BY j.started_at DESC, j.created_at DESC', [$uid]);
$pStats = ['total' => count($printers), 'available' => 0, 'printing' => 0, 'maintenance' => 0];
$today = date('Y-m-d');
foreach ($printers as $p) {
    if ($p['status'] === 'Available') $pStats['available']++;
    if ($p['status'] === 'Printing') $pStats['printing']++;
    if ($p['status'] === 'Maintenance' || ($p['next_maintenance_due'] && $p['next_maintenance_due'] <= $today)) $pStats['maintenance']++;
}
$awaiting = count(array_filter($jobs, fn($j) => $j['status'] === 'Awaiting Confirmation'));

/* ---------- stock alerts ---------- */
$lowFilament = rows('SELECT * FROM inventory WHERE user_id = ? AND remaining_weight <= min_stock_alert ORDER BY remaining_weight', [$uid]);
$lowStock = rows('SELECT * FROM stock_items WHERE user_id = ? AND status IN ("Low Stock","Out of Stock") ORDER BY current_quantity', [$uid]);
$recentOrders = rows('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 6', [$uid]);

$pageTitle = 'Operational Dashboard';
$pageDescription = 'Your 3D printing business at a glance.';
$pageScripts = ['dashboard.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-4">
  <div class="stat accent"><div class="stat-label">Accepted order value this month</div><div class="stat-value"><?= money($orderStats['month_revenue'], $currency) ?></div><div class="stat-sub">Estimated profit <?= money($orderStats['month_profit'], $currency) ?></div></div>
  <div class="stat"><div class="stat-label">Active orders</div><div class="stat-value"><?= (int)$orderStats['active'] ?></div><div class="stat-sub"><?= (int)$orderStats['quotes'] ?> open quotes &middot; <?= (int)$orderStats['delivered'] ?> delivered</div></div>
  <div class="stat"><div class="stat-label">Average margin</div><div class="stat-value"><?= pct($orderStats['avg_margin']) ?></div><div class="stat-sub">across accepted orders</div></div>
  <div class="stat"><div class="stat-label">Outstanding invoices</div><div class="stat-value"><?= money($inv['outstanding'], $currency) ?></div><div class="stat-sub"><?= (int)$inv['unpaid'] ?> unpaid &middot; <span class="<?= (int)$inv['overdue'] > 0 ? 'text-danger' : '' ?>"><?= (int)$inv['overdue'] ?> overdue</span></div></div>
</div>

<h2 class="muted small" style="margin:22px 0 10px;text-transform:uppercase;letter-spacing:.08em">Production</h2>
<div class="grid grid-4">
  <div class="stat"><div class="stat-label">Printers</div><div class="stat-value"><?= $pStats['total'] ?></div><div class="stat-sub"><?= $pStats['available'] ?> available &middot; <?= $pStats['printing'] ?> printing</div></div>
  <div class="stat"><div class="stat-label">Jobs in progress</div><div class="stat-value"><?= count($jobs) ?></div><div class="stat-sub"><?= $awaiting ?> awaiting confirmation</div></div>
  <div class="stat"><div class="stat-label">Needs maintenance</div><div class="stat-value <?= $pStats['maintenance'] ? 'text-warning' : '' ?>"><?= $pStats['maintenance'] ?></div><div class="stat-sub">printers due or in service</div></div>
  <div class="stat"><div class="stat-label">Low material</div><div class="stat-value <?= (count($lowFilament) + count($lowStock)) ? 'text-warning' : '' ?>"><?= count($lowFilament) + count($lowStock) ?></div><div class="stat-sub"><?= count($lowFilament) ?> filament rolls &middot; <?= count($lowStock) ?> products</div></div>
</div>

<div class="grid grid-main" style="margin-top:16px">
  <div class="card">
    <div class="card-title"><h3>Accepted order value &amp; estimated profit (last 6 months)</h3></div>
    <canvas class="chart" id="revenueChart" data-series='<?= e(json_encode($series)) ?>' data-currency="<?= e($currency) ?>"></canvas>
    <div class="legend"><span><i style="background:var(--primary)"></i>Order value</span><span><i style="background:var(--success)"></i>Estimated profit</span></div>
  </div>
  <div class="card">
    <div class="card-title"><h3>Accepted orders per month</h3></div>
    <canvas class="chart" id="ordersChart"></canvas>
  </div>
</div>

<div class="grid grid-3" style="margin-top:16px">
  <div class="card">
    <div class="card-title"><h3>Best sellers</h3></div>
    <ul class="list">
      <li><span class="muted">Top selling</span><span class="cell-main"><?= $topSelling ? e($topSelling['product_name']) . ' (' . (int)$topSelling['qty'] . ')' : 'No orders yet' ?></span></li>
      <li><span class="muted">Most profitable</span><span class="cell-main"><?= $mostProfitable ? e($mostProfitable['product_name']) . ' (' . money($mostProfitable['p'], $currency) . ')' : 'No orders yet' ?></span></li>
      <li><span class="muted">Total invoiced</span><span class="cell-main"><?= money($inv['invoiced'], $currency) ?></span></li>
      <li><span class="muted">Paid this month</span><span class="cell-main"><?= (int)$inv['paid_month'] ?> invoices</span></li>
    </ul>
  </div>
  <div class="card">
    <div class="card-title"><h3>Active print jobs</h3><a class="small" href="<?= BASE_PATH ?>/printers.php">Manage</a></div>
    <?php if (!$jobs): ?><p class="muted">No jobs running. Start one from the Printers page.</p><?php endif; ?>
    <ul class="list">
      <?php foreach (array_slice($jobs, 0, 5) as $j): $rem = job_remaining_seconds($j); ?>
        <li><span><span class="cell-main"><?= e(job_title($j)) ?></span><br><span class="cell-sub"><?= e($j['printer_name']) ?> &middot; <?= (int)$j['planned_quantity'] ?> pcs</span></span>
          <span class="right"><span class="badge <?= badge_class($j['status']) ?>"><?= e($j['status']) ?></span><?php if ($rem !== null && $j['status'] === 'Printing'): ?><br><span class="cell-sub <?= $rem < 0 ? 'text-danger' : '' ?>"><?= $rem < 0 ? 'overdue by ' : '' ?><?= human_duration($rem) ?><?= $rem >= 0 ? ' left' : '' ?></span><?php endif; ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="card">
    <div class="card-title"><h3>Stock alerts</h3></div>
    <?php if (!$lowFilament && !$lowStock): ?><p class="muted">All material levels look healthy.</p><?php endif; ?>
    <ul class="list">
      <?php foreach ($lowFilament as $f): ?>
        <li><span><span class="cell-main"><?= e($f['material_type']) ?> <?= e($f['colour']) ?></span><br><span class="cell-sub"><?= e($f['brand']) ?> filament</span></span><span class="text-warning"><?= (int)$f['remaining_weight'] ?> g left</span></li>
      <?php endforeach; ?>
      <?php foreach ($lowStock as $s): ?>
        <li><span><span class="cell-main"><?= e($s['product_name']) ?></span><br><span class="cell-sub">finished product</span></span><span class="badge <?= badge_class($s['status']) ?>"><?= (int)$s['current_quantity'] ?> left</span></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-title"><h3>Recent orders</h3><a class="small" href="<?= BASE_PATH ?>/orders.php">View all</a></div>
  <div class="table-wrap" style="border:0">
    <table>
      <thead><tr><th>Order</th><th>Customer</th><th>Product</th><th>Qty</th><th>Status</th><th class="right">Total</th><th class="right">Profit</th></tr></thead>
      <tbody>
        <?php if (!$recentOrders): ?><tr><td colspan="7" class="empty">No orders yet. Save a quote from the calculator to get started.</td></tr><?php endif; ?>
        <?php foreach ($recentOrders as $o): ?>
          <tr>
            <td class="mono">#<?= (int)$o['id'] ?></td>
            <td><?= e($o['customer_name']) ?></td>
            <td class="cell-main"><?= e($o['product_name']) ?></td>
            <td><?= (int)$o['order_quantity'] ?></td>
            <td><span class="badge <?= badge_class($o['status']) ?>"><?= e($o['status']) ?></span></td>
            <td class="right"><?= money($o['total_selling_price'], $currency) ?></td>
            <td class="right text-success"><?= money($o['total_profit'], $currency) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
