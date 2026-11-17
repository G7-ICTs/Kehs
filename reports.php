<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

$passMark = (float)getSetting('grading_pass_mark', '40');

// Overall pass/fail on published results
$overall = $db->query(
    "SELECT
        SUM(CASE WHEN r.total >= $passMark THEN 1 ELSE 0 END) AS passed,
        SUM(CASE WHEN r.total < $passMark THEN 1 ELSE 0 END) AS failed,
        COUNT(*) AS total
     FROM results r JOIN exams e ON r.exam_id = e.id WHERE e.status = 'published'"
)->fetch();

// Average score per class
$byClass = $db->query(
    "SELECT c.name AS class_name, ROUND(AVG(r.total),1) AS avg_score, COUNT(*) AS entries
     FROM results r JOIN exams e ON r.exam_id = e.id JOIN classes c ON e.class_id = c.id
     WHERE e.status = 'published' GROUP BY c.id ORDER BY c.name"
)->fetchAll();

// Average score per subject
$bySubject = $db->query(
    "SELECT sub.name AS subject_name, ROUND(AVG(r.total),1) AS avg_score, COUNT(*) AS entries
     FROM results r JOIN exams e ON r.exam_id = e.id JOIN subjects sub ON e.subject_id = sub.id
     WHERE e.status = 'published' GROUP BY sub.id ORDER BY sub.name"
)->fetchAll();

// Grade distribution
$grades = $db->query(
    "SELECT r.grade, COUNT(*) AS count FROM results r JOIN exams e ON r.exam_id = e.id
     WHERE e.status='published' AND r.grade IS NOT NULL GROUP BY r.grade"
)->fetchAll();
$gradeMap = ['A'=>0,'B'=>0,'C'=>0,'D'=>0,'F'=>0];
foreach ($grades as $g) { if (isset($gradeMap[$g['grade']])) $gradeMap[$g['grade']] = (int)$g['count']; }

$pageTitle = 'Reports & Statistics';
include __DIR__ . '/../includes/header.php';

$gradeLabels = array_keys($gradeMap);
$gradeValues = array_values($gradeMap);
$classLabels = array_column($byClass, 'class_name');
$classValues = array_map('floatval', array_column($byClass, 'avg_score'));
$subjectLabels = array_column($bySubject, 'subject_name');
$subjectValues = array_map('floatval', array_column($bySubject, 'avg_score'));
?>
<script src="<?= e(vendorAsset('chartjs/chart.umd.min.js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js')) ?>"></script>
<h3 class="mb-4">Reports &amp; Statistics</h3>
<p class="text-muted">Based on published results only (pass mark: <?= e($passMark) ?>).</p>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="card stat-card p-3 text-center"><h4 class="text-success"><?= (int)($overall['passed'] ?? 0) ?></h4><small class="text-muted">Passed</small></div></div>
  <div class="col-6 col-md-3"><div class="card stat-card p-3 text-center"><h4 class="text-danger"><?= (int)($overall['failed'] ?? 0) ?></h4><small class="text-muted">Failed</small></div></div>
  <div class="col-6 col-md-3"><div class="card stat-card p-3 text-center"><h4><?= (int)($overall['total'] ?? 0) ?></h4><small class="text-muted">Total Entries</small></div></div>
  <div class="col-6 col-md-3"><div class="card stat-card p-3 text-center">
    <h4><?= $overall['total'] ? round(($overall['passed']/$overall['total'])*100,1) : 0 ?>%</h4><small class="text-muted">Pass Rate</small></div></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card stat-card p-3">
      <h6 class="mb-2">Pass / Fail</h6>
      <canvas id="passFailChart" height="220"></canvas>
    </div>
  </div>
  <div class="col-md-8">
    <div class="card stat-card p-3">
      <h6 class="mb-2">Grade Distribution (Published Results)</h6>
      <canvas id="gradeChart" height="220"></canvas>
      <div class="d-flex gap-4 mt-3 justify-content-center">
        <?php foreach ($gradeMap as $g => $count): ?>
          <div class="text-center"><div class="grade-<?= $g ?> fs-5"><?= $g ?></div><div class="small text-muted"><?= $count ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card stat-card p-3">
      <h6 class="mb-3">Average Score by Class</h6>
      <canvas id="classChart" height="220"></canvas>
      <table class="table table-sm mt-3"><thead><tr><th>Class</th><th>Average</th><th>Entries</th></tr></thead><tbody>
      <?php foreach ($byClass as $c): ?>
        <tr><td><?= e($c['class_name']) ?></td><td><?= e($c['avg_score']) ?></td><td><?= e($c['entries']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$byClass): ?><tr><td colspan="3" class="text-center text-muted">No published data yet.</td></tr><?php endif; ?>
      </tbody></table>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card stat-card p-3">
      <h6 class="mb-3">Average Score by Subject</h6>
      <canvas id="subjectChart" height="220"></canvas>
      <table class="table table-sm mt-3"><thead><tr><th>Subject</th><th>Average</th><th>Entries</th></tr></thead><tbody>
      <?php foreach ($bySubject as $s): ?>
        <tr><td><?= e($s['subject_name']) ?></td><td><?= e($s['avg_score']) ?></td><td><?= e($s['entries']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$bySubject): ?><tr><td colspan="3" class="text-center text-muted">No published data yet.</td></tr><?php endif; ?>
      </tbody></table>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof Chart === 'undefined') return; // CDN blocked/offline - tables above still work

  var passed = <?= (int)($overall['passed'] ?? 0) ?>;
  var failed = <?= (int)($overall['failed'] ?? 0) ?>;
  new Chart(document.getElementById('passFailChart'), {
    type: 'doughnut',
    data: { labels: ['Passed', 'Failed'], datasets: [{ data: [passed, failed], backgroundColor: ['#198754', '#dc3545'] }] },
    options: { plugins: { legend: { position: 'bottom' } } }
  });

  new Chart(document.getElementById('gradeChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($gradeLabels) ?>,
      datasets: [{
        label: 'Students',
        data: <?= json_encode($gradeValues) ?>,
        backgroundColor: ['#198754', '#0d6efd', '#fd7e14', '#ffc107', '#dc3545']
      }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
  });

  new Chart(document.getElementById('classChart'), {
    type: 'bar',
    data: { labels: <?= json_encode($classLabels) ?>, datasets: [{ label: 'Average Score', data: <?= json_encode($classValues) ?>, backgroundColor: '#2f80ed' }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, max: 100 } } }
  });

  new Chart(document.getElementById('subjectChart'), {
    type: 'bar',
    data: { labels: <?= json_encode($subjectLabels) ?>, datasets: [{ label: 'Average Score', data: <?= json_encode($subjectValues) ?>, backgroundColor: '#6f42c1' }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, max: 100 } } }
  });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
