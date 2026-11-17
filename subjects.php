<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        $db->prepare("INSERT INTO subjects (name, code) VALUES (?, ?)")
           ->execute([trim($_POST['name']), trim($_POST['code'])]);
        flash('success', 'Subject added.');
    } catch (Exception $ex) { flash('error', 'Could not add subject: ' . $ex->getMessage()); }
    redirect('/admin/subjects.php');
}
if (isset($_GET['delete'])) {
    try {
        $db->prepare("DELETE FROM subjects WHERE id = ?")->execute([$_GET['delete']]);
        flash('success', 'Subject removed.');
    } catch (PDOException $ex) {
        flash('error', 'Cannot delete this subject: it still has exams or teacher assignments linked to it. Remove those first.');
    }
    redirect('/admin/subjects.php');
}

$subjects = $db->query("SELECT * FROM subjects ORDER BY name")->fetchAll();

$pageTitle = 'Manage Subjects';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Subjects</h3>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg me-1"></i>Add Subject</button>
</div>
<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Subject Name</th><th>Code</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($subjects as $s): ?>
        <tr><td><?= e($s['name']) ?></td><td><?= e($s['code']) ?></td>
        <td><a href="?delete=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="bi bi-trash"></i></a></td></tr>
      <?php endforeach; ?>
      <?php if (!$subjects): ?><tr><td colspan="3" class="text-center text-muted">No subjects yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <input type="hidden" name="action" value="create">
    <div class="modal-header"><h5 class="modal-title">Add Subject</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Subject Name</label><input class="form-control" name="name" required></div>
      <div class="mb-2"><label class="form-label">Code</label><input class="form-control" name="code" placeholder="e.g. MATH"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
