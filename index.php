<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
redirect(current_user() ? 'dashboard.php' : 'login.php');
