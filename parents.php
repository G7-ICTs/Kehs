<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $password = trim($_POST['password']) ?: generateTempPassword();
    $username = trim($_POST['username']);
    $phone = normalizeTzPhone($_POST['phone'] ?? '');
    if ($phone === null) {
        flash('error', TZ_PHONE_ERROR);
        redirect('/admin/parents.php');
    }
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO users (role_id, name, username, email, phone, password_hash, must_change_password) VALUES (3, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([trim($_POST['name']), $username, trim($_POST['email']) ?: null, $phone, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = $db->lastInsertId();
        $db->prepare("INSERT INTO parents_guardians (user_id) VALUES (?)")->execute([$userId]);
        $db->commit();
        flash('success', "Parent added. Login User ID: \"$username\", Temporary Password: \"$password\" — give this to them directly; they'll be asked to change it on first login.");
        logActivity($_SESSION['user_id'], 'create_parent', "Added parent: {$_POST['name']}");
    } catch (Exception $ex) {
        $db->rollBack();
        flash('error', 'Could not add parent: ' . $ex->getMessage());
    }
    redirect('/admin/parents.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link') {
    try {
        $stmt = $db->prepare("INSERT IGNORE INTO parent_student (parent_id, student_id, relationship) VALUES (?, ?, ?)");
        $stmt->execute([(int)$_POST['parent_id'], (int)$_POST['student_id'], trim($_POST['relationship']) ?: 'Parent']);
        if ($stmt->rowCount() > 0) {
            flash('success', 'Child linked to parent.');
        } else {
            flash('error', 'This child is already linked to that parent.');
        }
    } catch (Exception $ex) {
        flash('error', 'Could not link child: ' . $ex->getMessage());
    }
    redirect('/admin/parents.php');
}

if (isset($_GET['unlink'])) {
    try {
        $db->prepare("DELETE FROM parent_student WHERE id = ?")->execute([$_GET['unlink']]);
        flash('success', 'Child unlinked.');
    } catch (PDOException $ex) {
        flash('error', 'Could not unlink child: ' . $ex->getMessage());
    }
    redirect('/admin/parents.php');
}

if (isset($_GET['delete'])) {
    $stmt = $db->prepare("SELECT user_id FROM parents_guardians WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    if ($row = $stmt->fetch()) {
        try {
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$row['user_id']]);
            flash('success', 'Parent removed.');
        } catch (PDOException $ex) {
            flash('error', 'Cannot delete this parent: unexpected related records exist.');
        }
    }
    redirect('/admin/parents.php');
}

$parents = $db->query(
    "SELECT p.id, u.name, u.username, u.email, u.phone FROM parents_guardians p JOIN users u ON p.user_id = u.id ORDER BY u.name"
)->fetchAll();

$links = $db->query(
    "SELECT ps.id, ps.relationship, p.id AS parent_id, u.name AS parent_name,
            s.full_name AS student_name, c.name AS class_name
     FROM parent_student ps
     JOIN parents_guardians p ON ps.parent_id = p.id
     JOIN users u ON p.user_id = u.id
     JOIN students s ON ps.student_id = s.id
     JOIN classes c ON s.class_id = c.id
     ORDER BY u.name"
)->fetchAll();

$students = $db->query("SELECT id, full_name, admission_no FROM students ORDER BY full_name")->fetchAll();

$pageTitle = 'Manage Parents';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Parents</h3>
  <div>
    <button class="btn btn-outline-primary btn-sm me-2" data-bs-toggle="modal" data-bs-target="#linkModal">
      <i class="bi bi-link-45deg me-1"></i>Link Child
    </button>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addParentModal">
      <i class="bi bi-plus-lg me-1"></i>Add Parent
    </button>
  </div>
</div>

<div class="card stat-card p-3 mb-4">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Name</th><th>User ID</th><th>Email</th><th>Phone</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($parents as $p): ?>
        <tr>
          <td><?= e($p['name']) ?></td><td><code><?= e($p['username']) ?></code></td><td><?= e($p['email']) ?></td><td><?= e(formatTzPhone($p['phone'])) ?></td>
          <td><a href="?delete=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="bi bi-trash"></i></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$parents): ?><tr><td colspan="5" class="text-center text-muted">No parents yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<h5 class="mb-3">Parent → Child Links</h5>
<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Parent</th><th>Child</th><th>Class</th><th>Relationship</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($links as $l): ?>
        <tr>
          <td><?= e($l['parent_name']) ?></td><td><?= e($l['student_name']) ?></td>
          <td><?= e($l['class_name']) ?></td><td><?= e($l['relationship']) ?></td>
          <td><a href="?unlink=<?= $l['id'] ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="bi bi-x-lg"></i></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$links): ?><tr><td colspan="5" class="text-center text-muted">No links yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="addParentModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="create">
      <div class="modal-header"><h5 class="modal-title">Add Parent</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Full Name</label><input class="form-control" name="name" required></div>
        <div class="mb-2"><label class="form-label">Login User ID</label><input class="form-control" name="username" placeholder="e.g. P-1002" required></div>
        <div class="mb-2"><label class="form-label">Email (optional)</label><input type="email" class="form-control" name="email"></div>
        <div class="mb-2"><label class="form-label">Phone</label><input type="tel" class="form-control" name="phone" <?= tzPhoneAttrs() ?>></div>
        <div class="mb-2"><label class="form-label">Temporary Password</label><input class="form-control" name="password" placeholder="Leave blank to auto-generate a strong password"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Save Parent</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="linkModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="link">
      <div class="modal-header"><h5 class="modal-title">Link Child to Parent</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Parent</label>
          <select class="form-select" name="parent_id" required>
            <?php foreach ($parents as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2"><label class="form-label">Student</label>
          <select class="form-select" name="student_id" required>
            <?php foreach ($students as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['full_name']) ?> (<?= e($s['admission_no']) ?>)</option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2"><label class="form-label">Relationship</label><input class="form-control" name="relationship" placeholder="e.g. Father, Mother, Guardian"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Link</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
