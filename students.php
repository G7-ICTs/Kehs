<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

// A student's combination only applies when their class is A-Level - this
// is enforced here server-side (not just hidden by JS) so a tampered
// request can never attach a combination to an O-Level student.
function resolveCombinationId(PDO $db, int $classId, $posted): ?int {
    $stmt = $db->prepare("SELECT level FROM classes WHERE id = ?");
    $stmt->execute([$classId]);
    $level = $stmt->fetch()['level'] ?? 'O-Level';
    if ($level !== 'A-Level' || empty($posted)) {
        return null;
    }
    return (int)$posted;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $wantsLogin = !empty($_POST['create_login']);
    $kinPhone = normalizeTzPhone($_POST['kin_phone'] ?? '');
    if ($kinPhone === null) {
        flash('error', 'Next-of-kin ' . lcfirst(TZ_PHONE_ERROR));
        redirect('/admin/students.php');
    }
    try {
        $db->beginTransaction();
        $admissionNo = trim($_POST['admission_no']) ?: generateNextAdmissionNo($db);
        $classId = (int)$_POST['class_id'];
        $combinationId = resolveCombinationId($db, $classId, $_POST['combination_id'] ?? null);
        $userId = null;
        $loginUsername = null;
        $loginPassword = null;

        if ($wantsLogin) {
            $loginUsername = trim($_POST['username']) ?: $admissionNo;
            $loginPassword = trim($_POST['password']) ?: generateTempPassword();
            $stmt = $db->prepare("INSERT INTO users (role_id, name, username, password_hash, must_change_password) VALUES (4, ?, ?, ?, 1)");
            $stmt->execute([trim($_POST['full_name']), $loginUsername, password_hash($loginPassword, PASSWORD_DEFAULT)]);
            $userId = $db->lastInsertId();
        }

        $stmt = $db->prepare(
            "INSERT INTO students (user_id, admission_no, full_name, class_id, gender, dob,
                                    kin_full_name, kin_address, kin_relationship, kin_phone, combination_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $userId, $admissionNo, trim($_POST['full_name']),
            $classId, $_POST['gender'], $_POST['dob'] ?: null,
            trim($_POST['kin_full_name']) ?: null, trim($_POST['kin_address']) ?: null,
            trim($_POST['kin_relationship']) ?: null, $kinPhone ?: null,
            $combinationId,
        ]);
        $db->commit();

        logActivity($_SESSION['user_id'], 'create_student', "Added student: {$_POST['full_name']} ($admissionNo)");
        if ($wantsLogin) {
            flash('success', "Student added (Admission No. \"$admissionNo\"). Login User ID: \"$loginUsername\", Temporary Password: \"$loginPassword\" — give this to them/their parent directly.");
        } else {
            flash('success', "Student added successfully. Admission No.: \"$admissionNo\" (no login account created).");
        }
    } catch (Exception $ex) {
        $db->rollBack();
        flash('error', 'Could not add student: ' . $ex->getMessage());
    }
    redirect('/admin/students.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $kinPhone = normalizeTzPhone($_POST['kin_phone'] ?? '');
    if ($kinPhone === null) {
        flash('error', 'Next-of-kin ' . lcfirst(TZ_PHONE_ERROR));
        redirect('/admin/students.php');
    }
    $studentId = (int)$_POST['student_id'];
    $classId = (int)$_POST['class_id'];
    $combinationId = resolveCombinationId($db, $classId, $_POST['combination_id'] ?? null);
    try {
        $stmt = $db->prepare(
            "UPDATE students SET full_name=?, class_id=?, gender=?, dob=?,
                                  kin_full_name=?, kin_address=?, kin_relationship=?, kin_phone=?, combination_id=?
             WHERE id=?"
        );
        $stmt->execute([
            trim($_POST['full_name']), $classId, $_POST['gender'], $_POST['dob'] ?: null,
            trim($_POST['kin_full_name']) ?: null, trim($_POST['kin_address']) ?: null,
            trim($_POST['kin_relationship']) ?: null, $kinPhone ?: null,
            $combinationId, $studentId,
        ]);
        logActivity($_SESSION['user_id'], 'update_student', "Updated student id $studentId");
        flash('success', 'Student details updated.');
    } catch (Exception $ex) {
        flash('error', 'Could not update student: ' . $ex->getMessage());
    }
    redirect('/admin/students.php' . (isset($_POST['return_qs']) ? '?' . $_POST['return_qs'] : ''));
}

