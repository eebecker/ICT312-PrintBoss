<?php
/**
 * Shared helpers and business rules.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/* ---------------------------------------------------------------
 * Output and request helpers
 * ------------------------------------------------------------- */

/** Escape for HTML output (XSS protection). */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . BASE_PATH . '/' . ltrim($path, '/'));
    exit;
}

function flash(string $type, string $text): void
{
    start_session();
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

function take_flash(): ?array
{
    start_session();
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function post_str(string $key, int $max = 255): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = trim($v);
    return mb_substr($v, 0, $max);
}

function post_num(string $key, float $default = 0.0): float
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '' || !is_numeric($v)) {
        return $default;
    }
    return (float)$v;
}

function post_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '' || !is_numeric($v)) {
        return $default;
    }
    return (int)$v;
}

function post_id(string $key): ?int
{
    $v = $_POST[$key] ?? '';
    if ($v === '' || !ctype_digit((string)$v)) {
        return null;
    }
    return (int)$v;
}

function post_date(string $key): ?string
{
    $v = post_str($key, 10);
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

function nullable(string $v): ?string
{
    return $v === '' ? null : $v;
}

function money(float|int|string|null $v, string $currency = 'AUD'): string
{
    $n = (float)($v ?? 0);
    $symbol = ['AUD' => 'A$', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'NZD' => 'NZ$', 'BRL' => 'R$'][$currency] ?? ($currency . ' ');
    return $symbol . number_format($n, 2);
}

function pct(float|int|string|null $v): string
{
    return number_format((float)($v ?? 0), 1) . '%';
}

function fmt_date(?string $d, string $format = 'd M Y'): string
{
    if (!$d) {
        return '';
    }
    $t = strtotime($d);
    return $t ? date($format, $t) : '';
}

function badge_class(string $status): string
{
    $map = [
        'Quote' => 'badge-muted', 'Approved' => 'badge-info', 'Printing' => 'badge-primary',
        'Post-processing' => 'badge-warning', 'Ready' => 'badge-success', 'Delivered' => 'badge-success',
        'Cancelled' => 'badge-danger',
        'Draft' => 'badge-muted', 'Sent' => 'badge-info', 'Paid' => 'badge-success', 'Overdue' => 'badge-danger',
        'In Stock' => 'badge-success', 'Low Stock' => 'badge-warning', 'Out of Stock' => 'badge-danger', 'Inactive' => 'badge-muted',
        'Available' => 'badge-success', 'Scheduled' => 'badge-info', 'Paused' => 'badge-warning',
        'Awaiting Confirmation' => 'badge-warning', 'Maintenance' => 'badge-warning', 'Offline' => 'badge-muted',
        'Error' => 'badge-danger', 'Completed' => 'badge-success', 'Partially Failed' => 'badge-warning', 'Failed' => 'badge-danger',
    ];
    return $map[$status] ?? 'badge-muted';
}

/* ---------------------------------------------------------------
 * Profile
 * ------------------------------------------------------------- */

function get_profile(int $uid): array
{
    $p = row('SELECT * FROM profiles WHERE user_id = ?', [$uid]);
    if (!$p) {
        q('INSERT INTO profiles (user_id) VALUES (?)', [$uid]);
        $p = row('SELECT * FROM profiles WHERE user_id = ?', [$uid]);
    }
    return $p;
}

/* ---------------------------------------------------------------
 * Cost calculator (mirrors assets/js/calculator.js)
 * ------------------------------------------------------------- */

function calculate_cost(array $i): array
{
    $material = ($i['grams_used'] / 1000) * $i['filament_cost_per_kg'];
    $electricity = ($i['printer_power_watts'] / 1000) * $i['print_time_hours'] * $i['electricity_cost_per_kwh'];
    $depreciation = $i['print_time_hours'] * $i['depreciation_per_hour'];
    $labour = $i['labour_time_hours'] * $i['labour_hourly_rate'];
    $packaging = $i['packaging_cost'];
    $base = $material + $electricity + $depreciation + $labour + $packaging;
    $totalReal = $base * (1 + $i['failed_risk_percent'] / 100);
    $margin = min(max($i['profit_margin_percent'], 0), 95) / 100;
    $price = $margin >= 1 ? $totalReal : $totalReal / (1 - $margin);
    $profit = $price - $totalReal;
    return [
        'material_cost' => $material, 'electricity_cost' => $electricity, 'depreciation_cost' => $depreciation,
        'labour_cost' => $labour, 'packaging_cost' => $packaging, 'base_cost' => $base,
        'total_real_cost' => $totalReal, 'suggested_price' => $price, 'expected_profit' => $profit,
        'profit_margin_percent' => $price > 0 ? ($profit / $price) * 100 : 0,
    ];
}

/* ---------------------------------------------------------------
 * Stock
 * ------------------------------------------------------------- */

function derive_stock_status(int $qty, int $min, string $current): string
{
    if ($current === 'Inactive') {
        return 'Inactive';
    }
    if ($qty <= 0) {
        return 'Out of Stock';
    }
    if ($qty <= $min) {
        return 'Low Stock';
    }
    return 'In Stock';
}

/**
 * Change a stock item's quantity and write the audit row.
 * $delta is positive to add and negative to reduce.
 */
function move_stock(int $uid, int $stockId, int $delta, string $type, array $refs = [], ?string $note = null): bool
{
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $item = row('SELECT * FROM stock_items WHERE id = ? AND user_id = ? FOR UPDATE', [$stockId, $uid]);
        if (!$item) {
            throw new RuntimeException('Stock product not found.');
        }
        $prev = (int)$item['current_quantity'];
        $new = $prev + $delta;
        if ($new < 0) {
            throw new RuntimeException('Not enough stock available.');
        }
        $status = derive_stock_status($new, (int)$item['minimum_quantity'], $item['status']);
        q('UPDATE stock_items SET current_quantity = ?, status = ? WHERE id = ? AND user_id = ?', [$new, $status, $stockId, $uid]);
        q('INSERT INTO stock_movements (user_id, stock_item_id, movement_type, quantity_changed, previous_quantity, new_quantity, order_id, print_job_id, printer_id, successful_quantity, failed_quantity, note)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
            $uid, $stockId, $type, $delta, $prev, $new,
            $refs['order_id'] ?? null, $refs['print_job_id'] ?? null, $refs['printer_id'] ?? null,
            $refs['successful_quantity'] ?? null, $refs['failed_quantity'] ?? null, $note,
        ]);
        if ($ownTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $t) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $t;
    }
}

