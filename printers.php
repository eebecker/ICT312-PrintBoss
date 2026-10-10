<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
$uid = (int)$user['id'];
$profile = get_profile($uid);
$currency = $profile['currency'] ?: 'AUD';

$MAINT_TYPES = ['Nozzle change', 'Bed cleaning', 'Belt adjustment', 'Lubrication', 'Extruder cleaning', 'Firmware update', 'General inspection', 'Other'];
$PRINTER_STATUSES = ['Available', 'Maintenance', 'Offline', 'Error'];

function own_printer(int $uid, ?int $id): ?array
{
    return $id ? row('SELECT * FROM printers WHERE id = ? AND user_id = ?', [$id, $uid]) : null;
}
function own_job(int $uid, ?int $id): ?array
{
    return $id ? row('SELECT * FROM print_jobs WHERE id = ? AND user_id = ?', [$id, $uid]) : null;
}

/** Lock the printer before changing its job, within the caller's transaction. */
function lock_printer_job(int $uid, int $printerId, ?int $jobId = null, bool $startingScheduled = false): array
{
    $printer = row('SELECT * FROM printers WHERE id = ? AND user_id = ? FOR UPDATE', [$printerId, $uid]);
    if (!$printer) throw new RuntimeException('Printer not found.');
    if ($jobId) {
        if ((int)$printer['current_job_id'] !== $jobId && !($startingScheduled && $printer['status'] === 'Available' && !$printer['current_job_id'])) {
            throw new RuntimeException('This is no longer the current job for that printer.');
        }
    } elseif ($printer['status'] !== 'Available' || $printer['current_job_id']) {
        throw new RuntimeException('That printer is not available. Finish the current job first.');
    }
    if ((!$jobId || $startingScheduled) && row('SELECT id FROM print_jobs WHERE printer_id = ? AND user_id = ? AND id <> ? AND status IN ("Printing", "Paused", "Awaiting Confirmation") LIMIT 1', [$printerId, $uid, $jobId ?? 0])) {
        throw new RuntimeException('That printer is not available. Finish the current job first.');
    }
    return $printer;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action', 30);
    $id = post_id('id');

    /* ---------- printer CRUD ---------- */
    if ($action === 'save_printer') {
        $data = [
            'name' => post_str('name', 80), 'model' => nullable(post_str('model', 80)),
            'printer_type' => in_array(post_str('printer_type', 10), ['FDM', 'Resin', 'Other'], true) ? post_str('printer_type', 10) : 'FDM',
            'nozzle_size' => nullable(post_str('nozzle_size', 10)), 'material_loaded' => nullable(post_str('material_loaded', 40)),
            'purchase_price' => max(0, post_num('purchase_price')), 'estimated_lifetime_hours' => max(1, post_int('estimated_lifetime_hours', 5000)),
            'average_power_consumption_watts' => max(0, post_int('average_power_consumption_watts', 150)),
            'maintenance_cost_per_hour' => max(0, post_num('maintenance_cost_per_hour')),
            'total_print_hours' => max(0, post_num('total_print_hours')),
            'last_maintenance_date' => post_date('last_maintenance_date'), 'next_maintenance_due' => post_date('next_maintenance_due'),
            'notes' => nullable(post_str('notes', 2000)),
        ];
        $data['depreciation_per_hour'] = round($data['purchase_price'] / $data['estimated_lifetime_hours'], 4);
        if ($data['name'] === '') {
            flash('error', 'Printer name is required.');
            redirect('printers.php');
        }
        $status = post_str('status', 30);
        if ($id && ($p = own_printer($uid, $id))) {
            $busy = in_array($p['status'], ['Printing', 'Paused', 'Scheduled', 'Awaiting Confirmation'], true);
            $newStatus = $busy ? $p['status'] : (in_array($status, $PRINTER_STATUSES, true) ? $status : $p['status']);
            q('UPDATE printers SET name=?, model=?, printer_type=?, nozzle_size=?, material_loaded=?, purchase_price=?, estimated_lifetime_hours=?, average_power_consumption_watts=?, maintenance_cost_per_hour=?, total_print_hours=?, last_maintenance_date=?, next_maintenance_due=?, notes=?, depreciation_per_hour=?, status=? WHERE id=? AND user_id=?',
                [...array_values($data), $newStatus, $id, $uid]);
            flash('success', 'Printer updated.');
        } else {
            q('INSERT INTO printers (name, model, printer_type, nozzle_size, material_loaded, purchase_price, estimated_lifetime_hours, average_power_consumption_watts, maintenance_cost_per_hour, total_print_hours, last_maintenance_date, next_maintenance_due, notes, depreciation_per_hour, status, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [...array_values($data), in_array($status, $PRINTER_STATUSES, true) ? $status : 'Available', $uid]);
            flash('success', 'Printer added.');
        }
        redirect('printers.php');
    }

    if ($action === 'delete_printer' && ($p = own_printer($uid, $id))) {
        if (in_array($p['status'], ['Printing', 'Paused', 'Awaiting Confirmation'], true)) {
            flash('error', 'Finish or cancel the running job before deleting this printer.');
        } else {
            q('DELETE FROM printers WHERE id = ? AND user_id = ?', [$id, $uid]);
            flash('success', 'Printer deleted.');
        }
        redirect('printers.php');
    }

    /* ---------- maintenance ---------- */
    if ($action === 'add_maintenance' && ($p = own_printer($uid, post_id('printer_id')))) {
        $type = in_array(post_str('maintenance_type', 30), $MAINT_TYPES, true) ? post_str('maintenance_type', 30) : 'General inspection';
        $date = post_date('date') ?? date('Y-m-d');
        q('INSERT INTO printer_maintenance_logs (user_id, printer_id, maintenance_type, date, cost, print_hours_at_maintenance, notes) VALUES (?,?,?,?,?,?,?)',
            [$uid, (int)$p['id'], $type, $date, max(0, post_num('cost')), (float)$p['total_print_hours'], nullable(post_str('notes', 1000))]);
        $next = post_date('next_maintenance_due');
        $backToService = isset($_POST['back_to_service']) && $p['status'] === 'Maintenance';
        q('UPDATE printers SET last_maintenance_date = ?, next_maintenance_due = COALESCE(?, next_maintenance_due), status = ? WHERE id = ?',
            [$date, $next, $backToService ? 'Available' : $p['status'], (int)$p['id']]);
        flash('success', 'Maintenance logged.');
        redirect('printers.php');
    }

    /* ---------- print jobs ---------- */
    if ($action === 'start_job' && ($p = own_printer($uid, post_id('printer_id')))) {
        if ($p['status'] !== 'Available') {
            flash('error', 'That printer is not available. Finish the current job first.');
            redirect('printers.php');
        }
        $source = post_str('source_type', 10);
        $source = in_array($source, ['stock', 'order', 'custom'], true) ? $source : 'custom';
        $stockId = $source === 'stock' ? post_id('stock_item_id') : null;
        $orderId = $source === 'order' ? post_id('order_id') : null;
        if ($stockId && !row('SELECT id FROM stock_items WHERE id = ? AND user_id = ?', [$stockId, $uid])) $stockId = null;
        $order = $orderId ? row('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$orderId, $uid]) : null;
        if ($orderId && !$order) $orderId = null;
        if ($order && $order['stock_item_id']) $stockId = (int)$order['stock_item_id'];
        $inventoryId = post_id('inventory_id');
        if ($inventoryId && !row('SELECT id FROM inventory WHERE id = ? AND user_id = ?', [$inventoryId, $uid])) $inventoryId = null;
        $customName = nullable(post_str('custom_job_name', 120));
        if ($source === 'stock' && !$stockId) { flash('error', 'Choose a stock product for the job.'); redirect('printers.php'); }
        if ($source === 'order' && !$orderId) { flash('error', 'Choose an order for the job.'); redirect('printers.php'); }
        if ($source === 'custom' && !$customName) { flash('error', 'Give the custom job a name.'); redirect('printers.php'); }

        $minutes = max(1, post_int('estimated_duration_minutes', 60));
        $startNow = post_str('when', 10) !== 'schedule';
        $status = $startNow ? 'Printing' : 'Scheduled';
        $pdo = db();
        $pdo->beginTransaction();
        try {
            lock_printer_job($uid, (int)$p['id']);
            if ($orderId) {
                // Refresh the order while locked, so the job uses its current product.
                $order = $startNow ? start_order_printing($uid, $orderId, (int)$p['id']) : row('SELECT * FROM orders WHERE id = ? AND user_id = ? FOR UPDATE', [$orderId, $uid]);
                if (!$order) throw new RuntimeException('Order not found.');
                $stockId = $order['stock_item_id'] ? (int)$order['stock_item_id'] : null;
            }
            q('INSERT INTO print_jobs (user_id, printer_id, source_type, stock_item_id, order_id, inventory_id, custom_job_name, customer_or_purpose, status, planned_quantity, material_used, estimated_material_quantity, estimated_duration_minutes, started_at, estimated_completion_at, notes)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $uid, (int)$p['id'], $source, $stockId, $orderId, $inventoryId, $customName, nullable(post_str('customer_or_purpose', 120)), $status,
                max(1, post_int('planned_quantity', 1)), nullable(post_str('material_used', 40)), post_num('estimated_material_quantity') ?: null, $minutes,
                $startNow ? date('Y-m-d H:i:s') : null, $startNow ? date('Y-m-d H:i:s', time() + $minutes * 60) : null, nullable(post_str('notes', 1000)),
            ]);
            $jobId = (int)db()->lastInsertId();
            printer_set_status((int)$p['id'], $status, $jobId);
            $pdo->commit();
            flash('success', $startNow ? 'Print job started.' : 'Print job scheduled.');
        } catch (Throwable $t) {
            $pdo->rollBack();
            flash('error', $t instanceof RuntimeException && !($t instanceof PDOException) ? $t->getMessage() : 'Could not save the print job. No stock was changed.');
        }
        redirect('printers.php');
    }

    if (in_array($action, ['begin_job', 'pause_job', 'resume_job', 'finish_job', 'cancel_job'], true) && ($job = own_job($uid, $id))) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            lock_printer_job($uid, (int)$job['printer_id'], $id, $action === 'begin_job');
            $job = row('SELECT * FROM print_jobs WHERE id = ? AND user_id = ? FOR UPDATE', [$id, $uid]);
            $now = date('Y-m-d H:i:s');
            if ($action === 'begin_job' && $job['status'] === 'Scheduled') {
                if ($job['order_id']) {
                    $order = start_order_printing($uid, (int)$job['order_id'], (int)$job['printer_id']);
                    q('UPDATE print_jobs SET stock_item_id = ? WHERE id = ? AND user_id = ?', [$order['stock_item_id'], $id, $uid]);
                }
                q('UPDATE print_jobs SET status = "Printing", started_at = ?, estimated_completion_at = ? WHERE id = ?', [$now, date('Y-m-d H:i:s', time() + (int)$job['estimated_duration_minutes'] * 60), $id]);
                printer_set_status((int)$job['printer_id'], 'Printing', $id);

                flash('success', 'Print job started.');
            } elseif ($action === 'pause_job' && $job['status'] === 'Printing') {
                q('UPDATE print_jobs SET status = "Paused", paused_at = ? WHERE id = ?', [$now, $id]);
                printer_set_status((int)$job['printer_id'], 'Paused', $id);
                flash('success', 'Job paused.');
            } elseif ($action === 'resume_job' && $job['status'] === 'Paused') {
                $pausedFor = $job['paused_at'] ? time() - strtotime($job['paused_at']) : 0;
                q('UPDATE print_jobs SET status = "Printing", paused_at = NULL, total_paused_seconds = total_paused_seconds + ? WHERE id = ?', [max(0, $pausedFor), $id]);
                printer_set_status((int)$job['printer_id'], 'Printing', $id);
                flash('success', 'Job resumed.');
            } elseif ($action === 'finish_job' && in_array($job['status'], ['Printing', 'Paused'], true)) {
                $pausedFor = $job['status'] === 'Paused' && $job['paused_at'] ? time() - strtotime($job['paused_at']) : 0;
                q('UPDATE print_jobs SET status = "Awaiting Confirmation", completed_at = ?, paused_at = NULL, total_paused_seconds = total_paused_seconds + ? WHERE id = ?', [$now, max(0, $pausedFor), $id]);
                printer_set_status((int)$job['printer_id'], 'Awaiting Confirmation', $id);
                flash('success', 'Print finished. Confirm how many units came out well.');
            } elseif ($action === 'cancel_job' && in_array($job['status'], ['Scheduled', 'Printing', 'Paused', 'Awaiting Confirmation'], true)) {
                q('UPDATE print_jobs SET status = "Cancelled", completed_at = COALESCE(completed_at, ?) WHERE id = ?', [$now, $id]);
                printer_set_status((int)$job['printer_id'], 'Available', null);
                flash('success', 'Job cancelled.');
            }
            $pdo->commit();
        } catch (Throwable $t) {
            $pdo->rollBack();
            flash('error', $t instanceof RuntimeException && !($t instanceof PDOException) ? $t->getMessage() : 'Could not update the print job. Please try again.');
        }
        redirect('printers.php');
    }

    if ($action === 'confirm_job' && ($job = own_job($uid, $id)) && $job['status'] === 'Awaiting Confirmation') {
        $planned = (int)$job['planned_quantity'];
        $ok = min($planned, max(0, post_int('successful_quantity', $planned)));
        $failed = $planned - $ok;
        $wasted = max(0, post_num('wasted_material'));
        $reason = nullable(post_str('failure_reason', 255));
        $status = $ok === $planned ? 'Completed' : ($ok === 0 ? 'Failed' : 'Partially Failed');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            lock_printer_job($uid, (int)$job['printer_id'], $id);
            $job = row('SELECT * FROM print_jobs WHERE id = ? AND user_id = ? FOR UPDATE', [$id, $uid]);
            if (!$job || $job['status'] !== 'Awaiting Confirmation') throw new RuntimeException('This job is no longer awaiting confirmation.');
            $now = date('Y-m-d H:i:s');
            q('UPDATE print_jobs SET status = ?, successful_quantity = ?, failed_quantity = ?, wasted_material = ?, failure_reason = ?, confirmed_at = ?, stock_update_completed = 1 WHERE id = ?',
                [$status, $ok, $failed, $wasted, $reason, $now, $id]);
            // Real print time = wall clock minus pauses
            $hours = 0.0;
            if ($job['started_at']) {
                $end = $job['completed_at'] ? strtotime($job['completed_at']) : time();
                $hours = max(0, ($end - strtotime($job['started_at']) - (int)$job['total_paused_seconds']) / 3600);
            }
            q('UPDATE printers SET status = "Available", current_job_id = NULL, total_print_hours = total_print_hours + ? WHERE id = ?', [round($hours, 2), (int)$job['printer_id']]);
            // Only successful units reach stock
            if ($job['stock_item_id'] && $ok > 0) {
                move_stock($uid, (int)$job['stock_item_id'], $ok, 'Production Completed',
                    ['print_job_id' => $id, 'printer_id' => (int)$job['printer_id'], 'successful_quantity' => $ok, 'failed_quantity' => $failed],
                    "Print job #{$id} confirmed");
            }
            // Deduct filament if a roll was linked
            if ($job['inventory_id']) {
                $grams = (int)round((float)($job['estimated_material_quantity'] ?? 0) + $wasted);
                if ($grams > 0) {
                    q('UPDATE inventory SET remaining_weight = GREATEST(0, remaining_weight - ?) WHERE id = ? AND user_id = ?', [$grams, (int)$job['inventory_id'], $uid]);
                }
            }
            // Move a linked order forward
            if ($job['order_id'] && $ok > 0) {
                q('UPDATE orders SET status = "Post-processing" WHERE id = ? AND status = "Printing"', [(int)$job['order_id']]);
            }
            $pdo->commit();
            flash('success', "Job confirmed: {$ok} successful, {$failed} failed. Status {$status}.");
        } catch (Throwable $t) {
            $pdo->rollBack();
            flash('error', 'Could not confirm the job: ' . $t->getMessage());
        }
        redirect('printers.php');
    }
    redirect('printers.php');
}

