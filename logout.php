<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}
logout_user();
flash('success', 'You have been signed out.');
redirect('login.php');
