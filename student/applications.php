<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireStudent();

$st = $pdo->prepare('
    SELECT a.*, sc.title, sc.type, sc.amount, sc.deadline, adm.name AS reviewer, p.paymentid, p.amount AS fee
    FROM application a
    LEFT JOIN payment p ON p.appid = a.appid AND p.status = \'Completed\'
    JOIN scholarship sc ON sc.scholarshipid = a.scholarshipid
    LEFT JOIN admin adm ON adm.adminid = a.approvedby
    WHERE a.studentid = ?
    ORDER BY a.applydate DESC');
$st->execute([$_SESSION['studentid']]);
$apps = $st->fetchAll();

page_start('My Applications', 'Student', 'applications');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">My Applications</h1>
        <p class="page-subtitle">Track the status of every scholarship you applied for.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-outline-primary" data-print><i class="bi bi-printer"></i> Print list</button>
        <a href="scholarships.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Apply for more</a>
    </div>
</div>

<div class="content-card">
    <div class="table-responsive">
        <table class="table app-table">
            <thead><tr><th>App ID</th><th>Scholarship</th><th>Type</th><th>Amount</th><th>Applied on</th><th>Status</th><th>Reviewed by</th><th class="text-end no-print">Documents</th></tr></thead>
            <tbody>
            <?php foreach ($apps as $a): ?>
                <tr>
                    <td><span class="id-pill"><?= e($a['appid']) ?></span></td>
                    <td class="fw-semibold"><?= e($a['title']) ?></td>
                    <td><span class="type-badge"><?= e($a['type'] ?: 'General') ?></span></td>
                    <td class="text-nowrap"><?= money($a['amount']) ?></td>
                    <td class="text-nowrap"><?= date('d M Y, h:i A', strtotime($a['applydate'])) ?></td>
                    <td><?= status_badge($a['status']) ?></td>
                    <td><?= $a['reviewer'] ? e($a['reviewer']) : '<span class="text-muted">Waiting for review</span>' ?></td>
                    <td class="text-end text-nowrap no-print">
                        <a class="btn btn-sm btn-outline-secondary btn-icon" href="../application_print.php?id=<?= urlencode($a['appid']) ?>" title="Print application" aria-label="Print <?= e($a['appid']) ?>"><i class="bi bi-printer"></i></a>
                        <?php if ($a['paymentid']): ?><a class="btn btn-sm btn-soft" href="../invoice.php?id=<?= urlencode($a['paymentid']) ?>"><i class="bi bi-receipt"></i> Invoice</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$apps): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="bi bi-send"></i>You haven't applied for any scholarship yet. <a href="scholarships.php">Browse scholarships</a></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php page_end(true); ?>
