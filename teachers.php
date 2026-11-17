<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

// -------- Handle Create --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']) ?: null;
    $phone = normalizeTzPhone($_POST['phone'] ?? '');
    if ($phone === null) {
        flash('error', TZ_PHONE_ERROR);
        redirect('/admin/teachers.php');
    }
    $staffNo = trim($_POST['staff_no']);
    $username = trim($_POST['username']) ?: generateUsernameFromName($db, $name); // e.g. "James Mushi" -> "jmushi"
    $password = trim($_POST['password']) ?: generateTempPassword();

    $departmentId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO users (role_id, name, username, email, phone, password_hash, must_change_password) VALUES (2, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$name, $username, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = $db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO teachers (user_id, staff_no, department_id) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $staffNo, $departmentId]);
        $db->commit();
        logActivity($_SESSION['user_id'], 'create_teacher', "Created teacher: $name");
        flash('success', "Teacher added. Login User ID: \"$username\", Temporary Password: \"$password\" — give this to them directly; they'll be asked to change it on first login.");
    } catch (Exception $ex) {
        $db->rollBack();
        flash('error', 'Could not add teacher: ' . $ex->getMessage());
    }
    redirect('/admin/teachers.php');
}

// -------- Handle Delete --------
if (isset($_GET['delete'])) {
    $stmt = $db->prepare("SELECT user_id FROM teachers WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    if ($row = $stmt->fetch()) {
        try {
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$row['user_id']]);
            flash('success', 'Teacher removed.');
            logActivity($_SESSION['user_id'], 'delete_teacher', "Deleted teacher id {$_GET['delete']}");
        } catch (PDOException $ex) {
            // Teacher still owns exam records (FK restrict) - fail gracefully instead of a fatal error
            flash('error', 'Cannot delete this teacher: they still have exams or results on record. Remove or reassign those first.');
        }
    }
    redirect('/admin/teachers.php');
}

$pg = paginate((int)$db->query("SELECT COUNT(*) c FROM teachers")->fetch()['c'], 50);
$teachers = $db->query(
    "SELECT t.id, t.staff_no, u.name, u.username, u.email, u.phone, u.status, d.name AS department_name,
            (SELECT COUNT(*) FROM teacher_subject_class tsc WHERE tsc.teacher_id = t.id) AS assignments
     FROM teachers t JOIN users u ON t.user_id = u.id LEFT JOIN departments d ON t.department_id = d.id ORDER BY u.name
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
)->fetchAll();
$departments = $db->query("SELECT * FROM departments ORDER BY name")->fetchAll();

$pageTitle = 'Manage Teachers';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Teachers</h3>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTeacherModal">
    <i class="bi bi-plus-lg me-1"></i>Add Teacher
  </button>
</div>

<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Name</th><th>User ID</th><th>Staff No.</th><th>Department</th><th>Phone</th><th>Assignments</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($teachers as $t): ?>
        <tr>
          <td><?= e($t['name']) ?></td>
          <td><code><?= e($t['username']) ?></code></td>
          <td><?= e($t['staff_no']) ?></td>
          <td><?= e($t['department_name'] ?? '—') ?></td>
          <td><?= e(formatTzPhone($t['phone'])) ?></td>
          <td><span class="badge bg-info"><?= (int)$t['assignments'] ?> classes</span></td>
          <td><span class="badge bg-<?= $t['status']=='active'?'success':'secondary' ?>"><?= e($t['status']) ?></span></td>
          <td>
            <a href="?delete=<?= $t['id'] ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="bi bi-trash"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$teachers): ?><tr><td colspan="8" class="text-center text-muted">No teachers yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= renderPagination($pg, 'teacher') ?>
</div>

<!-- Add Teacher Modal -->
<div class="modal fade" id="addTeacherModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="create">
      <div class="modal-header"><h5 class="modal-title">Add Teacher</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Full Name</label><input class="form-control" name="name" required></div>
        <div class="mb-2"><label class="form-label">Staff No.</label><input class="form-control" name="staff_no" required></div>
        <div class="mb-2"><label class="form-label">Login User ID</label>
          <input class="form-control" name="username" placeholder="Leave blank to auto-generate from name, e.g. jmushi">
          <small class="text-muted">This is what the teacher types to log in — letters, not the Staff No.</small></div>
        <div class="mb-2"><label class="form-label">Email (optional)</label><input type="email" class="form-control" name="email"></div>
        <div class="mb-2"><label class="form-label">Phone</label><input type="tel" class="form-control" name="phone" <?= tzPhoneAttrs() ?>></div>
        <div class="mb-2"><label class="form-label">Department</label>
          <select class="form-select" name="department_id">
            <option value="">-- None --</option>
            <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2"><label class="form-label">Temporary Password</label>
          <input class="form-control" name="password" placeholder="Leave blank to auto-generate a strong password"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Save Teacher</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