/* ---------- data for the page ---------- */
$printers = rows('SELECT * FROM printers WHERE user_id = ? ORDER BY is_active DESC, name', [$uid]);
$jobs = rows('SELECT j.*, s.product_name AS stock_name, o.product_name AS order_product, o.customer_name, p.name AS printer_name
              FROM print_jobs j LEFT JOIN stock_items s ON s.id = j.stock_item_id LEFT JOIN orders o ON o.id = j.order_id JOIN printers p ON p.id = j.printer_id
              WHERE j.user_id = ? ORDER BY FIELD(j.status,"Printing","Paused","Awaiting Confirmation","Scheduled") DESC, j.created_at DESC LIMIT 60', [$uid]);
$jobsByPrinter = [];
foreach ($jobs as $j) {
    if (in_array($j['status'], ['Scheduled', 'Printing', 'Paused', 'Awaiting Confirmation'], true)) {
        $jobsByPrinter[(int)$j['printer_id']][] = $j;
    }
}
$maint = rows('SELECT m.*, p.name AS printer_name FROM printer_maintenance_logs m JOIN printers p ON p.id = m.printer_id WHERE m.user_id = ? ORDER BY m.date DESC, m.id DESC LIMIT 20', [$uid]);
$stockItems = rows('SELECT id, product_name, sku FROM stock_items WHERE user_id = ? AND status <> "Inactive" ORDER BY product_name', [$uid]);
$openOrders = rows('SELECT id, product_name, customer_name, order_quantity, stock_item_id, material_used, grams_used, print_time FROM orders WHERE user_id = ? AND status IN ("Quote","Approved","Printing") ORDER BY created_at DESC', [$uid]);
$rolls = rows('SELECT id, material_type, colour, brand, remaining_weight FROM inventory WHERE user_id = ? ORDER BY material_type, colour', [$uid]);
$today = date('Y-m-d');

$pageTitle = 'Printers';
$pageDescription = 'Your fleet, live jobs and maintenance.';
$pageActions = '<button class="btn btn-outline" type="button" data-open="maintDialog">Log maintenance</button> <button class="btn" type="button" data-open="printerDialog">+ Add printer</button>';
$pageScripts = ['printers.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="printer-grid">
  <?php if (!$printers): ?><div class="card empty">No printers yet. Add your first machine to start tracking jobs.</div><?php endif; ?>
  <?php foreach ($printers as $p):
      $active = $jobsByPrinter[(int)$p['id']] ?? [];
      $current = null;
      foreach ($active as $j) { if ((int)$j['id'] === (int)$p['current_job_id']) { $current = $j; break; } }
      if (!$current && $active) { $current = $active[0]; }
      $maintDue = $p['next_maintenance_due'] && $p['next_maintenance_due'] <= $today;
      $life = (int)$p['estimated_lifetime_hours'] > 0 ? (float)$p['total_print_hours'] / (int)$p['estimated_lifetime_hours'] * 100 : 0;
      $json = json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
      $busy = in_array($p['status'], ['Printing', 'Paused', 'Scheduled', 'Awaiting Confirmation'], true);
  ?>
  <div class="printer-card <?= $p['status'] === 'Printing' ? 'is-printing' : '' ?> <?= $p['status'] === 'Maintenance' || $maintDue ? 'is-maint' : '' ?>">
    <div class="row-between">
      <div><div class="cell-main" style="font-size:1.05rem"><?= e($p['name']) ?></div><div class="cell-sub"><?= e($p['model']) ?> &middot; <?= e($p['printer_type']) ?></div></div>
      <span class="badge <?= badge_class($p['status']) ?>"><?= e($p['status']) ?></span>
    </div>
    <div class="printer-meta">
      <div>Material <b><?= e($p['material_loaded'] ?: '-') ?></b></div>
      <div>Nozzle <b><?= e($p['nozzle_size'] ? $p['nozzle_size'] . ' mm' : '-') ?></b></div>
      <div>Print hours <b><?= number_format((float)$p['total_print_hours'], 1) ?> h</b></div>
      <div>Depreciation <b><?= money($p['depreciation_per_hour'], $currency) ?>/h</b></div>
      <div>Power <b><?= (int)$p['average_power_consumption_watts'] ?> W</b></div>
      <div>Next service <b class="<?= $maintDue ? 'text-warning' : '' ?>"><?= $p['next_maintenance_due'] ? fmt_date($p['next_maintenance_due']) : '-' ?></b></div>
    </div>
    <div><div class="row-between small muted"><span>Lifetime used</span><span><?= number_format($life) ?>%</span></div><div class="bar <?= $life > 80 ? 'danger' : ($life > 50 ? 'warning' : '') ?>"><span style="width:<?= min(100, $life) ?>%"></span></div></div>

    <?php if ($current): $rem = job_remaining_seconds($current); ?>
      <div class="card" style="padding:12px 14px;background:var(--card-2)">
        <div class="row-between"><span class="cell-main"><?= e(job_title($current)) ?></span><span class="badge <?= badge_class($current['status']) ?>"><?= e($current['status']) ?></span></div>
        <div class="cell-sub"><?= (int)$current['planned_quantity'] ?> pcs &middot; <?= e($current['material_used'] ?: '') ?> &middot; est. <?= human_duration((int)$current['estimated_duration_minutes'] * 60) ?></div>
        <?php if (in_array($current['status'], ['Printing', 'Paused'], true)): ?>
          <div class="timer <?= $rem !== null && $rem < 0 ? 'overdue' : '' ?>" data-timer data-remaining="<?= (int)$rem ?>" data-paused="<?= $current['status'] === 'Paused' ? 1 : 0 ?>" data-total="<?= (int)$current['estimated_duration_minutes'] * 60 ?>"><?= $rem !== null ? ($rem < 0 ? '-' : '') . gmdate('H:i:s', abs($rem)) : '' ?></div>
          <div class="bar" style="margin-top:6px"><span data-progress style="width:<?= $rem !== null ? max(0, min(100, 100 - $rem / ((int)$current['estimated_duration_minutes'] * 60) * 100)) : 0 ?>%"></span></div>
        <?php endif; ?>
        <div class="row" style="margin-top:10px">
          <?php $jf = fn(string $a, string $label, string $cls = 'btn-outline') => '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="' . $a . '"><input type="hidden" name="id" value="' . (int)$current['id'] . '"><button class="btn btn-sm ' . $cls . '" type="submit">' . $label . '</button></form>'; ?>
          <?php if ($current['status'] === 'Scheduled') echo $jf('begin_job', 'Start now', ''); ?>
          <?php if ($current['status'] === 'Printing') echo $jf('pause_job', 'Pause') . $jf('finish_job', 'Finish', 'btn-success'); ?>
          <?php if ($current['status'] === 'Paused') echo $jf('resume_job', 'Resume', '') . $jf('finish_job', 'Finish', 'btn-success'); ?>
          <?php if ($current['status'] === 'Awaiting Confirmation'): ?>
            <button class="btn btn-sm" type="button" data-open="confirmDialog" data-json='<?= json_encode(['id' => $current['id'], 'planned_quantity' => $current['planned_quantity'], 'title' => job_title($current), 'successful_quantity' => $current['planned_quantity']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>'>Confirm result</button>
          <?php endif; ?>
          <form method="post" class="inline-form" data-confirm="Cancel this job? No stock will be added."><?= csrf_field() ?><input type="hidden" name="action" value="cancel_job"><input type="hidden" name="id" value="<?= (int)$current['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">Cancel</button></form>
        </div>
      </div>
    <?php endif; ?>

    <div class="row" style="margin-top:auto">
      <?php if (!$busy && $p['status'] === 'Available' && (int)$p['is_active'] === 1): ?>
        <button class="btn btn-sm" type="button" data-open="jobDialog" data-json='<?= json_encode(['printer_id' => $p['id'], 'material_used' => $p['material_loaded'], 'printer_name' => $p['name']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>'>Start print job</button>
      <?php endif; ?>
      <button class="btn btn-sm btn-outline" type="button" data-open="maintDialog" data-json='<?= json_encode(['printer_id' => $p['id']], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>Service</button>
      <button class="btn btn-sm btn-ghost" type="button" data-open="printerDialog" data-json='<?= $json ?>'>Edit</button>
      <form method="post" class="inline-form" data-confirm="Delete this printer and its job history?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_printer"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit">Delete</button></form>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid grid-main" style="margin-top:16px">
  <div class="card">
    <div class="card-title"><h3>Print job history</h3></div>
    <div class="table-wrap" style="border:0">
      <table>
        <thead><tr><th>Job</th><th>Printer</th><th>Planned</th><th>Result</th><th>Status</th><th>Started</th><th>Duration</th></tr></thead>
        <tbody>
          <?php if (!$jobs): ?><tr><td colspan="7" class="empty">No print jobs yet.</td></tr><?php endif; ?>
          <?php foreach ($jobs as $j): ?>
            <tr>
              <td><div class="cell-main"><?= e(job_title($j)) ?></div><div class="cell-sub"><?= e($j['customer_or_purpose'] ?: $j['customer_name'] ?: ucfirst($j['source_type'])) ?></div></td>
              <td><?= e($j['printer_name']) ?></td>
              <td><?= (int)$j['planned_quantity'] ?></td>
              <td><?php if ($j['confirmed_at']): ?><span class="text-success"><?= (int)$j['successful_quantity'] ?> ok</span><?php if ((int)$j['failed_quantity']): ?> / <span class="text-danger"><?= (int)$j['failed_quantity'] ?> failed</span><?php endif; ?><?php else: ?><span class="muted">-</span><?php endif; ?></td>
              <td><span class="badge <?= badge_class($j['status']) ?>"><?= e($j['status']) ?></span></td>
              <td class="small nowrap"><?= $j['started_at'] ? fmt_date($j['started_at'], 'd M H:i') : '-' ?></td>
              <td class="small"><?= human_duration((int)$j['estimated_duration_minutes'] * 60) ?> est.</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-title"><h3>Maintenance log</h3></div>
    <ul class="list">
      <?php if (!$maint): ?><li class="muted">No maintenance recorded.</li><?php endif; ?>
      <?php foreach ($maint as $m): ?>
        <li><span><span class="cell-main"><?= e($m['maintenance_type']) ?></span><br><span class="cell-sub"><?= e($m['printer_name']) ?> &middot; <?= fmt_date($m['date']) ?><?= $m['notes'] ? ' &middot; ' . e($m['notes']) : '' ?></span></span><span class="nowrap"><?= money($m['cost'], $currency) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<!-- ---------- dialogs ---------- -->
<dialog id="printerDialog">
  <form method="post" action="<?= BASE_PATH ?>/printers.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_printer"><input type="hidden" name="id" value="">
    <div class="dialog-head"><h3 data-title-create="Add printer" data-title-edit="Edit printer">Add printer</h3><button type="button" class="icon-btn" data-close aria-label="Close">&times;</button></div>
    <div class="dialog-body">
      <div class="field-row">
        <div class="field"><label>Name</label><input type="text" name="name" required maxlength="80" placeholder="P2S Main"></div>
        <div class="field"><label>Model</label><input type="text" name="model" maxlength="80" placeholder="Bambu Lab P2S"></div>
        <div class="field"><label>Type</label><select name="printer_type"><option>FDM</option><option>Resin</option><option>Other</option></select></div>
        <div class="field"><label>Status</label><select name="status"><?php foreach ($PRINTER_STATUSES as $s): ?><option><?= $s ?></option><?php endforeach; ?></select><p class="help">Running jobs keep the printer busy regardless of this value.</p></div>
        <div class="field"><label>Nozzle size (mm)</label><input type="text" name="nozzle_size" maxlength="10" placeholder="0.4"></div>
        <div class="field"><label>Material loaded</label><input type="text" name="material_loaded" maxlength="40" placeholder="PLA"></div>
        <div class="field"><label>Purchase price</label><input type="number" name="purchase_price" min="0" step="0.01" data-default="0"></div>
        <div class="field"><label>Estimated lifetime (hours)</label><input type="number" name="estimated_lifetime_hours" min="1" step="1" data-default="5000"><p class="help">Depreciation per hour = price / lifetime.</p></div>
        <div class="field"><label>Average power (W)</label><input type="number" name="average_power_consumption_watts" min="0" step="1" data-default="150"></div>
        <div class="field"><label>Maintenance cost per hour</label><input type="number" name="maintenance_cost_per_hour" min="0" step="0.01" data-default="0"></div>
        <div class="field"><label>Total print hours so far</label><input type="number" name="total_print_hours" min="0" step="0.1" data-default="0"></div>
        <div class="field"><label>Last maintenance</label><input type="date" name="last_maintenance_date"></div>
        <div class="field"><label>Next maintenance due</label><input type="date" name="next_maintenance_due"></div>
      </div>
      <div class="field"><label>Notes</label><textarea name="notes" maxlength="2000"></textarea></div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn" type="submit">Save printer</button></div>
  </form>
</dialog>

<dialog id="jobDialog">
  <form method="post" action="<?= BASE_PATH ?>/printers.php" id="jobForm">
    <?= csrf_field() ?><input type="hidden" name="action" value="start_job"><input type="hidden" name="printer_id" value="">
    <div class="dialog-head"><h3>Start print job <span class="muted" id="jobPrinterName"></span></h3><button type="button" class="icon-btn" data-close aria-label="Close">&times;</button></div>
    <div class="dialog-body">
      <div class="field"><label>What are you printing?</label>
        <select name="source_type" id="sourceType"><option value="stock">Stock product (restock)</option><option value="order">Customer order</option><option value="custom">Custom / one-off</option></select></div>
      <div class="field" data-source="stock"><label>Stock product</label><select name="stock_item_id"><option value="">Choose</option><?php foreach ($stockItems as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['product_name']) ?><?= $s['sku'] ? ' (' . e($s['sku']) . ')' : '' ?></option><?php endforeach; ?></select><p class="help">Successful units are added to stock when you confirm the result.</p></div>
      <div class="field hidden" data-source="order"><label>Order</label><select name="order_id"><option value="">Choose</option><?php foreach ($openOrders as $o): ?><option value="<?= (int)$o['id'] ?>" data-qty="<?= (int)$o['order_quantity'] ?>" data-material="<?= e($o['material_used']) ?>" data-grams="<?= e($o['grams_used']) ?>" data-minutes="<?= (int)round((float)$o['print_time'] * 60) ?>">#<?= (int)$o['id'] ?> <?= e($o['product_name']) ?> for <?= e($o['customer_name']) ?> (<?= (int)$o['order_quantity'] ?> pcs)</option><?php endforeach; ?></select><p class="help">The order moves to Printing, then to Post-processing when confirmed.</p></div>
      <div class="field hidden" data-source="custom"><label>Job name</label><input type="text" name="custom_job_name" maxlength="120" placeholder="Lithophane test"></div>
      <div class="field-row">
        <div class="field"><label>Planned quantity</label><input type="number" name="planned_quantity" min="1" step="1" data-default="1"></div>
        <div class="field"><label>Estimated duration (minutes)</label><input type="number" name="estimated_duration_minutes" min="1" step="1" data-default="60"></div>
        <div class="field"><label>Material</label><input type="text" name="material_used" maxlength="40"></div>
        <div class="field"><label>Material needed (g)</label><input type="number" name="estimated_material_quantity" min="0" step="1"></div>
        <div class="field"><label>Filament roll to deduct (optional)</label><select name="inventory_id"><option value="">None</option><?php foreach ($rolls as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['material_type'] . ' ' . $r['colour'] . ' ' . $r['brand']) ?> (<?= (int)$r['remaining_weight'] ?> g)</option><?php endforeach; ?></select></div>
        <div class="field"><label>Customer or purpose</label><input type="text" name="customer_or_purpose" maxlength="120"></div>
      </div>
      <div class="field"><label>Notes</label><textarea name="notes" maxlength="1000"></textarea></div>
      <label class="check"><input type="radio" name="when" value="now" checked> Start now</label>
      <label class="check"><input type="radio" name="when" value="schedule"> Schedule (start later from the printer card)</label>
    </div>
    <div class="dialog-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn" type="submit">Start job</button></div>
  </form>
</dialog>

<dialog id="confirmDialog" class="dialog-sm">
  <form method="post" action="<?= BASE_PATH ?>/printers.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="confirm_job"><input type="hidden" name="id" value="">
    <div class="dialog-head"><h3>Confirm print result</h3><button type="button" class="icon-btn" data-close aria-label="Close">&times;</button></div>
    <div class="dialog-body">
      <p class="muted small"><span data-show="title"></span>: <span data-show="planned_quantity"></span> planned.</p>
      <div class="field"><label>Successful units</label><input type="number" name="successful_quantity" min="0" step="1" required><p class="help">Only successful units are added to stock. The rest are recorded as failed.</p></div>
      <div class="field"><label>Wasted material (g)</label><input type="number" name="wasted_material" min="0" step="1" data-default="0"></div>
      <div class="field"><label>Failure reason (if any)</label><input type="text" name="failure_reason" maxlength="255" placeholder="Warping, spaghetti, power cut"></div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn btn-success" type="submit">Confirm</button></div>
  </form>
</dialog>

<dialog id="maintDialog" class="dialog-sm">
  <form method="post" action="<?= BASE_PATH ?>/printers.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_maintenance">
    <div class="dialog-head"><h3>Log maintenance</h3><button type="button" class="icon-btn" data-close aria-label="Close">&times;</button></div>
    <div class="dialog-body">
      <div class="field"><label>Printer</label><select name="printer_id" required><?php foreach ($printers as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field-row">
        <div class="field"><label>Type</label><select name="maintenance_type"><?php foreach ($MAINT_TYPES as $t): ?><option><?= $t ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Date</label><input type="date" name="date" data-default="<?= $today ?>" value="<?= $today ?>"></div>
        <div class="field"><label>Cost</label><input type="number" name="cost" min="0" step="0.01" data-default="0"></div>
        <div class="field"><label>Next due</label><input type="date" name="next_maintenance_due"></div>
      </div>
      <div class="field"><label>Notes</label><textarea name="notes" maxlength="1000"></textarea></div>
      <label class="check"><input type="checkbox" name="back_to_service" value="1" checked> Set printer back to Available if it was in Maintenance</label>
    </div>
    <div class="dialog-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn" type="submit">Save log</button></div>
  </form>
</dialog>

<?php require __DIR__ . '/includes/footer.php'; ?>
