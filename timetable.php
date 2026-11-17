<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['teacher']);
$db = getDB();
$teacherId = currentTeacherId();

$entries = $db->prepare(
    "SELECT tt.*, c.name AS class_name, c.level AS class_level, sub.name AS subject_name
     FROM timetable tt JOIN classes c ON tt.class_id=c.id JOIN subjects sub ON tt.subject_id=sub.id
     WHERE tt.teacher_id = ?
     ORDER BY FIELD(tt.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), tt.start_time"
);
$entries->execute([$teacherId]);
$entries = $entries->fetchAll();

$comboMap = subjectCombinationCodes();
$byDay = [];
foreach ($entries as $e) { $byDay[$e['day_of_week']][] = $e; }

$pageTitle = 'My Timetable';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-4">My Teaching Timetable</h3>
<div class="row g-3">
  <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $day): ?>
    <div class="col-md-4">
      <div class="card stat-card p-3 h-100">
        <h6 class="mb-3"><?= $day ?></h6>
        <?php if (!empty($byDay[$day])): foreach ($byDay[$day] as $e): ?>
          <div class="border rounded p-2 mb-2">
            <div class="fw-semibold"><?= e($e['subject_name']) ?> — <?= e($e['class_name']) ?>
              <span class="badge <?= $e['class_level']==='A-Level'?'bg-info text-dark':'bg-secondary' ?> fw-normal"><?= e($e['class_level']) ?></span>
              <?php if ($e['class_level']==='A-Level' && !empty($comboMap[(int)$e['subject_id']])): ?>
                <span class="text-muted small fw-normal">(<?= e(implode(', ', $comboMap[(int)$e['subject_id']])) ?>)</span>
              <?php endif; ?></div>
            <div class="text-muted small"><?= e(substr($e['start_time'],0,5)) ?> - <?= e(substr($e['end_time'],0,5)) ?>
              <?php if ($e['room']): ?> &middot; <?= e($e['room']) ?><?php endif; ?></div>
          </div>
        <?php endforeach; else: ?>
          <p class="text-muted small mb-0">No classes.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
