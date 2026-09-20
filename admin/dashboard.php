<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/payment.php';
requireAdmin();

$stats = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM student) AS students,
        (SELECT COUNT(*) FROM scholarship) AS scholarships,
        (SELECT COUNT(*) FROM scholarship WHERE deadline >= CURDATE()) AS open_scholarships,
        (SELECT COUNT(*) FROM application WHERE status = 'Pending') AS pending,
        (SELECT COUNT(*) FROM application WHERE status = 'Approved') AS approved,
        (SELECT COUNT(*) FROM application) AS applications,
        fn_fee_collected(NULL) AS collected,
        (SELECT IFNULL(SUM(amount),0) FROM payment WHERE status = 'Completed' AND paidat >= CURDATE() - INTERVAL 6 DAY) AS week
")->fetch();

$pendingApps = $pdo->query("
    SELECT a.appid, a.applydate, s.studentid, s.name AS student_name, s.cgpa, sc.title
    FROM application a
    JOIN student s ON s.studentid = a.studentid
    JOIN scholarship sc ON sc.scholarshipid = a.scholarshipid
    WHERE a.status = 'Pending'
    ORDER BY a.applydate ASC
    LIMIT 6
")->fetchAll();

// fn_app_count() / fn_fee_collected() are stored functions from the database
$slots = $pdo->query("
    SELECT sc.scholarshipid, sc.title, sc.totalslots, sc.deadline,
           fn_app_count(sc.scholarshipid) AS applicants,
           fn_fee_collected(sc.scholarshipid) AS fees,
           (SELECT COUNT(*) FROM application a WHERE a.scholarshipid = sc.scholarshipid AND a.status = 'Approved') AS approved
    FROM scholarship sc
    ORDER BY sc.deadline DESC
")->fetchAll();

$payments = $pdo->query("
    SELECT p.paymentid, p.amount, p.method, p.paidat, s.name
    FROM payment p JOIN student s ON s.studentid = p.studentid
    WHERE p.status = 'Completed' ORDER BY p.paidat DESC LIMIT 5
")->fetchAll();

// application status breakdown (for the small chart)
$byStatus = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM application GROUP BY status') as $r) {
    $byStatus[$r['status']] = (int) $r['n'];
}
$allApps = max(1, array_sum($byStatus));
$thisWeek = (int) $pdo->query('SELECT COUNT(*) FROM application WHERE applydate >= CURDATE() - INTERVAL 6 DAY')->fetchColumn();

page_start('Admin Dashboard', 'Admin', 'dashboard');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Good <?= (int) date('G') < 12 ? 'morning' : ((int) date('G') < 17 ? 'afternoon' : 'evening') ?>, <?= e($_SESSION['name']) ?></h1>
        <p class="page-subtitle">Here is what is happening with scholarships today.</p>
    </div>
    <div class="page-actions">
        <a href="applications.php?status=Pending" class="btn btn-outline-primary"><i class="bi bi-clipboard-check"></i> Review pending</a>
        <a href="scholarships.php#form" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New scholarship</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><span class="stat-icon tone-amber"><i class="bi bi-hourglass-split"></i></span>
        <div><div class="stat-label">Pending review</div><div class="stat-value" data-pending-count-text><?= (int) $stats['pending'] ?></div>
        <a class="stat-link" href="applications.php?status=Pending">Review now</a></div></div>
    <div class="stat-card"><span class="stat-icon"><i class="bi bi-patch-check"></i></span>
        <div><div class="stat-label">Approved</div><div class="stat-value"><?= (int) $stats['approved'] ?> <small>of <?= (int) $stats['applications'] ?></small></div>
        <a class="stat-link" href="applications.php?status=Approved">View approved</a></div></div>
    <div class="stat-card"><span class="stat-icon tone-deep"><i class="bi bi-cash-stack"></i></span>
        <div><div class="stat-label">Fees collected</div><div class="stat-value"><?= money($stats['collected']) ?></div>
        <a class="stat-link" href="payments.php"><?= strip_tags(money($stats['week'])) ?> this week</a></div></div>
    <div class="stat-card"><span class="stat-icon tone-sage"><i class="bi bi-award"></i></span>
        <div><div class="stat-label">Open now</div><div class="stat-value"><?= (int) $stats['open_scholarships'] ?> <small>of <?= (int) $stats['scholarships'] ?></small></div>
        <a class="stat-link" href="scholarships.php">Manage</a></div></div>
    <div class="stat-card"><span class="stat-icon tone-sage"><i class="bi bi-people"></i></span>
        <div><div class="stat-label">Students</div><div class="stat-value"><?= (int) $stats['students'] ?></div>
        <a class="stat-link" href="students.php">View students</a></div></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="content-card h-100">
            <h2 class="card-heading"><span><i class="bi bi-inbox"></i> Waiting for review</span> <a class="small-link" href="applications.php?status=Pending">View all</a></h2>
            <?php if (!$pendingApps): ?>
                <div class="empty-state"><i class="bi bi-check2-all"></i>No pending applications. Everything is reviewed.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table app-table">
                    <thead><tr><th>App ID</th><th>Student</th><th>Scholarship</th><th>CGPA</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($pendingApps as $a): ?>
                        <tr>
                            <td><span class="id-pill"><?= e($a['appid']) ?></span></td>
                            <td><?= e($a['student_name']) ?><span class="cell-sub"><?= e($a['studentid']) ?> &middot; <?= fmt_date($a['applydate']) ?></span></td>
                            <td><?= e($a['title']) ?></td>
                            <td class="fw-semibold"><?= e($a['cgpa'] ?? '—') ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-primary" href="review.php?id=<?= urlencode($a['appid']) ?>">Review</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="content-card h-100">
            <h2 class="card-heading"><span><i class="bi bi-pie-chart"></i> Application status</span><span class="small text-muted fw-normal"><?= $thisWeek ?> new this week</span></h2>
            <div class="bar-chart">
                <?php foreach ($byStatus as $st => $n): ?>
                <div class="bar-row">
                    <span><?= status_badge($st) ?></span>
                    <div class="bar-track"><div class="bar-fill bar-<?= strtolower($st) ?>" style="width: <?= round($n / $allApps * 100) ?>%"></div></div>
                    <span class="bar-value"><?= $n ?> <small class="text-muted fw-normal">(<?= round($n / $allApps * 100) ?>%)</small></span>
                </div>
                <?php endforeach; ?>
            </div>
            <hr class="my-3">
            <div class="d-flex justify-content-between small"><span class="text-muted">Total applications</span><strong><?= array_sum($byStatus) ?></strong></div>
            <div class="d-flex justify-content-between small mt-1"><span class="text-muted">Approval rate (of reviewed)</span><strong><?= ($byStatus['Approved'] + $byStatus['Rejected']) ? round($byStatus['Approved'] / ($byStatus['Approved'] + $byStatus['Rejected']) * 100) : 0 ?>%</strong></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="content-card h-100">
            <h2 class="card-heading"><span><i class="bi bi-award"></i> Scholarships</span> <a class="small-link" href="scholarships.php">Manage</a></h2>
            <div class="table-responsive">
                <table class="table app-table">
                    <thead><tr><th>Scholarship</th><th>Slots filled</th><th>Applied</th><th class="text-end">Fees</th></tr></thead>
                    <tbody>
                    <?php foreach ($slots as $s):
                        $pct = $s['totalslots'] > 0 ? min(100, round($s['approved'] / $s['totalslots'] * 100)) : 100; ?>
                        <tr>
                            <td><span class="fw-semibold"><?= e($s['title']) ?></span><span class="cell-sub">Deadline <?= fmt_date($s['deadline']) ?></span></td>
                            <td style="min-width:140px"><span class="small"><?= (int) $s['approved'] ?>/<?= (int) $s['totalslots'] ?></span><div class="slot-bar mb-0"><span style="width: <?= $pct ?>%"></span></div></td>
                            <td><a href="applications.php?scholarship=<?= urlencode($s['scholarshipid']) ?>"><?= (int) $s['applicants'] ?></a></td>
                            <td class="text-end text-nowrap"><?= money($s['fees']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$slots): ?><tr><td colspan="4"><div class="empty-state">No scholarships yet.</div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="content-card h-100">
            <h2 class="card-heading"><span><i class="bi bi-wallet2"></i> Latest payments</span> <a class="small-link" href="payments.php">All payments</a></h2>
            <?php if (!$payments): ?><div class="empty-state">No payments yet.</div><?php endif; ?>
            <ul class="list-unstyled mb-0">
                <?php foreach ($payments as $p): ?>
                <li class="d-flex align-items-center gap-3 py-2 border-bottom">
                    <?= method_badge($p['method']) ?>
                    <div class="min-w-0 flex-fill"><div class="text-truncate"><?= e($p['name']) ?></div><span class="cell-sub"><?= date('d M, h:i A', strtotime($p['paidat'])) ?></span></div>
                    <div class="text-end"><strong><?= money($p['amount']) ?></strong><br><a class="small" href="../invoice.php?id=<?= urlencode($p['paymentid']) ?>">Invoice</a></div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php page_end(true); ?>
