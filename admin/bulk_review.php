<?php
/** Approve or reject several applications at once (each one goes through proc_update_status). */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

if (!isPost() || !verifyCsrfToken()) {
    finish('danger', 'Your session expired. Please reload the page and try again.', 'admin/applications.php', false);
}
$decision = (string) ($_POST['decision'] ?? '');
if (!in_array($decision, ['Approved', 'Rejected'], true)) {
    finish('danger', 'Invalid decision.', 'admin/applications.php', false);
}
$ids = array_values(array_unique(array_filter(explode(',', (string) ($_POST['ids'] ?? '')), fn($id) => preg_match('/^APP\d{4,}$/', $id))));
if (!$ids || count($ids) > 200) {
    finish('warning', 'Select at least one pending application.', 'admin/applications.php', false);
}

$done = 0;
$problems = [];
$call = $pdo->prepare('CALL proc_update_status(?, ?, ?)');
foreach ($ids as $id) {
    try {
        $call->execute([$id, $decision, $_SESSION['adminid']]);
        $call->closeCursor();
        $done++;
    } catch (PDOException $ex) {
        $problems[] = "$id: " . ($ex->errorInfo[2] ?? 'could not be updated');
    }
}
$pending = (int) $pdo->query("SELECT COUNT(*) FROM application WHERE status = 'Pending'")->fetchColumn();
$msg = "$done application" . ($done === 1 ? '' : 's') . ' ' . strtolower($decision) . '.';
if ($problems) {
    $msg .= ' Skipped ' . count($problems) . ': ' . implode('; ', array_slice($problems, 0, 3)) . (count($problems) > 3 ? '…' : '');
}
finish($done ? 'success' : 'warning', $msg, 'admin/applications.php', false, ['pending' => $pending, 'reloadLive' => true]);
