<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/layout.php';
requireAdmin();

$departments = $pdo->query('SELECT deptid, deptname FROM department ORDER BY deptname')->fetchAll();
$errors = [];
$edit = null;

if (isPost()) {
    if (!verifyCsrfToken()) {
        finish('danger', 'Your session expired. Please reload the page and try again.', 'admin/students.php', false);
    }
    $id       = (string) ($_POST['studentid'] ?? '');
    $deptid   = trim((string) ($_POST['deptid'] ?? ''));
    $semester = trim((string) ($_POST['semester'] ?? ''));
    $cgpa     = trim((string) ($_POST['cgpa'] ?? ''));

    $q = $pdo->prepare('SELECT s.*, d.deptname FROM student s JOIN department d ON d.deptid = s.deptid WHERE s.studentid = ?');
    $q->execute([$id]);
    $edit = $q->fetch();
    if (!$edit) {
        finish('warning', 'Student not found.', 'admin/students.php');
    }

    $errors = collect_errors(v_semester($semester), v_cgpa($cgpa, false), v_required($deptid, 'Department'));
    if (!$errors && !in_array($deptid, array_column($departments, 'deptid'), true)) {
        $errors[] = 'Please choose a valid department.';
    }
    if (!$errors) {
        $u = $pdo->prepare('UPDATE student SET deptid = ?, semester = ?, cgpa = ? WHERE studentid = ?');
        $u->execute([$deptid, (int) $semester, $cgpa === '' ? null : $cgpa, $id]);
        finish('success', "Academic record of {$edit['name']} ($id) updated.", 'admin/students.php');
    }
    $edit = array_merge($edit, ['deptid' => $deptid, 'semester' => $semester, 'cgpa' => $cgpa]);
} elseif (isset($_GET['edit'])) {
    $q = $pdo->prepare('SELECT s.*, d.deptname FROM student s JOIN department d ON d.deptid = s.deptid WHERE s.studentid = ?');
    $q->execute([$_GET['edit']]);
    $edit = $q->fetch() ?: null;
}

$search = trim((string) ($_GET['q'] ?? ''));
$dept   = trim((string) ($_GET['dept'] ?? ''));
$sql = "
    SELECT s.*, d.deptname, u.username,
           (SELECT COUNT(*) FROM application a WHERE a.studentid = s.studentid) AS apps,
           (SELECT COUNT(*) FROM application a WHERE a.studentid = s.studentid AND a.status = 'Approved') AS approved
    FROM student s
    JOIN department d ON d.deptid = s.deptid
    JOIN users u ON u.userid = s.userid
    WHERE 1 = 1";
$params = [];
if ($dept !== '') {
    $sql .= ' AND s.deptid = ?';
    $params[] = $dept;
}
if ($search !== '') {
    $sql .= ' AND (s.studentid LIKE ? OR s.name LIKE ? OR s.email LIKE ? OR u.username LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
$sql .= ' ORDER BY s.studentid';
$st = $pdo->prepare($sql);
$st->execute($params);
$students = $st->fetchAll();

/** Student table. Returned alone for AJAX (live search). */
function render_results(array $students): void
{ ?>
    <p class="text-muted small mb-2"><?= count($students) ?> student<?= count($students) === 1 ? '' : 's' ?> found</p>
    <div class="table-responsive">
        <table class="table app-table">
            <thead><tr><th>ID</th><th>Name</th><th>Username / Email</th><th>Department</th><th>Sem</th><th>CGPA</th><th>Applications</th><th class="text-end">Edit</th></tr></thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td><span class="id-pill"><?= e($s['studentid']) ?></span></td>
                    <td class="fw-semibold"><?= e($s['name']) ?></td>
                    <td><?= e($s['username']) ?><br><small class="text-muted"><?= e($s['email']) ?></small></td>
                    <td><?= e($s['deptname']) ?></td>
                    <td><?= e($s['semester']) ?></td>
                    <td><?= e($s['cgpa'] ?? '—') ?></td>
                    <td><?= (int) $s['apps'] ?> <small class="text-muted">(<?= (int) $s['approved'] ?> approved)</small></td>
                    <td class="text-end"><a href="?edit=<?= urlencode($s['studentid']) ?>#form" class="btn btn-sm btn-outline-primary btn-icon" title="Edit academic record" aria-label="Edit <?= e($s['name']) ?>"><i class="bi bi-pencil"></i></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$students): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-people"></i>No students found.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
<?php }

if (is_ajax() && isset($_GET['partial'])) {
    render_results($students);
    exit;
}
ajax_errors($errors);

page_start('Students', 'Admin', 'students');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Students</h1>
        <p class="page-subtitle">Registered students and their academic records.</p>
    </div>
</div>

<?php if ($edit): ?>
<div class="content-card" id="form">
    <h2 class="card-heading">Update academic record — <?= e($edit['name']) ?> <span class="id-pill"><?= e($edit['studentid']) ?></span></h2>
    <?= error_list($errors) ?>
    <form method="POST" action="students.php" class="row g-3 needs-validation" novalidate data-ajax>
        <?= csrfField() ?>
        <input type="hidden" name="studentid" value="<?= e($edit['studentid']) ?>">
        <div class="col-md-4">
            <label class="form-label" for="deptid">Department<span class="req">*</span></label>
            <select id="deptid" name="deptid" class="form-select" required>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= e($d['deptid']) ?>" <?= $edit['deptid'] === $d['deptid'] ? 'selected' : '' ?>><?= e($d['deptname']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="semester">Semester<span class="req">*</span></label>
            <input type="number" id="semester" name="semester" class="form-control" value="<?= e($edit['semester']) ?>" min="1" max="12" step="1" required>
            <div class="invalid-feedback">1 to 12.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="cgpa">CGPA</label>
            <input type="number" id="cgpa" name="cgpa" class="form-control" value="<?= e($edit['cgpa']) ?>" min="0" max="4" step="0.01">
            <div class="invalid-feedback">0.00 to 4.00.</div>
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary" data-busy="Saving…"><i class="bi bi-check-lg"></i> Save</button>
            <a href="students.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="content-card">
    <form method="GET" class="filter-bar" data-live="#results" role="search">
        <h2 class="card-heading mb-0">All students</h2>
        <div class="d-flex gap-2 flex-wrap">
            <select name="dept" class="form-select" aria-label="Filter by department">
                <option value="">All departments</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= e($d['deptid']) ?>" <?= $dept === $d['deptid'] ? 'selected' : '' ?>><?= e($d['deptname']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="search" name="q" class="form-control" placeholder="Search ID, name, email…" value="<?= e($search) ?>" maxlength="100" aria-label="Search students">
            </div>
            <noscript><button class="btn btn-primary">Filter</button></noscript>
        </div>
    </form>
    <div id="results" data-live-target>
        <?php render_results($students); ?>
    </div>
</div>
<?php page_end(true); ?>
