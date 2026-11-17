<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_role') {
    $targetUserId = (int)$_POST['user_id'];
    $newRoleId = (int)$_POST['role_id'];

    if ($targetUserId === (int)$_SESSION['user_id']) {
        flash('error', 'You cannot change your own role.');
    } else {
        $db->prepare("UPDATE users SET role_id = ? WHERE id = ?")->execute([$newRoleId, $targetUserId]);
        flash('success', 'User role updated. Note: this does not automatically create/remove their teacher, parent, or student profile record.');
        logActivity($_SESSION['user_id'], 'change_role', "Changed role for user id $targetUserId");
    }
    redirect('/admin/roles.php');
}

$roles = $db->query("SELECT * FROM roles ORDER BY id")->fetchAll();
$users = $db->query("SELECT u.id, u.name, u.username, u.status, r.name AS role_name, r.id AS role_id FROM users u JOIN roles r ON u.role_id = r.id ORDER BY r.id, u.name")->fetchAll();

$pageTitle = 'Roles & Permissions';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-4">Roles &amp; Permissions</h3>

<div class="card stat-card p-3 mb-4">
  <h6 class="mb-3">System Roles</h6>
  <div class="row g-3">
    <div class="col-md-3"><div class="border rounded p-3"><strong>Admin</strong><p class="small text-muted mb-0">Full access: manage users, classes, subjects, results approval, documents, settings.</p></div></div>
    <div class="col-md-3"><div class="border rounded p-3"><strong>Teacher</strong><p class="small text-muted mb-0">Manage marks for assigned classes/subjects, upload result files, view performance.</p></div></div>
    <div class="col-md-3"><div class="border rounded p-3"><strong>Parent</strong><p class="small text-muted mb-0">View linked children's published results and download documents/result slips.</p></div></div>
    <div class="col-md-3"><div class="border rounded p-3"><strong>Student</strong><p class="small text-muted mb-0">View own published results only.</p></div></div>
  </div>
</div>

<div class="card stat-card p-3">
  <h6 class="mb-3">All Users</h6>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Name</th><th>User ID</th><th>Role</th><th>Status</th><th>Change Role</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td><td><code><?= e($u['username']) ?></code></td>
          <td><span class="badge bg-primary text-capitalize"><?= e($u['role_name']) ?></span></td>
          <td><span class="badge bg-<?= $u['status']=='active'?'success':'secondary' ?>"><?= e($u['status']) ?></span></td>
          <td>
            <form method="POST" class="d-flex gap-1">
              <input type="hidden" name="action" value="change_role">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <select name="role_id" class="form-select form-select-sm" style="width:auto;" <?= $u['id']==$_SESSION['user_id']?'disabled':'' ?>>
                <?php foreach ($roles as $r): ?>
                  <option value="<?= $r['id'] ?>" <?= $r['id']==$u['role_id']?'selected':'' ?>><?= e(ucfirst($r['name'])) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($u['id'] != $_SESSION['user_id']): ?>
                <button class="btn btn-sm btn-outline-primary" type="submit">Update</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
