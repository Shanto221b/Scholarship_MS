<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/payment.php';
requireStudent();

$sid = $_SESSION['studentid'];
$id  = (string) ($_GET['id'] ?? $_POST['scholarshipid'] ?? '');

$q = $pdo->prepare("
    SELECT sc.*, ad.name AS creator,
        (SELECT COUNT(*) FROM application a WHERE a.scholarshipid = sc.scholarshipid AND a.status = 'Approved') AS approved
    FROM scholarship sc JOIN admin ad ON ad.adminid = sc.createdby
    WHERE sc.scholarshipid = ?");
$q->execute([$id]);
$sch = $q->fetch();
if (!$sch) {
    finish('danger', 'That scholarship does not exist.', 'student/scholarships.php');
}

$p = $pdo->prepare('SELECT s.*, d.deptname FROM student s JOIN department d ON d.deptid = s.deptid WHERE s.studentid = ?');
$p->execute([$sid]);
$me = $p->fetch();

$ex = $pdo->prepare('SELECT appid, status FROM application WHERE studentid = ? AND scholarshipid = ?');
$ex->execute([$sid, $id]);
$existing = $ex->fetch();

$open = $sch['deadline'] >= date('Y-m-d');
$full = (int) $sch['approved'] >= (int) $sch['totalslots'];

/** Reasons the student cannot apply (empty = allowed). */
$blocked = '';
if ($existing) {
    $blocked = "You already applied for this scholarship ({$existing['appid']}, {$existing['status']}).";
} elseif (!$open) {
    $blocked = 'The application deadline for this scholarship has passed.';
} elseif ($full) {
    $blocked = 'All slots for this scholarship have already been filled.';
} elseif ($me['cgpa'] === null) {
    $blocked = 'Please add your CGPA in your profile before applying.';
}

/** Shown in place of the form after a successful AJAX submission. */
function render_success(string $appid, array $sch): string
{
    return '<div class="content-card" id="applyCard"><div class="success-panel">'
        . '<span class="big-icon"><i class="bi bi-check-lg"></i></span>'
        . '<h2 class="h5 fw-semibold mb-1">Application submitted</h2>'
        . '<p class="text-muted mb-3">Your application <span class="id-pill">' . e($appid) . '</span> for <strong>'
        . e($sch['title']) . '</strong> is now pending review.</p>'
        . '<div class="d-flex gap-2 justify-content-center flex-wrap">'
        . '<a href="applications.php" class="btn btn-primary">Track my applications</a>'
        . '<a href="scholarships.php" class="btn btn-outline-primary">Browse more scholarships</a>'
        . '</div></div></div>';
}

$errors = [];
if (isPost()) {
    if (!verifyCsrfToken()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($blocked !== '') {
        $errors[] = $blocked;
    } elseif (($_POST['declaration'] ?? '') !== '1') {
        $errors[] = 'Please confirm the declaration before submitting.';
    } elseif ((float) $sch['applicationfee'] > 0) {
        // a fee is due: the application is created only after the payment succeeds
        $pid = start_checkout($pdo, $sid, $sch);
        if (is_ajax()) {
            json_response(['ok' => true, 'redirect' => BASE_URL . 'pay/checkout.php?id=' . urlencode($pid)]);
        }
        redirect('pay/checkout.php?id=' . urlencode($pid));
    } else {
        try {
            $pdo->beginTransaction();
            // lock the scholarship row so two students can't take the last slot at the same time
            $lock = $pdo->prepare('SELECT totalslots FROM scholarship WHERE scholarshipid = ? FOR UPDATE');
            $lock->execute([$id]);
            $slots = (int) $lock->fetchColumn();
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM application WHERE scholarshipid = ? AND status = 'Approved'");
            $cnt->execute([$id]);
            if ((int) $cnt->fetchColumn() >= $slots) {
                throw new RuntimeException('All slots for this scholarship have already been filled.');
            }
            $ins = $pdo->prepare('INSERT INTO application (studentid, scholarshipid) VALUES (?, ?)');
            $ins->execute([$sid, $id]);
            $new = $pdo->prepare('SELECT appid FROM application WHERE studentid = ? AND scholarshipid = ?');
            $new->execute([$sid, $id]);
            $appid = $new->fetchColumn();
            $pdo->commit();

            if (is_ajax()) {
                json_response(['ok' => true, 'type' => 'success', 'message' => "Application $appid submitted.",
                    'fragments' => ['#applyCard' => render_success($appid, $sch)]]);
            }
            flash('success', "Application submitted. Your application ID is $appid. You can track its status here.");
            redirect('student/applications.php');
        } catch (RuntimeException $rex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $rex->getMessage();
        } catch (PDOException $pex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $pex->getCode() === '23000'
                ? 'You have already applied for this scholarship.'
                : 'Could not submit your application. Please try again.';
        }
    }
}

ajax_errors($errors);

page_start('Apply: ' . $sch['title'], 'Student', 'scholarships');
?>
<div class="breadcrumb-lite"><a href="scholarships.php">Scholarships</a> / Apply</div>
<div class="page-header">
    <div>
        <h1 class="page-title">Scholarship Application Form</h1>
        <p class="page-subtitle">Review the scholarship and your details, then submit.</p>
    </div>
</div>

<?php if ($blocked !== '' && !$errors): ?>
    <div class="alert alert-warning"><i class="bi bi-info-circle"></i><div><?= e($blocked) ?>
        <?php if ($me['cgpa'] === null && !$existing): ?> <a href="profile.php">Update profile</a><?php endif; ?></div></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="content-card h-100">
            <h2 class="card-heading"><span><i class="bi bi-award"></i> Scholarship details</span></h2>
            <div class="mb-3"><span class="type-badge"><?= e($sch['type'] ?: 'General') ?></span></div>
            <h3 class="h5 fw-semibold mb-1"><?= e($sch['title']) ?></h3>
            <div class="fs-4 fw-semibold mb-3" style="color: var(--primary)"><?= money($sch['amount']) ?></div>
            <dl class="detail-list">
                <dt>Scholarship ID</dt><dd><?= e($sch['scholarshipid']) ?></dd>
                <dt>Deadline</dt><dd><?= fmt_date($sch['deadline']) ?> <?= $open ? '' : '<span class="status-badge status-closed">Closed</span>' ?></dd>
                <dt>Slots</dt><dd><?= (int) $sch['approved'] ?> of <?= (int) $sch['totalslots'] ?> filled</dd>
                <dt>Offered by</dt><dd><?= e($sch['creator']) ?></dd>
                <dt>Application fee</dt><dd><?= (float) $sch['applicationfee'] > 0 ? money($sch['applicationfee']) : '<span class="status-badge status-free">Free</span>' ?></dd>
            </dl>
            <?php if ($sch['description']): ?><p class="text-muted small mt-3 mb-0"><?= e($sch['description']) ?></p><?php endif; ?>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="content-card" id="applyCard">
            <h2 class="card-heading"><span><i class="bi bi-person-lines-fill"></i> Applicant information</span> <a class="small-link" href="profile.php">Edit profile</a></h2>
            <?= error_list($errors) ?>
            <form method="POST" action="apply.php?id=<?= urlencode($sch['scholarshipid']) ?>" class="needs-validation" novalidate data-ajax>
                <?= csrfField() ?>
                <input type="hidden" name="scholarshipid" value="<?= e($sch['scholarshipid']) ?>">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Student ID</label><input class="form-control" value="<?= e($me['studentid']) ?>" readonly></div>
                    <div class="col-md-6"><label class="form-label">Full Name</label><input class="form-control" value="<?= e($me['name']) ?>" readonly></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" value="<?= e($me['email']) ?>" readonly></div>
                    <div class="col-md-6"><label class="form-label">Department</label><input class="form-control" value="<?= e($me['deptname']) ?>" readonly></div>
                    <div class="col-md-6"><label class="form-label">Semester</label><input class="form-control" value="<?= e($me['semester']) ?>" readonly></div>
                    <div class="col-md-6"><label class="form-label">CGPA</label><input class="form-control" value="<?= e($me['cgpa'] ?? 'Not set') ?>" readonly></div>
                    <?php if ((float) $sch['applicationfee'] > 0): ?>
                    <div class="col-12">
                        <div class="fee-box">
                            <div><div class="small text-muted">Application fee (non-refundable)</div><strong><?= money($sch['applicationfee']) ?></strong></div>
                            <div class="pay-logos">
                                <span class="pay-chip" style="background:#d91e6e">bKash</span><span class="pay-chip" style="background:#ee6b1f">Nagad</span><span class="pay-chip" style="background:#8a3a95">Rocket</span><span class="pay-chip" style="background:#2c5872">Card</span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="declaration" name="declaration" required <?= $blocked !== '' ? 'disabled' : '' ?>>
                            <label class="form-check-label" for="declaration">
                                I confirm that the information above is correct and I want to apply for this scholarship.
                            </label>
                            <div class="invalid-feedback">You must confirm before submitting.</div>
                        </div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4" data-busy="<?= (float) $sch['applicationfee'] > 0 ? 'Opening checkout…' : 'Submitting…' ?>" <?= $blocked !== '' ? 'disabled' : '' ?>>
                            <?php if ((float) $sch['applicationfee'] > 0): ?><i class="bi bi-lock"></i> Proceed to payment<?php else: ?><i class="bi bi-send"></i> Submit application<?php endif; ?>
                        </button>
                        <a href="scholarships.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<?php page_end(true); ?>
