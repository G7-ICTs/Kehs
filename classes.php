<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        $level = ($_POST['level'] ?? '') === 'A-Level' ? 'A-Level' : 'O-Level';
        $db->prepare("INSERT INTO classes (name, level) VALUES (?, ?)")->execute([trim($_POST['name']), $level]);
        flash('success', 'Class added.');
    } catch (Exception $ex) { flash('error', 'Could not add class: ' . $ex->getMessage()); }
    redirect('/admin/classes.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_level') {
    $level = ($_POST['level'] ?? '') === 'A-Level' ? 'A-Level' : 'O-Level';
    $classId = (int)$_POST['class_id'];
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE classes SET level = ? WHERE id = ?")->execute([$level, $classId]);
        if ($level === 'O-Level') {
            // Combinations never apply to secondary students - clear any left over.
            $db->prepare("UPDATE students SET combination_id = NULL WHERE class_id = ?")->execute([$classId]);
        }
        $db->commit();
        flash('success', "Class level set to $level.");
    } catch (Exception $ex) {
        if ($db->inTransaction()) { $db->rollBack(); }
        flash('error', 'Could not change level: ' . $ex->getMessage());
    }
    redirect('/admin/classes.php');
}
if (isset($_GET['delete'])) {
    try {
        $db->prepare("DELETE FROM classes WHERE id = ?")->execute([(int)$_GET['delete']]);
        flash('success', 'Class removed.');
    } catch (PDOException $ex) {
        flash('error', 'Cannot delete this class: it still has students, exams, or teacher assignments linked to it. Remove those first.');
    }
    redirect('/admin/classes.php');
}

$classes = $db->query(
    "SELECT c.*, (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS student_count
     FROM classes c ORDER BY c.name"
)->fetchAll();

$pageTitle = 'Manage Classes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Classes</h3>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg me-1"></i>Add Class</button>
</div>
<p class="text-muted small">O-Level (Form I-IV, secondary) students all take the same subjects - combinations don't apply to them.
  A-Level (Form V-VI, advanced) students belong to a subject combination (PCB, PCM, CBG, EGM, HGK, HGL, HKL).</p>
<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Class Name</th><th>Level</th><th>Students</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($classes as $c): ?>
        <tr><td><?= e($c['name']) ?></td>
        <td>
          <form method="POST" class="d-flex align-items-center gap-2">
            <input type="hidden" name="action" value="set_level">
            <input type="hidden" name="class_id" value="<?= (int)$c['id'] ?>">
            <select class="form-select form-select-sm" name="level" style="width:auto" onchange="this.form.submit()">
              <option value="O-Level" <?= $c['level']==='O-Level'?'selected':'' ?>>O-Level (secondary)</option>
              <option value="A-Level" <?= $c['level']==='A-Level'?'selected':'' ?>>A-Level (advanced)</option>
            </select>
            <small class="text-muted"><?= $c['level']==='A-Level' ? 'has combinations' : 'no combinations' ?></small>
          </form>
        </td>
        <td><?= (int)$c['student_count'] ?></td>
        <td><a href="?delete=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="bi bi-trash"></i></a></td></tr>
      <?php endforeach; ?>
      <?php if (!$classes): ?><tr><td colspan="4" class="text-center text-muted">No classes yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <input type="hidden" name="action" value="create">
    <div class="modal-header"><h5 class="modal-title">Add Class</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Class Name</label><input class="form-control" name="name" placeholder="e.g. Form I" required></div>
      <div class="mb-2"><label class="form-label">Level</label>
        <select class="form-select" name="level">
          <option value="O-Level">O-Level (secondary, Form I-IV) - no combinations</option>
          <option value="A-Level">A-Level (advanced, Form V-VI) - has combinations</option>
        </select></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