// Grant a login account to a student who doesn't have one yet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_login') {
    $studentId = (int)$_POST['student_id'];
    $stmt = $db->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if (!$student) {
        flash('error', 'Student not found.');
    } elseif ($student['user_id']) {
        flash('error', 'This student already has a login account.');
    } else {
        $username = trim($_POST['username']) ?: $student['admission_no'];
        $password = generateTempPassword();
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("INSERT INTO users (role_id, name, username, password_hash, must_change_password) VALUES (4, ?, ?, ?, 1)");
            $stmt->execute([$student['full_name'], $username, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = $db->lastInsertId();
            $db->prepare("UPDATE students SET user_id = ? WHERE id = ?")->execute([$userId, $studentId]);
            $db->commit();
            logActivity($_SESSION['user_id'], 'create_student_login', "Created login for student id $studentId");
            flash('success', "Login created. User ID: \"$username\", Temporary Password: \"$password\".");
        } catch (Exception $ex) {
            $db->rollBack();
            flash('error', 'Could not create login: ' . $ex->getMessage());
        }
    }
    redirect('/admin/students.php');
}

if (isset($_GET['delete'])) {
    try {
        $stmt = $db->prepare("SELECT user_id FROM students WHERE id = ?");
        $stmt->execute([$_GET['delete']]);
        $row = $stmt->fetch();
        $db->prepare("DELETE FROM students WHERE id = ?")->execute([$_GET['delete']]);
        if ($row && $row['user_id']) {
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$row['user_id']]);
        }
        flash('success', 'Student removed.');
        logActivity($_SESSION['user_id'], 'delete_student', "Deleted student id {$_GET['delete']}");
    } catch (PDOException $ex) {
        flash('error', 'Cannot delete this student: related records exist.');
    }
    redirect('/admin/students.php');
}

