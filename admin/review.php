<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireAdmin();

$appid = (string) ($_GET['id'] ?? $_POST['appid'] ?? '');

function load_application(PDO $pdo, string $appid): array|false
{
    $q = $pdo->prepare("
        SELECT a.*, s.name AS student_name, s.email, s.cgpa, s.semester, s.studentid,
               u.username, d.deptname, d.facultyname,
               sc.title, sc.type, sc.amount, sc.deadline, sc.totalslots,
               cr.name AS creator_name, rv.name AS reviewer_name,
               p.paymentid, p.invoiceno, p.amount AS fee, p.method, p.account, p.trxid, p.paidat,
               (SELECT COUNT(*) FROM application x WHERE x.scholarshipid = a.scholarshipid AND x.status = 'Approved') AS approved_count,
               fn_app_count(a.scholarshipid) AS applicant_count,
               (SELECT COUNT(*) FROM application y WHERE y.studentid = a.studentid) AS student_app_count
        FROM application a
        JOIN student s      ON s.studentid = a.studentid
        JOIN users u        ON u.userid = s.userid
        JOIN department d   ON d.deptid = s.deptid
        JOIN scholarship sc ON sc.scholarshipid = a.scholarshipid
        JOIN admin cr       ON cr.adminid = sc.createdby
        LEFT JOIN admin rv  ON rv.adminid = a.approvedby
        LEFT JOIN payment p ON p.appid = a.appid AND p.status = 'Completed'
        WHERE a.appid = ?");
    $q->execute([$appid]);
    return $q->fetch();
}

function slots_left(array $app): int
{
    return max(0, (int) $app['totalslots'] - (int) $app['approved_count']);
}

/* ---------- fragments (also sent back to the browser after an AJAX decision) ---------- */

function render_status(array $app): string
{
    return '<div id="statusBadge">' . status_badge($app['status']) . '</div>';
}

function render_slots(array $app): string
{
    $pct = $app['totalslots'] > 0 ? min(100, round($app['approved_count'] / $app['totalslots'] * 100)) : 100;
    return '<dd id="slotInfo">' . (int) $app['approved_count'] . ' of ' . (int) $app['totalslots'] . ' filled '
         . '<small class="text-muted">(' . slots_left($app) . ' left)</small>'
         . '<div class="slot-bar mb-0"><span style="width: ' . $pct . '%"></span></div></dd>';
}

function render_decision(array $app): string
{
    ob_start(); ?>
<div class="content-card" id="decisionCard">
    <h2 class="card-heading"><span><i class="bi bi-clipboard-check"></i> Decision</span></h2>
    <?php if ($app['status'] === 'Pending'): ?>
        <?php if (slots_left($app) === 0): ?>
            <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i><div>All slots for this scholarship are filled. This application can only be rejected.</div></div>
        <?php endif; ?>
        <p class="text-muted">Check the applicant's CGPA and details above, then record your decision. It will be saved under your name (<?= e($_SESSION['name']) ?>).</p>
        <div class="d-flex gap-2 flex-wrap">
            <form method="POST" data-ajax data-confirm="Approve application <?= e($app['appid']) ?> for <?= e($app['student_name']) ?>?">
                <?= csrfField() ?>
                <input type="hidden" name="appid" value="<?= e($app['appid']) ?>">
                <button type="submit" name="decision" value="Approved" class="btn btn-success px-4" data-busy="Approving…" <?= slots_left($app) === 0 ? 'disabled' : '' ?>>
                    <i class="bi bi-check-lg"></i> Approve
                </button>
            </form>
            <form method="POST" data-ajax data-confirm="Reject application <?= e($app['appid']) ?> for <?= e($app['student_name']) ?>?">
                <?= csrfField() ?>
                <input type="hidden" name="appid" value="<?= e($app['appid']) ?>">
                <button type="submit" name="decision" value="Rejected" class="btn btn-outline-danger px-4" data-busy="Rejecting…">
                    <i class="bi bi-x-lg"></i> Reject
                </button>
            </form>
        </div>
    <?php else: ?>
        <div class="decision-result is-<?= e(strtolower($app['status'])) ?>">
            <i class="bi bi-<?= $app['status'] === 'Approved' ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
            <div>This application was <strong><?= e(strtolower($app['status'])) ?></strong>
                by <strong><?= e($app['reviewer_name'] ?? 'an admin') ?></strong>.</div>
        </div>
    <?php endif; ?>
</div>
    <?php
    return (string) ob_get_clean();
}

$app = load_application($pdo, $appid);
if (!$app) {
    finish('danger', 'Application not found.', 'admin/applications.php');
}

if (isPost()) {
    $decision = (string) ($_POST['decision'] ?? '');
    $back = 'admin/review.php?id=' . urlencode($appid);
    if (!verifyCsrfToken()) {
        finish('danger', 'Your session expired. Please reload the page and try again.', $back, false);
    }
    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        finish('danger', 'Invalid decision.', $back, false);
    }
    if ($app['status'] !== 'Pending') {
        finish('warning', 'This application has already been reviewed.', $back, false);
    }
    try {
        // Stored procedure: validates status, pending state and slot capacity
        $call = $pdo->prepare('CALL proc_update_status(?, ?, ?)');
        $call->execute([$appid, $decision, $_SESSION['adminid']]);
        $call->closeCursor();
    } catch (PDOException $ex) {
        finish('danger', $ex->errorInfo[2] ?? 'Could not update the application.', $back, false);
    }

    $app = load_application($pdo, $appid);
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM application WHERE status = 'Pending'")->fetchColumn();
    finish('success', "Application $appid has been " . strtolower($decision) . '.', $back, false, [
        'pending'   => $pending,
        'fragments' => [
            '#decisionCard' => render_decision($app),
            '#statusBadge'  => render_status($app),
            '#slotInfo'     => render_slots($app),
        ],
    ]);
}

