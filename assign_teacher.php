<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        $stmt = $db->prepare(
            "INSERT IGNORE INTO teacher_subject_class (teacher_id, subject_id, class_id, academic_year_id, periods_per_week) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            (int)$_POST['teacher_id'], (int)$_POST['subject_id'],
            (int)$_POST['class_id'], (int)$_POST['academic_year_id'],
            max(1, min(20, (int)($_POST['periods_per_week'] ?: 3))),
        ]);
        if ($stmt->rowCount() > 0) {
            flash('success', 'Teacher assigned successfully.');
            logActivity($_SESSION['user_id'], 'assign_teacher', 'Assigned teacher to subject/class');
        } else {
            flash('error', 'This teacher is already assigned to that subject/class/year.');
        }
    } catch (Exception $ex) {
        flash('error', 'Could not assign teacher: ' . $ex->getMessage());
    }
    redirect('/admin/assign_teacher.php');
}
if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM teacher_subject_class WHERE id = ?")->execute([(int)$_GET['delete']]);
    flash('success', 'Assignment removed.');
    redirect('/admin/assign_teacher.php');
}

$assignments = $db->query(
    "SELECT tsc.id, u.name AS teacher_name, sub.name AS subject_name, c.name AS class_name, c.level AS class_level, ay.year_name, tsc.periods_per_week
     FROM teacher_subject_class tsc
     JOIN teachers t ON tsc.teacher_id = t.id
     JOIN users u ON t.user_id = u.id
     JOIN subjects sub ON tsc.subject_id = sub.id
     JOIN classes c ON tsc.class_id = c.id
     JOIN academic_years ay ON tsc.academic_year_id = ay.id
     ORDER BY ay.year_name DESC, c.name, sub.name"
)->fetchAll();

$teachers = $db->query("SELECT t.id, u.name FROM teachers t JOIN users u ON t.user_id = u.id ORDER BY u.name")->fetchAll();
$subjects = $db->query("SELECT * FROM subjects ORDER BY name")->fetchAll();
$classes = $db->query("SELECT * FROM classes ORDER BY name")->fetchAll();
$years = $db->query("SELECT * FROM academic_years ORDER BY year_name DESC")->fetchAll();

$pageTitle = 'Assign Teachers';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Assign Teachers to Subject / Class</h3>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg me-1"></i>New Assignment</button>
</div>
<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Teacher</th><th>Subject</th><th>Class</th><th>Academic Year</th><th>Periods/Week</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($assignments as $a): ?>
        <tr>
          <td><?= e($a['teacher_name']) ?></td><td><?= e($a['subject_name']) ?></td>
          <td><?= e($a['class_name']) ?> <span class="badge <?= $a['class_level']==='A-Level'?'bg-info text-dark':'bg-secondary' ?>"><?= e($a['class_level']) ?></span></td><td><?= e($a['year_name']) ?></td>
          <td><?= e($a['periods_per_week']) ?> <small class="text-muted"><?= $a['class_level']==='A-Level' ? '× 90 min' : '× 45 min' ?></small></td>
          <td><a href="?delete=<?= $a['id'] ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="bi bi-trash"></i></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$assignments): ?><tr><td colspan="6" class="text-center text-muted">No assignments yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <input type="hidden" name="action" value="create">
    <div class="modal-header"><h5 class="modal-title">New Assignment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Teacher</label>
        <select class="form-select" name="teacher_id" required>
          <?php foreach ($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="mb-2"><label class="form-label">Subject</label>
        <select class="form-select" name="subject_id" required>
          <?php foreach ($subjects as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="mb-2"><label class="form-label">Class</label>
        <select class="form-select" name="class_id" required>
          <?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (<?= e($c['level']) ?>)</option><?php endforeach; ?>
        </select></div>
      <div class="mb-2"><label class="form-label">Academic Year</label>
        <select class="form-select" name="academic_year_id" required>
          <?php foreach ($years as $y): ?><option value="<?= $y['id'] ?>"><?= e($y['year_name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="mb-2"><label class="form-label">Periods per Week</label>
        <input type="number" class="form-control" name="periods_per_week" min="1" max="20" value="3">
        <small class="text-muted">Used by the Timetable "Auto-Generate" tool. O-Level classes: number of 45-minute periods per week. A-Level classes: number of 90-minute double sessions per week.</small></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
