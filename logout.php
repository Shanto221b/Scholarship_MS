<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

$role = $_SESSION['role'] ?? ($_COOKIE[COOKIE_LAST_ROLE] ?? 'Student');
clear_remember_cookie($pdo);          // forget "Remember me" on this device
$_SESSION = [];
session_regenerate_id(true);
flash('success', 'You have been signed out.');
redirect('login.php?role=' . ($role === 'Admin' ? 'Admin' : 'Student'));