page_start('Review ' . $app['appid'], 'Admin', 'applications');
?>
<div class="breadcrumb-lite"><a href="applications.php">Applications</a> / <?= e($app['appid']) ?></div>
<div class="page-header">
    <div>
        <h1 class="page-title">Application Review <span class="id-pill ms-1 align-middle"><?= e($app['appid']) ?></span></h1>
        <p class="page-subtitle">Submitted on <?= date('d M Y, h:i A', strtotime($app['applydate'])) ?></p>
    </div>
    <div class="page-actions align-items-center">
        <?= render_status($app) ?>
        <a class="btn btn-outline-primary" href="../application_print.php?id=<?= urlencode($app['appid']) ?>"><i class="bi bi-printer"></i> Print</a>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="content-card h-100">
            <h2 class="card-heading"><span><i class="bi bi-person-badge"></i> Applicant</span></h2>
            <dl class="detail-list">
                <dt>Student ID</dt><dd><?= e($app['studentid']) ?></dd>
                <dt>Name</dt><dd><?= e($app['student_name']) ?></dd>
                <dt>Username</dt><dd><?= e($app['username']) ?></dd>
                <dt>Email</dt><dd><a href="mailto:<?= e($app['email']) ?>"><?= e($app['email']) ?></a></dd>
                <dt>Department</dt><dd><?= e($app['deptname']) ?> <small class="text-muted">(<?= e($app['facultyname']) ?>)</small></dd>
                <dt>Semester</dt><dd><?= e($app['semester']) ?></dd>
                <dt>CGPA</dt><dd><strong><?= e($app['cgpa'] ?? '—') ?></strong> <small class="text-muted">/ 4.00</small></dd>
                <dt>Applications</dt><dd><?= (int) $app['student_app_count'] ?> in total</dd>
            </dl>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="content-card h-100">
            <h2 class="card-heading"><span><i class="bi bi-award"></i> Scholarship</span></h2>
            <dl class="detail-list">
                <dt>Scholarship</dt><dd><?= e($app['title']) ?> <span class="id-pill"><?= e($app['scholarshipid']) ?></span></dd>
                <dt>Type</dt><dd><span class="type-badge"><?= e($app['type'] ?: 'General') ?></span></dd>
                <dt>Amount</dt><dd><?= money($app['amount']) ?></dd>
                <dt>Deadline</dt><dd><?= fmt_date($app['deadline']) ?></dd>
                <dt>Created by</dt><dd><?= e($app['creator_name']) ?></dd>
                <dt>Applicants</dt><dd><?= (int) $app['applicant_count'] ?></dd>
                <dt>Slots</dt>
                <?= render_slots($app) ?>
                <dt>Application fee</dt>
                <dd><?php if ($app['paymentid']): ?>
                    <?= money($app['fee']) ?> paid by <?= e($app['method']) ?> <small class="text-muted">(<?= e($app['account']) ?>)</small><br>
                    <small class="text-muted">Trx <code><?= e($app['trxid']) ?></code> &middot; <a href="../invoice.php?id=<?= urlencode($app['paymentid']) ?>"><?= e($app['invoiceno']) ?></a></small>
                <?php else: ?><span class="status-badge status-free">No fee</span><?php endif; ?></dd>
            </dl>
        </div>
    </div>
</div>

<?= render_decision($app) ?>

<a href="applications.php" class="btn btn-outline-primary"><i class="bi bi-arrow-left"></i> Back to applications</a>
<?php page_end(true); ?>
