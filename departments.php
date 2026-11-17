<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        $db->prepare("INSERT INTO departments (name, description) VALUES (?, ?)")
           ->execute([trim($_POST['name']), trim($_POST['description'])]);
        flash('success', 'Department added.');
    } catch (Exception $ex) { flash('error', 'Could not add department: ' . $ex->getMessage()); }
    redirect('/admin/departments.php');
}
if (isset($_GET['delete'])) {
    try {
        $db->prepare("DELETE FROM departments WHERE id = ?")->execute([$_GET['delete']]);
        flash('success', 'Department removed.');
    } catch (PDOException $ex) {
        flash('error', 'Could not delete department: unexpected related records exist.');
    }
    redirect('/admin/departments.php');
}

$departments = $db->query(
    "SELECT d.*, (SELECT COUNT(*) FROM subjects s WHERE s.department_id=d.id) AS subject_count,
            (SELECT COUNT(*) FROM teachers t WHERE t.department_id=d.id) AS teacher_count
     FROM departments d ORDER BY d.name"
)->fetchAll();

$pageTitle = 'Departments';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Departments</h3>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg me-1"></i>Add Department</button>
</div>
<p class="text-muted">All departments report to the Head Teacher. Default KEHS departments: Arts and Social Science, Mathematics, Science, TEHAMA na Ubunifu, Academic, Books and Store (Library), Business.</p>
<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Name</th><th>Description</th><th>Subjects</th><th>Teachers</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($departments as $d): ?>
        <tr>
          <td><?= e($d['name']) ?></td><td><?= e($d['description']) ?></td>
          <td><?= (int)$d['subject_count'] ?></td><td><?= (int)$d['teacher_count'] ?></td>
          <td><a href="?delete=<?= $d['id'] ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="bi bi-trash"></i></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$departments): ?><tr><td colspan="5" class="text-center text-muted">No departments yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <input type="hidden" name="action" value="create">
    <div class="modal-header"><h5 class="modal-title">Add Department</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
      <div class="mb-2"><label class="form-label">Description</label><input class="form-control" name="description"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
