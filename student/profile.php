<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/layout.php';
requireStudent();

$sid = $_SESSION['studentid'];
$departments = $pdo->query('SELECT deptid, deptname FROM department ORDER BY deptname')->fetchAll();
const GENDERS = ['Male', 'Female', 'Other'];

function load_me(PDO $pdo, string $sid): array
{
    $p = $pdo->prepare('SELECT s.*, u.username, u.createdat, u.lastlogin, d.deptname, d.facultyname
        FROM student s JOIN users u ON u.userid = s.userid JOIN department d ON d.deptid = s.deptid WHERE s.studentid = ?');
    $p->execute([$sid]);
    return $p->fetch();
}

$me = load_me($pdo, $sid);
$fields = ['name', 'email', 'phone', 'dob', 'gender', 'address', 'deptid', 'semester', 'cgpa', 'guardianname', 'guardianphone'];
$form = [];
foreach ($fields as $f) {
    $form[$f] = (string) ($me[$f] ?? '');
}
$profileErrors = [];
$passwordErrors = [];

if (isPost()) {
    $action = (string) ($_POST['action'] ?? '');
    if (!verifyCsrfToken()) {
        finish('danger', 'Your session expired. Please reload the page and try again.', 'student/profile.php', false);
    }

    if ($action === 'photo') {
        [$file, $err] = save_avatar($_FILES['photo'] ?? []);
        if ($err) {
            finish('danger', $err, 'student/profile.php', false);
        }
        delete_avatar($me['photo']);
        $pdo->prepare('UPDATE student SET photo = ? WHERE studentid = ?')->execute([$file, $sid]);
        $_SESSION['photo'] = $file;
        finish('success', 'Profile photo updated.', 'student/profile.php', false, ['photo' => BASE_URL . 'uploads/avatars/' . $file]);
    }
    if ($action === 'photo_remove') {
        delete_avatar($me['photo']);
        $pdo->prepare('UPDATE student SET photo = NULL WHERE studentid = ?')->execute([$sid]);
        $_SESSION['photo'] = null;
        finish('success', 'Profile photo removed.', 'student/profile.php');
    }

    if ($action === 'profile') {
        foreach ($fields as $f) {
            $form[$f] = trim((string) ($_POST[$f] ?? ''));
        }
        $profileErrors = collect_errors(
            v_name($form['name'], 'Full name'),
            v_email($form['email']),
            v_phone($form['phone']),
            v_dob($form['dob']),
            v_choice($form['gender'], GENDERS, 'gender'),
            v_text($form['address'], 'Address', 255, false),
            v_required($form['deptid'], 'Department'),
            v_semester($form['semester']),
            v_cgpa($form['cgpa'], true),
            $form['guardianname'] === '' ? '' : v_name($form['guardianname'], "Guardian's name"),
            v_phone($form['guardianphone'], "Guardian's phone")
        );
        if ($form['deptid'] !== '' && !in_array($form['deptid'], array_column($departments, 'deptid'), true)) {
            $profileErrors[] = 'Please choose a valid department.';
        }
        if (!$profileErrors) {
            $dup = $pdo->prepare('SELECT 1 FROM student WHERE email = ? AND studentid <> ?');
            $dup->execute([$form['email'], $sid]);
            if ($dup->fetch()) {
                $profileErrors[] = 'Another account already uses this email.';
            }
        }
        if (!$profileErrors) {
            $n = fn($v) => $v === '' ? null : $v;
            $pdo->prepare('UPDATE student SET name = ?, email = ?, phone = ?, dob = ?, gender = ?, address = ?, deptid = ?, semester = ?, cgpa = ?, guardianname = ?, guardianphone = ? WHERE studentid = ?')
                ->execute([$form['name'], $form['email'], $n($form['phone']), $n($form['dob']), $n($form['gender']), $n($form['address']),
                           $form['deptid'], (int) $form['semester'], $form['cgpa'], $n($form['guardianname']), $n($form['guardianphone']), $sid]);
            $_SESSION['name'] = $form['name'];
            finish('success', 'Your profile has been updated.', 'student/profile.php');
        }
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $h = $pdo->prepare('SELECT passwordhash FROM users WHERE userid = ?');
        $h->execute([$_SESSION['userid']]);
        if (!password_verify($current, (string) $h->fetchColumn())) {
            $passwordErrors[] = 'Your current password is incorrect.';
        }
        if ($msg = v_password($new, $confirm)) {
            $passwordErrors[] = $msg;
        }
        if (!$passwordErrors && $new === $current) {
            $passwordErrors[] = 'The new password must be different from the current one.';
        }
        if (!$passwordErrors) {
            $pdo->prepare('UPDATE users SET passwordhash = ? WHERE userid = ?')->execute([password_hash($new, PASSWORD_BCRYPT), $_SESSION['userid']]);
            $pdo->prepare('DELETE FROM remember_token WHERE userid = ?')->execute([$_SESSION['userid']]);
            if (isset($_COOKIE[COOKIE_REMEMBER])) {
                issue_remember_cookie($pdo, $_SESSION['userid']);
            }
            finish('success', 'Your password has been changed. Other devices have been signed out.', 'student/profile.php', false);
        }
    } elseif ($action === 'signout_others') {
        $keep = explode(':', (string) ($_COOKIE[COOKIE_REMEMBER] ?? ''), 2)[0];
        $pdo->prepare('DELETE FROM remember_token WHERE userid = ? AND selector <> ?')->execute([$_SESSION['userid'], $keep]);
        finish('success', 'You have been signed out on all other devices.', 'student/profile.php', false);
    }
}
ajax_errors($profileErrors ?: $passwordErrors);

/* ---------- data for the page ---------- */
$checks = ['phone', 'dob', 'gender', 'address', 'guardianname', 'guardianphone', 'photo', 'cgpa'];
$filled = count(array_filter($checks, fn($f) => !empty($me[$f])));
$completion = (int) round((6 + $filled) / (6 + count($checks)) * 100);   // name, email, dept, semester, username, id are always set

$c = $pdo->prepare("SELECT COUNT(*) AS apps, SUM(status='Approved') AS approved FROM application WHERE studentid = ?");
$c->execute([$sid]);
$counts = $c->fetch();
$pd = $pdo->prepare("SELECT IFNULL(SUM(amount),0) FROM payment WHERE studentid = ? AND status = 'Completed'");
$pd->execute([$sid]);
$paidTotal = (float) $pd->fetchColumn();

$act = $pdo->prepare("
    (SELECT a.applydate AS at, CONCAT('Applied for ', sc.title) AS what, a.appid AS ref, a.status AS status
       FROM application a JOIN scholarship sc ON sc.scholarshipid = a.scholarshipid WHERE a.studentid = ?)
    UNION ALL
    (SELECT COALESCE(p.paidat, p.createdat), CONCAT('Payment of ', FORMAT(p.amount, 2), ' BDT via ', IFNULL(p.method, 'checkout')), p.paymentid, p.status
       FROM payment p WHERE p.studentid = ?)
    ORDER BY at DESC LIMIT 12");
$act->execute([$sid, $sid]);
$activity = $act->fetchAll();

$sessions = $pdo->prepare('SELECT COUNT(*) FROM remember_token WHERE userid = ? AND expires > NOW()');
$sessions->execute([$_SESSION['userid']]);
$rememberCount = (int) $sessions->fetchColumn();

$val = fn($v) => $v !== null && $v !== '' ? e($v) : '<span class="text-muted">Not added</span>';

page_start('My Profile', 'Student', 'profile');
?>
<div class="profile-hero">
    <div class="profile-cover"></div>
    <div class="profile-body">
        <div class="avatar-wrap">
            <?= avatar_html($me['photo'], $me['name'], 'avatar-xl') ?>
            <form method="POST" action="profile.php" enctype="multipart/form-data" data-ajax class="no-print">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="photo">
                <label class="avatar-edit" title="Change photo" aria-label="Change profile photo">
                    <i class="bi bi-camera"></i>
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="visually-hidden" data-autosubmit>
                </label>
                <button type="submit" hidden></button>
            </form>
        </div>
        <div class="min-w-0">
            <h1 class="profile-name"><?= e($me['name']) ?></h1>
            <div class="profile-meta">
                <span><i class="bi bi-person-badge"></i><?= e($me['studentid']) ?></span>
                <span><i class="bi bi-building"></i><?= e($me['deptname']) ?></span>
                <span><i class="bi bi-mortarboard"></i>Semester <?= e($me['semester']) ?></span>
                <span><i class="bi bi-calendar3"></i>Member since <?= date('M Y', strtotime($me['createdat'])) ?></span>
            </div>
        </div>
        <div class="profile-actions">
            <div class="completion">
                <div class="d-flex justify-content-between small"><span class="text-muted">Profile completion</span><strong><?= $completion ?>%</strong></div>
                <div class="completion-bar"><span style="width: <?= $completion ?>%"></span></div>
            </div>
        </div>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><span class="stat-icon"><i class="bi bi-mortarboard"></i></span><div><div class="stat-label">CGPA</div><div class="stat-value"><?= e($me['cgpa'] ?? 'N/A') ?> <small>/ 4.00</small></div></div></div>
    <div class="stat-card"><span class="stat-icon tone-sage"><i class="bi bi-file-earmark-text"></i></span><div><div class="stat-label">Applications</div><div class="stat-value"><?= (int) $counts['apps'] ?></div></div></div>
    <div class="stat-card"><span class="stat-icon tone-deep"><i class="bi bi-patch-check"></i></span><div><div class="stat-label">Approved</div><div class="stat-value"><?= (int) $counts['approved'] ?></div></div></div>
    <div class="stat-card"><span class="stat-icon tone-amber"><i class="bi bi-cash"></i></span><div><div class="stat-label">Fees paid</div><div class="stat-value"><?= money($paidTotal) ?></div></div></div>
</div>

<div class="content-card">
    <div class="nav-tabs-clean no-print" role="tablist" data-tabs>
        <button type="button" class="active" data-tab="overview" role="tab" aria-selected="true"><i class="bi bi-person-lines-fill"></i> Overview</button>
        <button type="button" data-tab="edit" role="tab" aria-selected="false"><i class="bi bi-pencil-square"></i> Edit profile</button>
        <button type="button" data-tab="security" role="tab" aria-selected="false"><i class="bi bi-shield-lock"></i> Security</button>
        <button type="button" data-tab="activity" role="tab" aria-selected="false"><i class="bi bi-clock-history"></i> Activity</button>
    </div>

    <section class="tab-pane-clean active" id="overview">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h2 class="card-heading mb-0">Personal information</h2>
            <div class="d-flex gap-2 no-print">
                <?php if ($me['photo']): ?>
                <form method="POST" action="profile.php" data-ajax data-confirm="Remove your profile photo?">
                    <?= csrfField() ?><input type="hidden" name="action" value="photo_remove">
                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-image"></i> Remove photo</button>
                </form>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-primary" data-print><i class="bi bi-printer"></i> Print profile</button>
            </div>
        </div>
        <div class="info-grid mb-4">
            <div class="info-item"><div class="k">Full name</div><div class="v"><?= e($me['name']) ?></div></div>
            <div class="info-item"><div class="k">Username</div><div class="v"><?= e($me['username']) ?></div></div>
            <div class="info-item"><div class="k">Date of birth</div><div class="v"><?= $me['dob'] ? fmt_date($me['dob']) : $val(null) ?></div></div>
            <div class="info-item"><div class="k">Gender</div><div class="v"><?= $val($me['gender']) ?></div></div>
        </div>
        <h2 class="card-heading">Contact</h2>
        <div class="info-grid mb-4">
            <div class="info-item"><div class="k">Email</div><div class="v"><?= e($me['email']) ?></div></div>
            <div class="info-item"><div class="k">Mobile</div><div class="v"><?= $val($me['phone']) ?></div></div>
            <div class="info-item" style="grid-column: span 2"><div class="k">Address</div><div class="v"><?= $val($me['address']) ?></div></div>
        </div>
        <h2 class="card-heading">Academic</h2>
        <div class="info-grid mb-4">
            <div class="info-item"><div class="k">Department</div><div class="v"><?= e($me['deptname']) ?></div></div>
            <div class="info-item"><div class="k">Faculty</div><div class="v"><?= e($me['facultyname']) ?></div></div>
            <div class="info-item"><div class="k">Semester</div><div class="v"><?= e($me['semester']) ?></div></div>
            <div class="info-item"><div class="k">CGPA</div><div class="v"><?= $val($me['cgpa']) ?></div></div>
        </div>
        <h2 class="card-heading">Guardian</h2>
        <div class="info-grid">
            <div class="info-item"><div class="k">Name</div><div class="v"><?= $val($me['guardianname']) ?></div></div>
            <div class="info-item"><div class="k">Mobile</div><div class="v"><?= $val($me['guardianphone']) ?></div></div>
        </div>
    </section>

    <section class="tab-pane-clean" id="edit">
        <?= error_list($profileErrors) ?>
        <form method="POST" action="profile.php" class="needs-validation" novalidate data-ajax>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="profile">
            <h2 class="card-heading">Personal</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label" for="name">Full name<span class="req">*</span></label>
                    <input type="text" id="name" name="name" class="form-control" value="<?= e($form['name']) ?>" minlength="2" maxlength="100" required>
                    <div class="invalid-feedback">2-100 characters.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="dob">Date of birth</label>
                    <input type="date" id="dob" name="dob" class="form-control" value="<?= e($form['dob']) ?>" max="<?= date('Y-m-d', strtotime('-15 years')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="gender">Gender</label>
                    <select id="gender" name="gender" class="form-select">
                        <option value="">Prefer not to say</option>
                        <?php foreach (GENDERS as $g): ?><option <?= $form['gender'] === $g ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <h2 class="card-heading">Contact</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label" for="email">Email<span class="req">*</span></label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= e($form['email']) ?>" maxlength="100" required data-check="email">
                    <div class="invalid-feedback">Enter a valid email that is not used by another account.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="phone">Mobile number</label>
                    <input type="tel" id="phone" name="phone" class="form-control" value="<?= e($form['phone']) ?>" pattern="01[3-9][0-9]{8}" maxlength="11" placeholder="01XXXXXXXXX" inputmode="numeric">
                    <div class="invalid-feedback">11-digit mobile number, e.g. 01712345678.</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="address">Address</label>
                    <input type="text" id="address" name="address" class="form-control" value="<?= e($form['address']) ?>" maxlength="255" placeholder="House, road, area, city">
                </div>
            </div>
            <h2 class="card-heading">Academic</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label" for="deptid">Department<span class="req">*</span></label>
                    <select id="deptid" name="deptid" class="form-select" required>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= e($d['deptid']) ?>" <?= $form['deptid'] === $d['deptid'] ? 'selected' : '' ?>><?= e($d['deptname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="semester">Semester<span class="req">*</span></label>
                    <input type="number" id="semester" name="semester" class="form-control" value="<?= e($form['semester']) ?>" min="1" max="12" step="1" required>
                    <div class="invalid-feedback">1 to 12.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="cgpa">CGPA<span class="req">*</span></label>
                    <input type="number" id="cgpa" name="cgpa" class="form-control" value="<?= e($form['cgpa']) ?>" min="0" max="4" step="0.01" required>
                    <div class="invalid-feedback">0.00 to 4.00.</div>
                </div>
            </div>
            <h2 class="card-heading">Guardian</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label" for="guardianname">Guardian's name</label>
                    <input type="text" id="guardianname" name="guardianname" class="form-control" value="<?= e($form['guardianname']) ?>" maxlength="100">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="guardianphone">Guardian's mobile</label>
                    <input type="tel" id="guardianphone" name="guardianphone" class="form-control" value="<?= e($form['guardianphone']) ?>" pattern="01[3-9][0-9]{8}" maxlength="11" placeholder="01XXXXXXXXX" inputmode="numeric">
                    <div class="invalid-feedback">11-digit mobile number.</div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" data-busy="Saving…"><i class="bi bi-check-lg"></i> Save changes</button>
        </form>
    </section>

    <section class="tab-pane-clean" id="security">
        <div class="row g-4">
            <div class="col-lg-6">
                <h2 class="card-heading">Change password</h2>
                <?= error_list($passwordErrors) ?>
                <form method="POST" action="profile.php" class="needs-validation" novalidate data-ajax data-reset>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="password">
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password<span class="req">*</span></label>
                        <input type="password" id="current_password" name="current_password" class="form-control" required autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">New password<span class="req">*</span></label>
                        <div class="password-field">
                            <input type="password" id="password" name="password" class="form-control" minlength="6" maxlength="72" required autocomplete="new-password">
                            <button type="button" class="password-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="form-text">At least 6 characters.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="confirm_password">Confirm new password<span class="req">*</span></label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="6" maxlength="72" required autocomplete="new-password">
                        <div class="invalid-feedback">Passwords must match.</div>
                    </div>
                    <button type="submit" class="btn btn-primary" data-busy="Updating…"><i class="bi bi-key"></i> Update password</button>
                </form>
            </div>
            <div class="col-lg-6">
                <h2 class="card-heading">Sign-in activity</h2>
                <div class="info-grid mb-3">
                    <div class="info-item"><div class="k">Last sign-in</div><div class="v"><?= $me['lastlogin'] ? date('d M Y, h:i A', strtotime($me['lastlogin'])) : '—' ?></div></div>
                    <div class="info-item"><div class="k">"Keep me signed in" devices</div><div class="v"><?= $rememberCount ?></div></div>
                </div>
                <p class="text-muted small">If you signed in on a shared computer and ticked "Keep me signed in", you can sign out from everywhere except this browser.</p>
                <form method="POST" action="profile.php" data-ajax data-confirm="Sign out on all other devices?">
                    <?= csrfField() ?><input type="hidden" name="action" value="signout_others">
                    <button class="btn btn-outline-primary" data-busy="Signing out…"><i class="bi bi-box-arrow-right"></i> Sign out other devices</button>
                </form>
            </div>
        </div>
    </section>

    <section class="tab-pane-clean" id="activity">
        <h2 class="card-heading">Recent activity</h2>
        <?php if (!$activity): ?>
            <div class="empty-state"><i class="bi bi-clock-history"></i>No activity yet.</div>
        <?php else: ?>
        <ul class="timeline">
            <?php foreach ($activity as $a): ?>
            <li>
                <div><?= e($a['what']) ?> <span class="id-pill"><?= e($a['ref']) ?></span> <?= status_badge($a['status']) ?></div>
                <div class="when"><?= date('d M Y, h:i A', strtotime($a['at'])) ?></div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>
<?php page_end(true); ?>
