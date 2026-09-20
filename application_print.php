<?php
/** Printable copy of an application (student: own only, admin: any). */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/payment.php';

if (!isLoggedIn()) {
    deny('Please log in to continue.', 'login.php');
}
$isAdmin = $_SESSION['role'] === 'Admin';
$back    = $isAdmin ? 'admin/applications.php' : 'student/applications.php';

$q = $pdo->prepare("
    SELECT a.*, s.name, s.email, s.phone, s.cgpa, s.semester, s.dob, s.gender, s.address, s.guardianname, s.guardianphone,
           d.deptname, d.facultyname, sc.title, sc.type, sc.amount, sc.deadline, sc.applicationfee,
           rv.name AS reviewer, p.paymentid, p.invoiceno, p.method, p.trxid, p.paidat, p.amount AS paid
    FROM application a
    JOIN student s      ON s.studentid = a.studentid
    JOIN department d   ON d.deptid = s.deptid
    JOIN scholarship sc ON sc.scholarshipid = a.scholarshipid
    LEFT JOIN admin rv  ON rv.adminid = a.approvedby
    LEFT JOIN payment p ON p.appid = a.appid AND p.status = 'Completed'
    WHERE a.appid = ?");
$q->execute([(string) ($_GET['id'] ?? '')]);
$a = $q->fetch();
if (!$a || (!$isAdmin && $a['studentid'] !== ($_SESSION['studentid'] ?? ''))) {
    finish('danger', 'Application not found.', $back);
}

doc_open('Application ' . $a['appid'], BASE_URL . ($isAdmin ? 'admin/review.php?id=' . urlencode($a['appid']) : $back), 'Back');
?>
<article class="doc" aria-label="Application copy">
    <div class="doc-stamp<?= $a['status'] === 'Rejected' ? ' unpaid' : '' ?>"><?= e(strtoupper($a['status'])) ?></div>
    <?php doc_head('APPLICATION', [
        '<strong>' . e($a['appid']) . '</strong>',
        'Submitted ' . date('d M Y, h:i A', strtotime($a['applydate'])),
    ]); ?>

    <div class="doc-section-title">Applicant</div>
    <dl class="doc-grid">
        <dt>Student ID</dt><dd><?= e($a['studentid']) ?></dd>
        <dt>Full name</dt><dd><?= e($a['name']) ?></dd>
        <dt>Department</dt><dd><?= e($a['deptname']) ?>, <?= e($a['facultyname']) ?></dd>
        <dt>Semester / CGPA</dt><dd><?= e($a['semester']) ?> / <?= e($a['cgpa'] ?? 'N/A') ?></dd>
        <dt>Email / Phone</dt><dd><?= e($a['email']) ?><?= $a['phone'] ? ' &middot; ' . e($a['phone']) : '' ?></dd>
        <?php if ($a['dob'] || $a['gender']): ?><dt>Date of birth / Gender</dt><dd><?= $a['dob'] ? fmt_date($a['dob']) : '&mdash;' ?> / <?= e($a['gender'] ?: '—') ?></dd><?php endif; ?>
        <?php if ($a['address']): ?><dt>Address</dt><dd><?= e($a['address']) ?></dd><?php endif; ?>
        <?php if ($a['guardianname']): ?><dt>Guardian</dt><dd><?= e($a['guardianname']) ?><?= $a['guardianphone'] ? ' &middot; ' . e($a['guardianphone']) : '' ?></dd><?php endif; ?>
    </dl>

    <div class="doc-section-title">Scholarship</div>
    <dl class="doc-grid">
        <dt>Scholarship</dt><dd><?= e($a['title']) ?> (<?= e($a['scholarshipid']) ?>)</dd>
        <dt>Type</dt><dd><?= e($a['type'] ?: 'General') ?></dd>
        <dt>Award amount</dt><dd><?= money($a['amount']) ?></dd>
        <dt>Deadline</dt><dd><?= fmt_date($a['deadline']) ?></dd>
    </dl>

    <div class="doc-section-title">Status &amp; payment</div>
    <dl class="doc-grid">
        <dt>Status</dt><dd><strong><?= e($a['status']) ?></strong><?= $a['reviewer'] ? ' (reviewed by ' . e($a['reviewer']) . ')' : '' ?></dd>
        <?php if ($a['paymentid']): ?>
        <dt>Application fee</dt><dd><?= money($a['paid']) ?> paid by <?= e($a['method']) ?> on <?= date('d M Y', strtotime($a['paidat'])) ?></dd>
        <dt>Invoice / Trx ID</dt><dd><?= e($a['invoiceno']) ?> &middot; <?= e($a['trxid']) ?></dd>
        <?php else: ?>
        <dt>Application fee</dt><dd>None</dd>
        <?php endif; ?>
    </dl>

    <p style="font-size:12.5px;color:#66756b;margin-top:1.4rem">I confirm that the information given in this application is true and complete.</p>
    <div class="doc-sign">
        <div>Applicant's signature</div>
        <div>Authorised officer</div>
    </div>
    <div class="doc-foot">
        <span>Printed on <?= date('d M Y, h:i A') ?></span>
        <span><?= e(APP_NAME) ?> &middot; <?= e($a['appid']) ?></span>
    </div>
</article>
<?php doc_close(); ?>
