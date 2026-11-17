<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['teacher']);
$db = getDB();
$teacherId = currentTeacherId();

$assignments = $db->prepare(
    "SELECT tsc.*, c.name AS class_name, sub.name AS subject_name, ay.year_name
     FROM teacher_subject_class tsc
     JOIN classes c ON tsc.class_id = c.id
     JOIN subjects sub ON tsc.subject_id = sub.id
     JOIN academic_years ay ON tsc.academic_year_id = ay.id
     WHERE tsc.teacher_id = ?
     ORDER BY ay.year_name DESC, c.name"
);
$assignments->execute([$teacherId]);
$assignments = $assignments->fetchAll();

$exams = $db->prepare(
    "SELECT e.*, c.name AS class_name, sub.name AS subject_name, t.name AS term_name
     FROM exams e JOIN classes c ON e.class_id = c.id JOIN subjects sub ON e.subject_id = sub.id
     JOIN terms t ON e.term_id = t.id
     WHERE e.teacher_id = ? ORDER BY e.created_at DESC LIMIT 6"
);
$exams->execute([$teacherId]);
$exams = $exams->fetchAll();

$pageTitle = 'Teacher Dashboard';
$bodyClass = 'dashboard-bg';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-3">Welcome, <?= e(currentUser()['name']) ?></h3>

<div class="card stat-card p-3 mb-4">
  <form method="GET" action="student_performance.php" class="row g-2 align-items-end">
    <div class="col-md-6">
      <label class="form-label small text-muted mb-1"><i class="bi bi-search me-1"></i>Quick Student Search</label>
      <input type="text" class="form-control" name="admission_no" placeholder="Search by admission number (e.g. <?= e(SCHOOL_CODE) ?>.0001) or name…">
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>View Performance</button>
    </div>
  </form>
</div>

<div class="card stat-card p-3 mb-4">
  <h6 class="mb-3">My Classes &amp; Subjects</h6>
  <div class="row g-3">
    <?php foreach ($assignments as $a): ?>
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="fw-semibold"><?= e($a['subject_name']) ?></div>
          <div class="text-muted small"><?= e($a['class_name']) ?> &middot; <?= e($a['year_name']) ?></div>
          <a href="select_exam.php?class_id=<?= $a['class_id'] ?>&subject_id=<?= $a['subject_id'] ?>" class="btn btn-sm btn-outline-primary mt-2">Enter Marks</a>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$assignments): ?><p class="text-muted mb-0">No classes assigned yet. Contact the administrator.</p><?php endif; ?>
  </div>
</div>

<div class="card stat-card p-3">
  <h6 class="mb-3">Recent Exams</h6>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead><tr><th>Exam</th><th>Class</th><th>Subject</th><th>Term</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($exams as $ex): ?>
        <tr>
          <td><?= e($ex['name']) ?></td><td><?= e($ex['class_name']) ?></td><td><?= e($ex['subject_name']) ?></td>
          <td><?= e($ex['term_name']) ?></td>
          <td><span class="badge badge-status-<?= e($ex['status']) ?>"><?= e(ucfirst($ex['status'])) ?></span></td>
          <td><a href="enter_marks.php?exam_id=<?= $ex['id'] ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$exams): ?><tr><td colspan="6" class="text-center text-muted">No exams yet. Start by selecting Class → Subject → Exam.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
