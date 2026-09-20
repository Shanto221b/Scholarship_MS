<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/layout.php';
requireAdmin();

$types  = ['Merit', 'Need-based', 'Sports', 'Research', 'Other'];
$errors = [];
$form   = ['scholarshipid' => '', 'title' => '', 'type' => 'Merit', 'totalslots' => '', 'amount' => '', 'deadline' => '', 'applicationfee' => '0', 'description' => ''];

if (isPost()) {
    $action = (string) ($_POST['action'] ?? 'save');

    if (!verifyCsrfToken()) {
        finish('danger', 'Your session expired. Please reload the page and try again.', 'admin/scholarships.php', false);
    }

    if ($action === 'delete') {
        $id = (string) ($_POST['scholarshipid'] ?? '');
        $c = $pdo->prepare('SELECT fn_app_count(?)');
        $c->execute([$id]);
        if ((int) $c->fetchColumn() > 0) {
            finish('danger', "Scholarship $id already has applications, so it cannot be deleted.", 'admin/scholarships.php', false);
        }
        $pc = $pdo->prepare('SELECT COUNT(*) FROM payment WHERE scholarshipid = ?');
        $pc->execute([$id]);
        if ((int) $pc->fetchColumn() > 0) {
            finish('danger', "Scholarship $id has payment records, so it cannot be deleted.", 'admin/scholarships.php', false);
        }
        $d = $pdo->prepare('DELETE FROM scholarship WHERE scholarshipid = ?');
        $d->execute([$id]);
        $d->rowCount()
            ? finish('success', "Scholarship $id deleted.", 'admin/scholarships.php', false)
            : finish('warning', 'Scholarship not found.', 'admin/scholarships.php', false);
    }

    foreach ($form as $k => $_) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $isEdit = $form['scholarshipid'] !== '';

    $errors = collect_errors(
        v_text($form['title'], 'Title', 100),
        in_array($form['type'], $types, true) ? '' : 'Please choose a valid scholarship type.',
        v_int_range($form['totalslots'], 'Total slots', 1, 10000),
        v_amount($form['amount']),
        v_date($form['deadline'], 'Deadline', !$isEdit),
        ($form['applicationfee'] === '0' || $form['applicationfee'] === '0.00' || !v_amount($form['applicationfee'])) && (float) $form['applicationfee'] <= 10000
            ? '' : 'Application fee must be 0 to 10,000 (use 0 for a free application).',
        v_text($form['description'], 'Description', 500, false)
    );

    if (!$errors) {
        $dup = $pdo->prepare('SELECT 1 FROM scholarship WHERE title = ? AND scholarshipid <> ?');
        $dup->execute([$form['title'], $form['scholarshipid']]);
        if ($dup->fetch()) {
            $errors[] = 'A scholarship with this title already exists.';
        }
    }

    if (!$errors && $isEdit) {
        $ap = $pdo->prepare("SELECT COUNT(*) FROM application WHERE scholarshipid = ? AND status = 'Approved'");
        $ap->execute([$form['scholarshipid']]);
        $approved = (int) $ap->fetchColumn();
        if ((int) $form['totalslots'] < $approved) {
            $errors[] = "Total slots cannot be less than the $approved application(s) already approved.";
        }
    }

    if (!$errors) {
        try {
            if ($isEdit) {
                $u = $pdo->prepare('UPDATE scholarship SET title = ?, type = ?, totalslots = ?, amount = ?, deadline = ?, applicationfee = ?, description = ? WHERE scholarshipid = ?');
                $u->execute([$form['title'], $form['type'], (int) $form['totalslots'], $form['amount'], $form['deadline'], $form['applicationfee'], $form['description'] ?: null, $form['scholarshipid']]);
                $msg = "Scholarship {$form['scholarshipid']} updated.";
            } else {
                $i = $pdo->prepare('INSERT INTO scholarship (createdby, totalslots, deadline, amount, type, title, applicationfee, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $i->execute([$_SESSION['adminid'], (int) $form['totalslots'], $form['deadline'], $form['amount'], $form['type'], $form['title'], $form['applicationfee'], $form['description'] ?: null]);
                $msg = "Scholarship \"{$form['title']}\" created.";
            }
            finish('success', $msg, 'admin/scholarships.php');
        } catch (PDOException $ex) {
            $errors[] = 'Could not save: ' . ($ex->errorInfo[2] ?? 'database error');
        }
    }
} elseif (isset($_GET['edit'])) {
    $q = $pdo->prepare('SELECT * FROM scholarship WHERE scholarshipid = ?');
    $q->execute([$_GET['edit']]);
    if ($row = $q->fetch()) {
        $form = array_map('strval', array_intersect_key($row, $form));
    } else {
        finish('warning', 'Scholarship not found.', 'admin/scholarships.php');
    }
}

$isEdit = $form['scholarshipid'] !== '';
$list = $pdo->query("
    SELECT sc.*, ad.name AS creator,
           fn_app_count(sc.scholarshipid) AS applicants,
           (SELECT COUNT(*) FROM application a WHERE a.scholarshipid = sc.scholarshipid AND a.status = 'Approved') AS approved
    FROM scholarship sc
    JOIN admin ad ON ad.adminid = sc.createdby
    ORDER BY sc.scholarshipid
")->fetchAll();

ajax_errors($errors);

page_start('Scholarships', 'Admin', 'scholarships');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Scholarships</h1>
        <p class="page-subtitle">Create scholarship opportunities and manage their details.</p>
    </div>
</div>

<div class="content-card" id="form">
    <h2 class="card-heading"><?= $isEdit ? 'Edit scholarship <span class="id-pill">' . e($form['scholarshipid']) . '</span>' : 'Create a new scholarship' ?></h2>
    <?= error_list($errors) ?>
    <form method="POST" action="scholarships.php" class="row g-3 needs-validation" novalidate data-ajax>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="scholarshipid" value="<?= e($form['scholarshipid']) ?>">
        <div class="col-md-8">
            <label class="form-label" for="title">Title<span class="req">*</span></label>
            <input type="text" id="title" name="title" class="form-control" value="<?= e($form['title']) ?>" maxlength="100" required>
            <div class="invalid-feedback">Title is required (max 100 characters).</div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="type">Type<span class="req">*</span></label>
            <select id="type" name="type" class="form-select" required>
                <?php foreach ($types as $t): ?>
                    <option value="<?= e($t) ?>" <?= $form['type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="totalslots">Total Slots<span class="req">*</span></label>
            <input type="number" id="totalslots" name="totalslots" class="form-control" value="<?= e($form['totalslots']) ?>" min="1" max="10000" step="1" required>
            <div class="invalid-feedback">Enter a whole number from 1 to 10000.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="amount">Amount (BDT)<span class="req">*</span></label>
            <input type="number" id="amount" name="amount" class="form-control" value="<?= e($form['amount']) ?>" min="1" step="0.01" max="99999999.99" required>
            <div class="invalid-feedback">Enter a positive amount.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="deadline">Application Deadline<span class="req">*</span></label>
            <input type="date" id="deadline" name="deadline" class="form-control" value="<?= e($form['deadline']) ?>" <?= $isEdit ? '' : 'min="' . date('Y-m-d') . '"' ?> required>
            <div class="invalid-feedback">Choose a valid<?= $isEdit ? '' : ', future' ?> date.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="applicationfee">Application fee (BDT)<span class="req">*</span></label>
            <input type="number" id="applicationfee" name="applicationfee" class="form-control" value="<?= e($form['applicationfee']) ?>" min="0" max="10000" step="0.01" required>
            <div class="form-text">Paid online by the student when applying. Use 0 for free.</div>
        </div>
        <div class="col-md-8">
            <label class="form-label" for="description">Short description</label>
            <textarea id="description" name="description" class="form-control" rows="2" maxlength="500" placeholder="Who is it for? What are the requirements?"><?= e($form['description']) ?></textarea>
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary" data-busy="Saving…"><i class="bi bi-<?= $isEdit ? 'check-lg' : 'plus-lg' ?>"></i> <?= $isEdit ? 'Save changes' : 'Create scholarship' ?></button>
            <?php if ($isEdit): ?><a href="scholarships.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="content-card">
    <h2 class="card-heading">All scholarships <span class="text-muted fw-normal small" data-row-count><?= count($list) ?> total</span></h2>
    <div class="table-responsive">
        <table class="table app-table">
            <thead><tr><th>ID</th><th>Title</th><th>Type</th><th>Amount</th><th>Fee</th><th>Deadline</th><th>Slots</th><th>Applicants</th><th>Created by</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($list as $s):
                $open = $s['deadline'] >= date('Y-m-d'); ?>
                <tr>
                    <td><span class="id-pill"><?= e($s['scholarshipid']) ?></span></td>
                    <td class="fw-semibold"><?= e($s['title']) ?></td>
                    <td><span class="type-badge"><?= e($s['type'] ?: 'General') ?></span></td>
                    <td class="text-nowrap"><?= money($s['amount']) ?></td>
                    <td class="text-nowrap"><?= (float) $s['applicationfee'] > 0 ? money($s['applicationfee']) : '<span class="status-badge status-free">Free</span>' ?></td>
                    <td class="text-nowrap"><?= fmt_date($s['deadline']) ?><br><span class="status-badge status-<?= $open ? 'open' : 'closed' ?>"><?= $open ? 'Open' : 'Closed' ?></span></td>
                    <td class="text-nowrap"><?= (int) $s['approved'] ?> / <?= (int) $s['totalslots'] ?></td>
                    <td><a href="applications.php?scholarship=<?= urlencode($s['scholarshipid']) ?>"><?= (int) $s['applicants'] ?></a></td>
                    <td><?= e($s['creator']) ?></td>
                    <td class="text-end text-nowrap">
                        <a href="?edit=<?= urlencode($s['scholarshipid']) ?>#form" class="btn btn-sm btn-outline-primary btn-icon" title="Edit"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="scholarships.php" class="d-inline" data-ajax data-on-success="remove-row" data-confirm="Delete scholarship <?= e($s['scholarshipid']) ?> (<?= e($s['title']) ?>)?">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="scholarshipid" value="<?= e($s['scholarshipid']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="<?= $s['applicants'] ? 'Has applications — cannot delete' : 'Delete' ?>" <?= $s['applicants'] ? 'disabled' : '' ?>><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?><tr><td colspan="10"><div class="empty-state"><i class="bi bi-award"></i>No scholarships yet — create the first one above.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php page_end(true); ?>
