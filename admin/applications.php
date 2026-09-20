<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireAdmin();

$statuses  = ['All', 'Pending', 'Approved', 'Rejected'];
$status    = in_array($_GET['status'] ?? 'All', $statuses, true) ? ($_GET['status'] ?? 'All') : 'All';
$search    = trim((string) ($_GET['q'] ?? ''));
$schFilter = trim((string) ($_GET['scholarship'] ?? ''));

$counts = ['All' => 0, 'Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
foreach ($pdo->query('SELECT status, COUNT(*) AS c FROM application GROUP BY status') as $r) {
    $counts[$r['status']] = (int) $r['c'];
    $counts['All'] += (int) $r['c'];
}

$sql = "
    SELECT a.appid, a.status, a.applydate,
           s.studentid, s.name AS student_name, s.email, s.cgpa,
           d.deptname, sc.scholarshipid, sc.title, adm.name AS reviewer,
           p.paymentid, p.amount AS fee, p.method
    FROM application a
    LEFT JOIN payment p ON p.appid = a.appid AND p.status = 'Completed'
    JOIN student s      ON s.studentid = a.studentid
    JOIN department d   ON d.deptid = s.deptid
    JOIN scholarship sc ON sc.scholarshipid = a.scholarshipid
    LEFT JOIN admin adm ON adm.adminid = a.approvedby
    WHERE 1 = 1";
$params = [];
if ($status !== 'All') {
    $sql .= ' AND a.status = ?';
    $params[] = $status;
}
if ($schFilter !== '') {
    $sql .= ' AND a.scholarshipid = ?';
    $params[] = $schFilter;
}
if ($search !== '') {
    $sql .= ' AND (a.appid LIKE ? OR s.studentid LIKE ? OR s.name LIKE ? OR s.email LIKE ? OR sc.title LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$sql .= " ORDER BY (a.status = 'Pending') DESC, a.applydate DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll();

/** Status tabs + table. Returned alone for AJAX (live filtering). */
function render_results(array $applications, array $counts, array $statuses, string $status, string $search, string $schFilter): void
{ ?>
    <div class="filter-tabs mb-3">
        <?php foreach ($statuses as $s): ?>
            <a class="filter-tab <?= $status === $s ? 'active' : '' ?>" data-live-set="status=<?= e($s) ?>"
               href="?<?= e(http_build_query(['status' => $s, 'q' => $search, 'scholarship' => $schFilter])) ?>">
                <?= e($s) ?><span class="count"><?= $counts[$s] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="content-card" data-bulk-scope>
        <form method="POST" action="bulk_review.php" class="bulk-bar" data-bulk-bar data-ajax hidden
              data-confirm="Apply this decision to all selected applications?">
            <?= csrfField() ?>
            <input type="hidden" name="ids" value="" data-bulk-ids>
            <span><i class="bi bi-check2-square"></i> <strong data-bulk-count>0</strong> selected</span>
            <div class="ms-auto">
                <button type="submit" name="decision" value="Approved" class="btn btn-sm btn-primary" data-busy="Approving…"><i class="bi bi-check-lg"></i> Approve selected</button>
                <button type="submit" name="decision" value="Rejected" class="btn btn-sm btn-outline-danger" data-busy="Rejecting…"><i class="bi bi-x-lg"></i> Reject selected</button>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table app-table">
                <thead><tr>
                    <th class="check-col"><input type="checkbox" class="form-check-input" data-check-all aria-label="Select all pending applications"></th>
                    <th>App ID</th><th>Student</th><th>Scholarship</th><th>Fee</th><th>Applied</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($applications as $a): ?>
                    <tr>
                        <td class="check-col"><?php if ($a['status'] === 'Pending'): ?><input type="checkbox" class="form-check-input" data-row-check value="<?= e($a['appid']) ?>" aria-label="Select <?= e($a['appid']) ?>"><?php endif; ?></td>
                        <td><span class="id-pill"><?= e($a['appid']) ?></span></td>
                        <td><strong><?= e($a['student_name']) ?></strong><span class="cell-sub"><?= e($a['studentid']) ?> &middot; <?= e($a['deptname']) ?> &middot; CGPA <?= e($a['cgpa'] ?? '—') ?></span></td>
                        <td><?= e($a['title']) ?></td>
                        <td class="text-nowrap"><?php if ($a['paymentid']): ?><?= money($a['fee']) ?><span class="cell-sub"><?= e($a['method']) ?></span><?php else: ?><span class="status-badge status-free">No fee</span><?php endif; ?></td>
                        <td class="text-nowrap"><?= fmt_date($a['applydate']) ?></td>
                        <td><?= status_badge($a['status']) ?><?php if ($a['reviewer']): ?><span class="cell-sub">by <?= e($a['reviewer']) ?></span><?php endif; ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm <?= $a['status'] === 'Pending' ? 'btn-primary' : 'btn-outline-primary' ?>" href="review.php?id=<?= urlencode($a['appid']) ?>"><?= $a['status'] === 'Pending' ? 'Review' : 'View' ?></a>
                            <a class="btn btn-sm btn-outline-secondary btn-icon" href="../application_print.php?id=<?= urlencode($a['appid']) ?>" title="Print application" aria-label="Print <?= e($a['appid']) ?>"><i class="bi bi-printer"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$applications): ?>
                    <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No applications match these filters.</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted small mb-0 mt-3">Showing <?= count($applications) ?> application<?= count($applications) === 1 ? '' : 's' ?>. Tick pending rows to approve or reject several at once.</p>
    </div>
<?php }

if (is_ajax() && isset($_GET['partial'])) {
    render_results($applications, $counts, $statuses, $status, $search, $schFilter);
    exit;
}

$scholarships = $pdo->query('SELECT scholarshipid, title FROM scholarship ORDER BY title')->fetchAll();

page_start('Applications', 'Admin', 'applications');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Scholarship Applications</h1>
        <p class="page-subtitle">Open an application to review the applicant and record a decision.</p>
    </div>
    <form method="GET" class="d-flex gap-2 flex-wrap filter-bar mb-0" data-live="#results" role="search">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <select name="scholarship" class="form-select" aria-label="Filter by scholarship">
            <option value="">All scholarships</option>
            <?php foreach ($scholarships as $s): ?>
                <option value="<?= e($s['scholarshipid']) ?>" <?= $schFilter === $s['scholarshipid'] ? 'selected' : '' ?>><?= e($s['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="search" name="q" class="form-control" placeholder="Search ID, name, email…" value="<?= e($search) ?>" maxlength="100" aria-label="Search applications">
        </div>
        <noscript><button class="btn btn-primary">Filter</button></noscript>
    </form>
</div>

<div id="results" data-live-target>
    <?php render_results($applications, $counts, $statuses, $status, $search, $schFilter); ?>
</div>
<?php page_end(true); ?>
