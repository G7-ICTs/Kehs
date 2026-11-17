<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['teacher']);
$db = getDB();
$teacherId = currentTeacherId();

$exams = $db->prepare(
    "SELECT e.id, e.name, c.name AS class_name, sub.name AS subject_name, t.name AS term_name
     FROM exams e JOIN classes c ON e.class_id = c.id JOIN subjects sub ON e.subject_id = sub.id
     JOIN terms t ON e.term_id = t.id
     WHERE e.teacher_id = ? ORDER BY e.created_at DESC"
);
$exams->execute([$teacherId]);
$exams = $exams->fetchAll();

$examId = (int)($_GET['exam_id'] ?? ($exams[0]['id'] ?? 0));
$results = [];
$gradeCounts = ['A'=>0,'B'=>0,'C'=>0,'D'=>0,'F'=>0];
$avg = 0; $highest = 0; $lowest = null;

if ($examId) {
    $check = $db->prepare("SELECT id FROM exams WHERE id=? AND teacher_id=?");
    $check->execute([$examId, $teacherId]);
    if ($check->fetch()) {
        $stmt = $db->prepare(
            "SELECT s.full_name, s.admission_no, r.ca_marks, r.exam_marks, r.total, r.grade
             FROM results r JOIN students s ON r.student_id = s.id
             WHERE r.exam_id = ? ORDER BY r.total DESC"
        );
        $stmt->execute([$examId]);
        $results = $stmt->fetchAll();

        $totals = array_filter(array_column($results, 'total'), fn($v) => $v !== null);
        if ($totals) {
            $avg = round(array_sum($totals) / count($totals), 1);
            $highest = max($totals);
            $lowest = min($totals);
        }
        foreach ($results as $r) {
            if (isset($gradeCounts[$r['grade']])) $gradeCounts[$r['grade']]++;
        }
    }
}

$pageTitle = 'Class Performance';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-4">Class Performance</h3>

<form class="row g-2 mb-4" method="GET">
  <div class="col-auto">
    <select class="form-select" name="exam_id" onchange="this.form.submit()">
      <?php foreach ($exams as $ex): ?>
        <option value="<?= $ex['id'] ?>" <?= $examId==$ex['id']?'selected':'' ?>>
          <?= e($ex['subject_name']) ?> — <?= e($ex['class_name']) ?> — <?= e($ex['name']) ?> (<?= e($ex['term_name']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if ($results): ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="card stat-card p-3 text-center"><h4><?= $avg ?></h4><small class="text-muted">Class Average</small></div></div>
  <div class="col-6 col-md-3"><div class="card stat-card p-3 text-center"><h4><?= $highest ?></h4><small class="text-muted">Highest Score</small></div></div>
  <div class="col-6 col-md-3"><div class="card stat-card p-3 text-center"><h4><?= $lowest ?></h4><small class="text-muted">Lowest Score</small></div></div>
  <div class="col-6 col-md-3"><div class="card stat-card p-3 text-center"><h4><?= count($results) ?></h4><small class="text-muted">Students Graded</small></div></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card stat-card p-3">
      <h6 class="mb-2">Grade Distribution</h6>
      <div class="d-flex gap-4">
        <?php foreach ($gradeCounts as $g => $count): ?>
          <div class="text-center"><div class="grade-<?= $g ?> fs-4"><?= $g ?></div><div><?= $count ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Rank</th><th>Admission No.</th><th>Student</th><th>CA</th><th>Exam</th><th>Total</th><th>Grade</th><th>Remark</th></tr></thead>
      <tbody>
      <?php $rank = 1; foreach ($results as $r): ?>
        <tr>
          <td><?= $rank++ ?></td><td><?= e($r['admission_no']) ?></td><td><?= e($r['full_name']) ?></td>
          <td><?= e($r['ca_marks']) ?></td><td><?= e($r['exam_marks']) ?></td><td><?= e($r['total']) ?></td>
          <td class="grade-<?= e($r['grade']) ?>"><?= e($r['grade']) ?></td>
          <td class="text-muted small"><?= e(remarkForGrade($r['grade'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
  <p class="text-muted">No results entered for this exam yet.</p>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
