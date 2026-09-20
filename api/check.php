<?php
/**
 * AJAX: is a username / email still free?
 *   GET api/check.php?field=username&value=karim
 *   -> {"available": true, "message": "Username is available."}
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';

$field = (string) ($_GET['field'] ?? '');
$value = trim((string) ($_GET['value'] ?? ''));

if ($field === 'username') {
    if ($msg = v_username($value)) {
        json_response(['available' => false, 'message' => $msg]);
    }
    $q = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
    $q->execute([$value]);
    $taken = (bool) $q->fetch();
    json_response(['available' => !$taken, 'message' => $taken ? 'This username is already taken.' : 'Username is available.']);
}

if ($field === 'email') {
    if ($msg = v_email($value)) {
        json_response(['available' => false, 'message' => $msg]);
    }
    // a logged-in student keeps their own email when editing the profile
    $q = $pdo->prepare('SELECT 1 FROM student WHERE email = ? AND studentid <> ?');
    $q->execute([$value, (string) ($_SESSION['studentid'] ?? '')]);
    $taken = (bool) $q->fetch();
    json_response(['available' => !$taken, 'message' => $taken ? 'An account with this email already exists.' : 'Email is available.']);
}

json_response(['available' => false, 'message' => 'Unknown field.'], 400);