/* ---------------------------------------------------------------
 * Invoices
 * ------------------------------------------------------------- */

function invoice_totals(array $items, float $discount, bool $gstEnabled, float $amountPaid, float $gstRate = 10.0): array
{
    $subtotal = 0.0;
    foreach ($items as $it) {
        $subtotal += round((float)$it['quantity'] * (float)$it['unit_price'], 2);
    }
    $discount = max(0, $discount);
    $after = max(0, $subtotal - $discount);
    $gst = $gstEnabled ? round($after * $gstRate / 100, 2) : 0.0;
    $total = round($after + $gst, 2);
    $paid = max(0, $amountPaid);
    return [
        'subtotal' => round($subtotal, 2), 'discount' => round($discount, 2), 'gst_amount' => $gst,
        'total_amount' => $total, 'amount_paid' => round($paid, 2), 'balance_due' => round($total - $paid, 2),
    ];
}

function next_invoice_number(int $uid, string $prefix): string
{
    $prefix = trim($prefix) !== '' ? trim($prefix) : 'INV';
    $max = 0;
    foreach (rows('SELECT invoice_number FROM invoices WHERE user_id = ?', [$uid]) as $r) {
        if (preg_match('/(\d+)\s*$/', $r['invoice_number'], $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return sprintf('%s-%04d', $prefix, $max + 1);
}

/* ---------------------------------------------------------------
 * Printers and print jobs
 * ------------------------------------------------------------- */

function printer_set_status(int $printerId, string $status, ?int $jobId = null): void
{
    q('UPDATE printers SET status = ?, current_job_id = ? WHERE id = ?', [$status, $jobId, $printerId]);
}

/** Seconds remaining on a running job (negative when overdue). */
function job_remaining_seconds(array $job): ?int
{
    if (!$job['started_at']) {
        return null;
    }
    $elapsed = time() - strtotime($job['started_at']) - (int)$job['total_paused_seconds'];
    if ($job['status'] === 'Paused' && $job['paused_at']) {
        $elapsed -= time() - strtotime($job['paused_at']);
    }
    return (int)$job['estimated_duration_minutes'] * 60 - $elapsed;
}

function job_title(array $job): string
{
    if ($job['source_type'] === 'stock' && !empty($job['stock_name'])) {
        return $job['stock_name'];
    }
    if ($job['source_type'] === 'order' && !empty($job['order_product'])) {
        return $job['order_product'] . ' (order #' . $job['order_id'] . ')';
    }
    return $job['custom_job_name'] ?: 'Custom job';
}

function human_duration(int $seconds): string
{
    $seconds = abs($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? sprintf('%dh %02dm', $h, $m) : sprintf('%dm', $m);
}
