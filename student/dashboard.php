<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireStudent();

$sid = $_SESSION['studentid'];

$p = $pdo->prepare('SELECT s.*, d.deptname, d.facultyname, u.username FROM student s JOIN department d ON d.deptid = s.deptid JOIN users u ON u.userid = s.userid WHERE s.studentid = ?');
$p->execute([$sid]);
$me = $p->fetch();

$c = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'Pending') AS pending, SUM(status = 'Approved') AS approved, SUM(status = 'Rejected') AS rejected FROM application WHERE studentid = ?");
$c->execute([$sid]);
$counts = $c->fetch();

$r = $pdo->prepare('
    SELECT a.appid, a.status, a.applydate, sc.title, sc.amount
    FROM application a JOIN scholarship sc ON sc.scholarshipid = a.scholarshipid
    WHERE a.studentid = ? ORDER BY a.applydate DESC LIMIT 5');
$r->execute([$sid]);
$recent = $r->fetchAll();

$o = $pdo->prepare("
    SELECT sc.*,
        (SELECT COUNT(*) FROM application a WHERE a.scholarshipid = sc.scholarshipid AND a.status = 'Approved') AS approved
    FROM scholarship sc
    WHERE sc.deadline >= CURDATE()
      AND sc.scholarshipid NOT IN (SELECT scholarshipid FROM application WHERE studentid = ?)
    ORDER BY sc.deadline ASC LIMIT 3");
$o->execute([$sid]);
$open = $o->fetchAll();

page_start('Student Dashboard', 'Student', 'dashboard');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Welcome back, <?= e($me['name']) ?></h1>
        <p class="page-subtitle"><?= e($me['deptname']) ?> &middot; Semester <?= e($me['semester']) ?> &middot; <?= e($me['studentid']) ?></p>
    </div>
    <div class="page-actions">
        <a href="payments.php" class="btn btn-outline-primary"><i class="bi bi-receipt"></i> My invoices</a>
        <a href="scholarships.php" class="btn btn-primary"><i class="bi bi-search"></i> Browse scholarships</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><span class="stat-icon"><i class="bi bi-mortarboard"></i></span>
        <div><div class="stat-label">Current CGPA</div><div class="stat-value"><?= e($me['cgpa'] ?? 'N/A') ?> <small>/ 4.00</small></div></div></div>
    <div class="stat-card"><span class="stat-icon tone-sage"><i class="bi bi-send"></i></span>
        <div><div class="stat-label">Applications sent</div><div class="stat-value"><?= (int) $counts['total'] ?></div></div></div>
    <div class="stat-card"><span class="stat-icon tone-amber"><i class="bi bi-hourglass-split"></i></span>
        <div><div class="stat-label">Under review</div><div class="stat-value"><?= (int) $counts['pending'] ?></div></div></div>
    <div class="stat-card"><span class="stat-icon tone-deep"><i class="bi bi-patch-check"></i></span>
        <div><div class="stat-label">Approved</div><div class="stat-value"><?= (int) $counts['approved'] ?></div></div></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="content-card h-100">
            <h2 class="card-heading"><span><i class="bi bi-file-earmark-text"></i> My recent applications</span> <a class="small-link" href="applications.php">View all</a></h2>
            <?php if (!$recent): ?>
                <div class="empty-state"><i class="bi bi-send"></i>You haven't applied yet. <a href="scholarships.php">Browse scholarships</a></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table app-table">
                    <thead><tr><th>App ID</th><th>Scholarship</th><th>Amount</th><th>Applied</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $a): ?>
                        <tr>
                            <td><span class="id-pill"><?= e($a['appid']) ?></span></td>
                            <td><?= e($a['title']) ?></td>
                            <td class="text-nowrap"><?= money($a['amount']) ?></td>
                            <td class="text-nowrap"><?= fmt_date($a['applydate']) ?></td>
                            <td><?= status_badge($a['status']) ?></td>
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
            <h2 class="card-heading"><span><i class="bi bi-stars"></i> Open for you</span> <a class="small-link" href="scholarships.php">See all</a></h2>
            <?php if (!$open): ?>
                <div class="empty-state"><i class="bi bi-award"></i>No new open scholarships right now.</div>
            <?php endif; ?>
            <?php foreach ($open as $s): $full = (int) $s['approved'] >= (int) $s['totalslots']; ?>
                <div class="d-flex justify-content-between align-items-center gap-3 py-2 border-bottom">
                    <div class="min-w-0">
                        <div class="fw-semibold"><?= e($s['title']) ?></div>
                        <small class="text-muted"><?= money($s['amount']) ?> &middot; closes <?= fmt_date($s['deadline']) ?> &middot; fee <?= (float) $s['applicationfee'] > 0 ? strip_tags(money($s['applicationfee'])) : 'free' ?></small>
                    </div>
                    <?php if ($full): ?>
                        <span class="status-badge status-full">Full</span>
                    <?php else: ?>
                        <a href="apply.php?id=<?= urlencode($s['scholarshipid']) ?>" class="btn btn-sm btn-primary text-nowrap">Apply</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="content-card">
    <h2 class="card-heading"><span><i class="bi bi-person"></i> My profile</span> <a class="small-link" href="profile.php">Edit profile</a></h2>
    <dl class="detail-list">
        <dt>Student ID</dt><dd><?= e($me['studentid']) ?></dd>
        <dt>Username</dt><dd><?= e($me['username']) ?></dd>
        <dt>Email</dt><dd><?= e($me['email']) ?></dd>
        <dt>Department</dt><dd><?= e($me['deptname']) ?> <small class="text-muted">(<?= e($me['facultyname']) ?>)</small></dd>
    </dl>
</div>
<?php page_end(true); ?>
