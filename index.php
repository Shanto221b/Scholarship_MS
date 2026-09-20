<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) {
    redirect('login.php');
}
redirect($_SESSION['role'] === 'Admin' ? 'admin/dashboard.php' : 'student/dashboard.php');
