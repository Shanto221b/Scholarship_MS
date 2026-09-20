<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/layout.php';
requireAdmin();

$errors = [];
$form = ['deptid' => '', 'deptname' => '', 'facultyname' => ''];

if (isPost()) {
    if (!verifyCsrfToken()) {
        finish('danger', 'Your session expired. Please reload the page and try again.', 'admin/departments.php', false);
    }
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'delete') {
        $id = (string) ($_POST['deptid'] ?? '');
        $c = $pdo->prepare('SELECT COUNT(*) FROM student WHERE deptid = ?');
        $c->execute([$id]);
        $n = (int) $c->fetchColumn();
        if ($n > 0) {
            finish('danger', "Department $id has $n student(s), so it cannot be deleted.", 'admin/departments.php', false);
        }
        $d = $pdo->prepare('DELETE FROM department WHERE deptid = ?');
        $d->execute([$id]);
        $d->rowCount()
            ? finish('success', "Department $id deleted.", 'admin/departments.php', false)
            : finish('warning', 'Department not found.', 'admin/departments.php', false);
    }

    foreach ($form as $k => $_) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $errors = collect_errors(
        v_text($form['deptname'], 'Department name', 100),
        v_text($form['facultyname'], 'Faculty name', 100)
    );
    if (!$errors) {
        $dup = $pdo->prepare('SELECT 1 FROM department WHERE deptname = ? AND deptid <> ?');
        $dup->execute([$form['deptname'], $form['deptid']]);
        if ($dup->fetch()) {
            $errors[] = 'A department with this name already exists.';
        }
    }
    if (!$errors) {
        if ($form['deptid'] !== '') {
            $u = $pdo->prepare('UPDATE department SET deptname = ?, facultyname = ? WHERE deptid = ?');
            $u->execute([$form['deptname'], $form['facultyname'], $form['deptid']]);
            $msg = "Department {$form['deptid']} updated.";
        } else {
            $i = $pdo->prepare('INSERT INTO department (deptname, facultyname) VALUES (?, ?)');
            $i->execute([$form['deptname'], $form['facultyname']]);
            $msg = "Department \"{$form['deptname']}\" added.";
        }
        finish('success', $msg, 'admin/departments.php');
    }
} elseif (isset($_GET['edit'])) {
    $q = $pdo->prepare('SELECT * FROM department WHERE deptid = ?');
    $q->execute([$_GET['edit']]);
    if ($row = $q->fetch()) {
        $form = $row;
    } else {
        finish('warning', 'Department not found.', 'admin/departments.php');
    }
}

$isEdit = $form['deptid'] !== '';
$list = $pdo->query('
    SELECT d.*, (SELECT COUNT(*) FROM student s WHERE s.deptid = d.deptid) AS students
    FROM department d ORDER BY d.deptid
')->fetchAll();
$faculties = $pdo->query('SELECT DISTINCT facultyname FROM department ORDER BY facultyname')->fetchAll(PDO::FETCH_COLUMN);

ajax_errors($errors);

page_start('Departments', 'Admin', 'departments');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Departments</h1>
        <p class="page-subtitle">Departments students can choose when they register.</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="content-card" id="form">
            <h2 class="card-heading"><?= $isEdit ? 'Edit department <span class="id-pill">' . e($form['deptid']) . '</span>' : 'Add a department' ?></h2>
            <?= error_list($errors) ?>
            <form method="POST" action="departments.php" class="needs-validation" novalidate data-ajax>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="deptid" value="<?= e($form['deptid']) ?>">
                <div class="mb-3">
                    <label class="form-label" for="deptname">Department Name<span class="req">*</span></label>
                    <input type="text" id="deptname" name="deptname" class="form-control" value="<?= e($form['deptname']) ?>" maxlength="100" required placeholder="e.g. CSE">
                    <div class="invalid-feedback">Department name is required.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="facultyname">Faculty Name<span class="req">*</span></label>
                    <input type="text" id="facultyname" name="facultyname" class="form-control" value="<?= e($form['facultyname']) ?>" maxlength="100" required list="faculties" placeholder="e.g. Faculty of Engineering">
                    <datalist id="faculties"><?php foreach ($faculties as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist>
                    <div class="invalid-feedback">Faculty name is required.</div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary" data-busy="Saving…"><i class="bi bi-<?= $isEdit ? 'check-lg' : 'plus-lg' ?>"></i> <?= $isEdit ? 'Save changes' : 'Add department' ?></button>
                    <?php if ($isEdit): ?><a href="departments.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="content-card">
            <h2 class="card-heading">All departments <span class="text-muted fw-normal small" data-row-count><?= count($list) ?> total</span></h2>
            <div class="table-responsive">
                <table class="table app-table">
                    <thead><tr><th>ID</th><th>Department</th><th>Faculty</th><th>Students</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($list as $d): ?>
                        <tr>
                            <td><span class="id-pill"><?= e($d['deptid']) ?></span></td>
                            <td class="fw-semibold"><?= e($d['deptname']) ?></td>
                            <td><?= e($d['facultyname']) ?></td>
                            <td><?= (int) $d['students'] ?></td>
                            <td class="text-end text-nowrap">
                                <a href="?edit=<?= urlencode($d['deptid']) ?>#form" class="btn btn-sm btn-outline-primary btn-icon" title="Edit"><i class="bi bi-pencil"></i></a>
                                <form method="POST" action="departments.php" class="d-inline" data-ajax data-on-success="remove-row" data-confirm="Delete department <?= e($d['deptname']) ?>?">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="deptid" value="<?= e($d['deptid']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="<?= $d['students'] ? 'Has students — cannot delete' : 'Delete' ?>" <?= $d['students'] ? 'disabled' : '' ?>><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$list): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-building"></i>No departments yet.</div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php page_end(true); ?>
