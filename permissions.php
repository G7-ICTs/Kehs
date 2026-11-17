<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_matrix') {
    // Rebuild role_permissions for the roles that were shown on the form
    // (admin's own grants are never editable here - see the disabled row below).
    $roles = $db->query("SELECT id, name FROM roles WHERE name != 'admin'")->fetchAll();
    $db->beginTransaction();
    foreach ($roles as $role) {
        $db->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$role['id']]);
        $checked = $_POST['perm'][$role['id']] ?? [];
        if ($checked) {
            $stmt = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
            foreach ($checked as $permId) {
                $stmt->execute([$role['id'], (int)$permId]);
            }
        }
    }
    $db->commit();
    flash('success', 'Permissions updated. Affected users will see the change next time they log in.');
    logActivity($_SESSION['user_id'], 'update_permissions', 'Updated RBAC permission matrix');
    redirect('/admin/permissions.php');
}

$roles = $db->query("SELECT * FROM roles WHERE name != 'admin' ORDER BY id")->fetchAll();
$permissions = $db->query("SELECT * FROM permissions ORDER BY category, label")->fetchAll();
$grants = $db->query("SELECT role_id, permission_id FROM role_permissions")->fetchAll();

$grantMap = [];
foreach ($grants as $g) { $grantMap[$g['role_id']][$g['permission_id']] = true; }

$byCategory = [];
foreach ($permissions as $p) { $byCategory[$p['category']][] = $p; }

$pageTitle = 'RBAC Permissions';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-2">Manage Permissions (RBAC)</h3>
<p class="text-muted">Admin always has full access. Toggle which permissions Teacher, Parent, and Student roles have.</p>

<form method="POST">
  <input type="hidden" name="action" value="save_matrix">
  <?php foreach ($byCategory as $category => $perms): ?>
    <div class="card stat-card p-3 mb-3">
      <h6 class="mb-3 text-capitalize"><?= e($category) ?> Permissions</h6>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>Permission</th><?php foreach ($roles as $r): ?><th class="text-center text-capitalize"><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach ($perms as $p): ?>
            <tr>
              <td><?= e($p['label']) ?></td>
              <?php foreach ($roles as $r): ?>
                <td class="text-center">
                  <input type="checkbox" class="form-check-input" name="perm[<?= $r['id'] ?>][]" value="<?= $p['id'] ?>"
                    <?= isset($grantMap[$r['id']][$p['id']]) ? 'checked' : '' ?>>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
  <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Permission Matrix</button>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