$classFilter = $_GET['class_id'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = "WHERE 1=1";
$params = [];
if ($classFilter) { $where .= " AND s.class_id = ?"; $params[] = $classFilter; }
if ($search !== '') { $where .= " AND (s.admission_no LIKE ? OR s.full_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

// Pagination - keeps this page fast as the student roll grows (a school
// with hundreds/thousands of students shouldn't dump them all into one
// unbounded table).
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$countStmt = $db->prepare("SELECT COUNT(*) c FROM students s $where");
$countStmt->execute($params);
$totalStudents = (int)$countStmt->fetch()['c'];
$totalPages = max(1, (int)ceil($totalStudents / $perPage));
$offset = (max(1, min($page, $totalPages)) - 1) * $perPage;

$sql = "SELECT s.*, c.name AS class_name, c.level AS class_level, u.username AS login_username, comb.code AS combination_code
        FROM students s JOIN classes c ON s.class_id = c.id
        LEFT JOIN users u ON s.user_id = u.id
        LEFT JOIN combinations comb ON s.combination_id = comb.id
        $where ORDER BY c.name, s.full_name LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

$classes = $db->query("SELECT * FROM classes ORDER BY name")->fetchAll();
$combinations = $db->query("SELECT * FROM combinations ORDER BY code")->fetchAll();

$pageTitle = 'Manage Students';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Students</h3>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addStudentModal">
    <i class="bi bi-plus-lg me-1"></i>Add Student
  </button>
</div>

<form class="row g-2 mb-3 align-items-end" method="GET">
  <div class="col-md-4">
    <label class="form-label small text-muted mb-1">Search by admission number or name</label>
    <div class="input-group input-group-sm">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input type="text" class="form-control" name="search" value="<?= e($search) ?>" placeholder="e.g. S4652.0001 or student name">
    </div>
  </div>
  <div class="col-auto">
    <label class="form-label small text-muted mb-1">Class</label>
    <select class="form-select form-select-sm" name="class_id">
      <option value="">All Classes</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $classFilter==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
    <a href="?" class="btn btn-sm btn-outline-secondary">Reset</a>
  </div>
</form>

<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Admission No.</th><th>Name</th><th>Class</th><th>Combination</th><th>Gender</th><th>DOB</th><th>Next of Kin</th><th>Status</th><th>Login</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($students as $s): ?>
        <tr>
          <td><?= e($s['admission_no']) ?></td>
          <td><?= e($s['full_name']) ?></td>
          <td><?= e($s['class_name']) ?></td>
          <td>
            <?php if ($s['class_level'] === 'A-Level'): ?>
              <?= $s['combination_code'] ? '<span class="badge bg-info text-dark">' . e($s['combination_code']) . '</span>' : '<span class="text-danger small">Not set</span>' ?>
            <?php else: ?>
              <span class="text-muted small">— N/A (O-Level)</span>
            <?php endif; ?>
          </td>
          <td><?= e($s['gender']) ?></td>
          <td><?= e($s['dob']) ?></td>
          <td>
            <?php if ($s['kin_full_name'] ?? null): ?>
              <span class="text-nowrap" title="<?= e($s['kin_relationship'] ?? '') ?><?= ($s['kin_address'] ?? '') ? ' · ' . e($s['kin_address']) : '' ?><?= ($s['kin_phone'] ?? '') ? ' · ' . e(formatTzPhone($s['kin_phone'])) : '' ?>">
                <i class="bi bi-person-vcard text-muted"></i> <?= e($s['kin_full_name'] ?? '') ?>
              </span>
            <?php else: ?>
              <span class="text-muted small">Not on file</span>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-success"><?= e($s['status']) ?></span></td>
          <td>
            <?php if ($s['login_username']): ?>
              <code><?= e($s['login_username']) ?></code>
            <?php else: ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('Create a login account for this student?');">
                <input type="hidden" name="action" value="create_login">
                <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                <input type="hidden" name="username" value="<?= e($s['admission_no']) ?>">
                <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-person-plus me-1"></i>Create Login</button>
              </form>
            <?php endif; ?>
          </td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-secondary" type="button"
                    data-bs-toggle="modal" data-bs-target="#editStudentModal<?= $s['id'] ?>" title="Edit / View details">
              <i class="bi bi-pencil"></i>
            </button>
            <a href="?delete=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="bi bi-trash"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$students): ?><tr><td colspan="10" class="text-center text-muted">No students found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <nav class="mt-2">
      <ul class="pagination pagination-sm mb-0">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <li class="page-item <?= $p==$page?'active':'' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page'=>$p])) ?>"><?= $p ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    </nav>
    <small class="text-muted"><?= $totalStudents ?> student(s) total.</small>
  <?php endif; ?>
</div>

<?php foreach ($students as $s): ?>
        <div class="modal fade" id="editStudentModal<?= $s['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <form method="POST" class="modal-content">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
              <input type="hidden" name="return_qs" value="<?= e(http_build_query($_GET)) ?>">
              <div class="modal-header"><h5 class="modal-title">Edit Student — <?= e($s['admission_no']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
              <div class="modal-body">
                <div class="mb-2"><label class="form-label">Full Name</label>
                  <input class="form-control" name="full_name" value="<?= e($s['full_name']) ?>" required></div>
                <div class="mb-2"><label class="form-label">Class</label>
                  <select class="form-select" name="class_id" required onchange="toggleCombinationField(this, 'combinationFieldEdit<?= $s['id'] ?>')" id="classSelectEdit<?= $s['id'] ?>">
                    <?php foreach ($classes as $c): ?>
                      <option value="<?= $c['id'] ?>" data-level="<?= e($c['level']) ?>" <?= $c['id']==$s['class_id']?'selected':'' ?>><?= e($c['name']) ?> (<?= e($c['level']) ?>)</option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-2 <?= $s['class_level']==='A-Level'?'':'d-none' ?>" id="combinationFieldEdit<?= $s['id'] ?>">
                  <label class="form-label">Combination <span class="text-muted fw-normal">(A-Level only)</span></label>
                  <select class="form-select" name="combination_id">
                    <option value="">-- Select Combination --</option>
                    <?php foreach ($combinations as $comb): ?>
                      <option value="<?= $comb['id'] ?>" <?= $s['combination_id']==$comb['id']?'selected':'' ?>><?= e($comb['code']) ?> — <?= e($comb['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted">Only applies to Form V/VI (A-Level) students; not used for O-Level.</small>
                </div>
                <div class="mb-2"><label class="form-label">Gender</label>
                  <select class="form-select" name="gender">
                    <option value="M" <?= $s['gender']=='M'?'selected':'' ?>>Male</option>
                    <option value="F" <?= $s['gender']=='F'?'selected':'' ?>>Female</option>
                  </select>
                </div>
                <div class="mb-3"><label class="form-label">Date of Birth</label>
                  <input type="date" class="form-control" name="dob" value="<?= e($s['dob']) ?>"></div>

                <hr>
                <h6 class="text-muted">Next of Kin / Emergency Contact</h6>
                <div class="mb-2"><label class="form-label">Full Name</label>
                  <input class="form-control" name="kin_full_name" value="<?= e($s['kin_full_name'] ?? '') ?>"></div>
                <div class="mb-2"><label class="form-label">Relationship</label>
                  <input class="form-control" name="kin_relationship" placeholder="e.g. Father, Mother, Guardian" value="<?= e($s['kin_relationship'] ?? '') ?>"></div>
                <div class="mb-2"><label class="form-label">Address</label>
                  <input class="form-control" name="kin_address" value="<?= e($s['kin_address'] ?? '') ?>"></div>
                <div class="mb-1"><label class="form-label">Phone</label>
                  <input type="tel" class="form-control" name="kin_phone" <?= tzPhoneAttrs() ?> value="<?= e(formatTzPhone($s['kin_phone'] ?? '')) ?>"></div>
              </div>
              <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Save Changes</button>
              </div>
            </form>
          </div>
        </div>
<?php endforeach; ?>

<div class="modal fade" id="addStudentModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="create">
      <div class="modal-header"><h5 class="modal-title">Add Student</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Admission No.</label>
          <input class="form-control" name="admission_no" placeholder="Leave blank to auto-generate, e.g. <?= e(SCHOOL_CODE) ?>.0001">
          <small class="text-muted">Format: <?= e(SCHOOL_CODE) ?>.NNNN — also used as their login ID if no separate one is set below.</small></div>
        <div class="mb-2"><label class="form-label">Full Name</label><input class="form-control" name="full_name" required></div>
        <div class="mb-2"><label class="form-label">Class</label>
          <select class="form-select" name="class_id" required onchange="toggleCombinationField(this, 'combinationFieldAdd')">
            <?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" data-level="<?= e($c['level']) ?>"><?= e($c['name']) ?> (<?= e($c['level']) ?>)</option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2 d-none" id="combinationFieldAdd">
          <label class="form-label">Combination <span class="text-muted fw-normal">(A-Level only)</span></label>
          <select class="form-select" name="combination_id">
            <option value="">-- Select Combination --</option>
            <?php foreach ($combinations as $comb): ?>
              <option value="<?= $comb['id'] ?>"><?= e($comb['code']) ?> — <?= e($comb['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="text-muted">Only applies to Form V/VI (A-Level) students; not used for O-Level.</small>
        </div>
        <div class="mb-2"><label class="form-label">Gender</label>
          <select class="form-select" name="gender"><option value="M">Male</option><option value="F">Female</option></select>
        </div>
        <div class="mb-3"><label class="form-label">Date of Birth</label><input type="date" class="form-control" name="dob"></div>

        <hr>
        <h6 class="text-muted">Next of Kin / Emergency Contact <span class="text-muted fw-normal">(optional, recommended)</span></h6>
        <div class="mb-2"><label class="form-label">Full Name</label><input class="form-control" name="kin_full_name"></div>
        <div class="mb-2"><label class="form-label">Relationship</label>
          <input class="form-control" name="kin_relationship" placeholder="e.g. Father, Mother, Guardian"></div>
        <div class="mb-2"><label class="form-label">Address</label><input class="form-control" name="kin_address"></div>
        <div class="mb-3"><label class="form-label">Phone</label>
          <input type="tel" class="form-control" name="kin_phone" <?= tzPhoneAttrs() ?>></div>

        <hr>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" id="createLoginCheck" name="create_login" value="1"
                 onchange="document.getElementById('studentLoginFields').classList.toggle('d-none', !this.checked)">
          <label class="form-check-label" for="createLoginCheck">Also create a student login account (for the Student portal)</label>
        </div>
        <div id="studentLoginFields" class="d-none border rounded p-2 bg-light">
          <div class="mb-2"><label class="form-label">Login User ID</label>
            <input class="form-control" name="username" placeholder="Leave blank to use Admission No."></div>
          <div class="mb-1"><label class="form-label">Temporary Password</label>
            <input class="form-control" name="password" placeholder="Leave blank to auto-generate a strong password"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Save Student</button>
      </div>
    </form>
  </div>
</div>

<script>
// Shows the Combination field only when the selected class is A-Level
// (Form V/VI) - it has no meaning for O-Level (Form I-IV) students.
function toggleCombinationField(selectEl, fieldId) {
  var opt = selectEl.options[selectEl.selectedIndex];
  var isALevel = opt && opt.dataset.level === 'A-Level';
  var field = document.getElementById(fieldId);
  if (field) field.classList.toggle('d-none', !isALevel);
}
document.addEventListener('DOMContentLoaded', function () {
  var addModal = document.getElementById('addStudentModal');
  if (addModal) {
    addModal.addEventListener('shown.bs.modal', function () {
      var sel = this.querySelector('select[name="class_id"]');
      if (sel) toggleCombinationField(sel, 'combinationFieldAdd');
    });
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
