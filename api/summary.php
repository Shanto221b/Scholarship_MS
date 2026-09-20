<?php
/** AJAX: live numbers for the admin sidebar badge. */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pending = (int) $pdo->query("SELECT COUNT(*) FROM application WHERE status = 'Pending'")->fetchColumn();
json_response(['ok' => true, 'pending' => $pending]);
