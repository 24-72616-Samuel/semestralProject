<?php
require __DIR__ . '/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(current_user() ? (is_staff() ? 'admin/dashboard.php' : 'customer/dashboard.php') : 'index.php');
csrf_check();
remember_forget($pdo);
$_SESSION = [];
session_destroy();
session_start();
flash('success', 'You have been logged out.');
redirect('index.php');
