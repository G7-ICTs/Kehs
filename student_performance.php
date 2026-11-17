<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['teacher']);
$db = getDB();
$teacherId = currentTeacherId();

// Students belonging to classes this teacher teaches
$students = $db->prepare(
    "SELECT DISTINCT s.id, s.full_name, s.admission_no, c.name AS class_name
     FROM students s
     JOIN classes c ON s.class_id = c.id
     JOIN teacher_subject_class tsc ON tsc.class_id = s.class_id
     WHERE tsc.teacher_id = ? AND s.status='active'
     ORDER BY s.full_name"
);
$students->execute([$teacherId]);
$students = $students->fetchAll();

// Resolve the chosen student either by admission number (from the search
// box below) or by student_id (kept for backward-compatible links).
$searchInput = trim($_GET['admission_no'] ?? '');
$studentId = (int)($_GET['student_id'] ?? 0);
$selected = null;

if ($searchInput !== '') {
    // Accept either "S4652.0001 - Full Name" (picked from the datalist) or just the admission number.
    $admissionNo = trim(explode(' - ', $searchInput)[0]);
    foreach ($students as $s) {
        if (strcasecmp($s['admission_no'], $admissionNo) === 0) { $selected = $s; break; }
    }
    if (!$selected) {
        flash('error', "No student with admission number \"$admissionNo\" found in your classes.");
    }
} elseif ($studentId) {
    foreach ($students as $s) {
        if ((int)$s['id'] === $studentId) { $selected = $s; break; }
    }
} elseif ($students) {
    $selected = $students[0];
}

$history = [];
if ($selected) {
    $stmt = $db->prepare(
        "SELECT e.name AS exam_name, sub.name AS subject_name, t.name AS term_name,
                r.ca_marks, r.exam_marks, r.total, r.grade, e.status
         FROM results r
         JOIN exams e ON r.exam_id = e.id
         JOIN subjects sub ON e.subject_id = sub.id
         JOIN terms t ON e.term_id = t.id
         WHERE r.student_id = ? AND e.teacher_id = ?
         ORDER BY e.created_at DESC"
    );
    $stmt->execute([$selected['id'], $teacherId]);
    $history = $stmt->fetchAll();
}

$pageTitle = 'Student Performance';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-4">Student Performance</h3>

<form class="row g-2 mb-4 align-items-end" method="GET">
  <div class="col-md-5">
    <label class="form-label small text-muted mb-1">Search by admission number or name</label>
    <input type="text" class="form-control" name="admission_no" list="studentDatalist"
           placeholder="e.g. S4652.0001 or student name" value="<?= e($searchInput) ?>">
    <datalist id="studentDatalist">
      <?php foreach ($students as $s): ?>
        <option value="<?= e($s['admission_no']) ?> - <?= e($s['full_name']) ?>">
      <?php endforeach; ?>
    </datalist>
  </div>
  <div class="col-auto">
    <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Find</button>
  </div>
  <?php if ($selected): ?>
    <div class="col-auto">
      <span class="badge bg-secondary">Showing: <?= e($selected['full_name']) ?> (<?= e($selected['admission_no']) ?>, <?= e($selected['class_name']) ?>)</span>
    </div>
  <?php endif; ?>
</form>

<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Subject</th><th>Exam</th><th>Term</th><th>CA</th><th>Exam</th><th>Total</th><th>Grade</th><th>Remark</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr>
          <td><?= e($h['subject_name']) ?></td><td><?= e($h['exam_name']) ?></td><td><?= e($h['term_name']) ?></td>
          <td><?= e($h['ca_marks']) ?></td><td><?= e($h['exam_marks']) ?></td><td><?= e($h['total']) ?></td>
          <td class="grade-<?= e($h['grade']) ?>"><?= e($h['grade']) ?></td>
          <td class="text-muted small"><?= e(remarkForGrade($h['grade'])) ?></td>
          <td><span class="badge badge-status-<?= e($h['status']) ?>"><?= e(ucfirst($h['status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$history): ?><tr><td colspan="9" class="text-center text-muted"><?= $selected ? 'No results recorded yet for this student.' : 'Search for a student above to view their results.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
