<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$u = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacy notice | PrintBoss</title>
<link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/img/favicon-32.png">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body>
<div class="auth-wrap" style="align-items:start">
  <div class="auth-card" style="width:min(760px,100%)">
    <div class="auth-brand">
      <img src="<?= BASE_PATH ?>/assets/img/icon-192.png" alt="PrintBoss logo">
      <div><h1>Privacy notice</h1><p>How PrintBoss handles your data</p></div>
    </div>
    <p>PrintBoss is a self-hosted web information system. Everything you enter is stored in a MySQL database on the server where the application is installed. The application was designed with the Australian Privacy Principles (APPs) in the <em>Privacy Act 1988</em> (Cth) in mind.</p>
    <h3>What we collect</h3>
    <ul>
      <li><strong>Account data:</strong> your name, email address and a bcrypt hash of your password. The plain password is never stored.</li>
      <li><strong>Business data:</strong> customers, orders, invoices, stock, filament and printer records that you create.</li>
      <li><strong>Customer personal information:</strong> names, contact details and addresses of your customers, used only to prepare quotes, orders and invoices (APP 3 and APP 6).</li>
    </ul>
    <h3>How it is protected</h3>
    <ul>
      <li>Every record is linked to your account and can only be read by you after signing in. Queries always filter by the signed-in user.</li>
      <li>All database access uses prepared statements, which blocks SQL injection. All output is escaped to prevent cross-site scripting.</li>
      <li>Forms carry a one-time security token (CSRF protection). Sessions use HttpOnly cookies and expire after 8 hours of inactivity.</li>
      <li>Login is locked for five minutes after five failed attempts.</li>
    </ul>
    <h3>Your rights</h3>
    <ul>
      <li>You can view and correct your data at any time in the application (APP 12 and APP 13).</li>
      <li>You can delete your account and every record linked to it from <strong>Settings &gt; Danger zone</strong>. Deletion is immediate and permanent.</li>
      <li>Bank details entered in Settings appear on invoices only. They are stored for that purpose and nothing else.</li>
    </ul>
    <p class="muted small">This notice is part of the ICT312 Assignment 02 group project at Wentworth Institute of Higher Education. It is an academic prototype, not a commercial service.</p>
    <div class="auth-footer"><a href="<?= BASE_PATH ?>/<?= $u ? 'dashboard.php' : 'login.php' ?>">&larr; Back to PrintBoss</a></div>
  </div>
</div>
</body>
</html>
