<?php
/**
 * Page shell: sidebar + top bar. Expects $pageTitle, $pageIcon, $pageDescription.
 * $user comes from require_login() on the calling page.
 */
declare(strict_types=1);
if (!isset($user)) {
    $user = require_login();
}
$profile = $profile ?? get_profile((int)$user['id']);
$currency = $profile['currency'] ?: 'AUD';
$current = basename($_SERVER['SCRIPT_NAME']);
$flash = take_flash();

$nav = [
    ['dashboard.php',  'Dashboard',        'grid'],
    ['calculator.php', 'Cost Calculator',  'calc'],
    ['inventory.php',  'Filament',         'spool'],
    ['stock.php',      'Stock',            'box'],
    ['printers.php',   'Printers',         'printer'],
    ['orders.php',     'Orders',           'clipboard'],
    ['invoices.php',   'Invoices',         'file'],
    ['customers.php',  'Customers',        'users'],
    ['settings.php',   'Settings',         'cog'],
];

function icon(string $name): string
{
    $icons = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'calc' => '<rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="6" x2="16" y2="6"/><line x1="8" y1="11" x2="8.01" y2="11"/><line x1="12" y1="11" x2="12.01" y2="11"/><line x1="16" y1="11" x2="16.01" y2="11"/><line x1="8" y1="15" x2="8.01" y2="15"/><line x1="12" y1="15" x2="12.01" y2="15"/><line x1="16" y1="15" x2="16" y2="19"/><line x1="8" y1="19" x2="12" y2="19"/>',
        'spool' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/><path d="M12 3v6M12 15v6M3 12h6M15 12h6"/>',
        'box' => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'printer' => '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="9" rx="2"/><path d="M6 14h12v7H6z"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 11h6M9 15h4"/>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17" cy="9" r="2.5"/><path d="M16 15.5a5 5 0 0 1 5.5 4.5"/>',
        'cog' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'PrintBoss') ?> | PrintBoss</title>
<link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/img/favicon-32.png">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <img src="<?= BASE_PATH ?>/assets/img/icon-192.png" alt="" class="brand-logo">
      <div>
        <div class="brand-name">PrintBoss</div>
        <div class="brand-sub"><?= e($profile['business_name'] ?: '3D Print Manager') ?></div>
      </div>
    </div>
    <nav class="nav">
      <div class="nav-label">Workspace</div>
      <?php foreach ($nav as [$file, $label, $ic]): ?>
        <a href="<?= BASE_PATH ?>/<?= $file ?>" class="nav-link<?= $current === $file ? ' active' : '' ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <div class="user-chip">
        <div class="avatar"><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></div>
        <div class="user-meta"><div class="user-name"><?= e($user['full_name']) ?></div><div class="user-email"><?= e($user['email']) ?></div></div>
      </div>
      <form method="post" action="<?= BASE_PATH ?>/logout.php"><?= csrf_field() ?>
        <button class="nav-link nav-button" type="submit"><?= icon('logout') ?><span>Sign out</span></button>
      </form>
    </div>
  </aside>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="main">
    <header class="topbar">
      <button class="icon-btn menu-btn" id="menuBtn" aria-label="Open menu"><?= icon('menu') ?></button>
      <div class="page-head">
        <h1 class="page-title"><?= e($pageTitle ?? '') ?></h1>
        <?php if (!empty($pageDescription)): ?><p class="page-desc"><?= e($pageDescription) ?></p><?php endif; ?>
      </div>
      <?php if (!empty($pageActions)): ?><div class="page-actions"><?= $pageActions ?></div><?php endif; ?>
    </header>

    <?php if ($flash): ?>
      <div class="toast toast-<?= e($flash['type']) ?>" id="toast" role="status"><?= e($flash['text']) ?></div>
    <?php endif; ?>

    <section class="content">
