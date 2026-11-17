<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['teacher']);
$db = getDB();
$teacherId = currentTeacherId();

// Only classes/subjects this teacher is assigned to
$assignments = $db->prepare(
    "SELECT DISTINCT tsc.class_id, c.name AS class_name, tsc.subject_id, sub.name AS subject_name, tsc.academic_year_id
     FROM teacher_subject_class tsc
     JOIN classes c ON tsc.class_id = c.id
     JOIN subjects sub ON tsc.subject_id = sub.id
     WHERE tsc.teacher_id = ?"
);
$assignments->execute([$teacherId]);
$assignments = $assignments->fetchAll();

$terms = $db->query(
    "SELECT t.*, ay.year_name FROM terms t JOIN academic_years ay ON t.academic_year_id = ay.id ORDER BY ay.year_name DESC"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $classId = (int)$_POST['class_id'];
    $subjectId = (int)$_POST['subject_id'];
    $termId = (int)$_POST['term_id'];
    $examName = trim($_POST['exam_name']);
    $maxCa = (float)$_POST['max_ca'];
    $maxExam = (float)$_POST['max_exam'];

    // Resolve the academic year from the TERM the teacher picked, not an
    // arbitrary assignment row - a teacher can be assigned to the same
    // class/subject across several years, so grabbing "any" row here could
    // tag the new exam with the wrong academic year.
    $termLookup = $db->prepare("SELECT academic_year_id FROM terms WHERE id = ?");
    $termLookup->execute([$termId]);
    $termRow = $termLookup->fetch();

    if (!$termRow) {
        flash('error', 'Invalid term selected.');
        redirect('/teacher/select_exam.php');
    }
    $academicYearId = $termRow['academic_year_id'];

    // Verify the teacher is actually assigned to this class/subject for that specific academic year
    $check = $db->prepare(
        "SELECT id FROM teacher_subject_class WHERE teacher_id=? AND class_id=? AND subject_id=? AND academic_year_id=? LIMIT 1"
    );
    $check->execute([$teacherId, $classId, $subjectId, $academicYearId]);

    if (!$check->fetch()) {
        flash('error', 'You are not assigned to this class/subject for the selected term\'s academic year.');
        redirect('/teacher/select_exam.php');
    }

    // Find existing exam or create a new one (draft)
    $find = $db->prepare("SELECT id FROM exams WHERE class_id=? AND subject_id=? AND term_id=? AND teacher_id=? LIMIT 1");
    $find->execute([$classId, $subjectId, $termId, $teacherId]);
    $existing = $find->fetch();

    if ($existing) {
        $examId = $existing['id'];
    } else {
        $stmt = $db->prepare(
            "INSERT INTO exams (name, class_id, subject_id, academic_year_id, term_id, teacher_id, max_ca, max_exam, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft')"
        );
        $stmt->execute([$examName ?: 'Exam', $classId, $subjectId, $academicYearId, $termId, $teacherId, $maxCa, $maxExam]);
        $examId = $db->lastInsertId();
    }
    redirect('/teacher/enter_marks.php?exam_id=' . $examId);
}

$preClass = $_GET['class_id'] ?? '';
$preSubject = $_GET['subject_id'] ?? '';

$pageTitle = 'Select Class / Subject / Exam';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-4">Select Class &rarr; Subject &rarr; Examination</h3>

<div class="card stat-card p-4" style="max-width:600px;">
  <form method="POST">
    <div class="mb-3">
      <label class="form-label">Class &amp; Subject</label>
      <select class="form-select" id="classSubject" required>
        <option value="">-- Select --</option>
        <?php foreach ($assignments as $a): ?>
          <option value="<?= $a['class_id'] ?>|<?= $a['subject_id'] ?>"
            <?= ($preClass==$a['class_id'] && $preSubject==$a['subject_id'])?'selected':'' ?>>
            <?= e($a['subject_name']) ?> — <?= e($a['class_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="class_id" id="class_id" value="<?= e($preClass) ?>">
      <input type="hidden" name="subject_id" id="subject_id" value="<?= e($preSubject) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">Term</label>
      <select class="form-select" name="term_id" required>
        <?php foreach ($terms as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?> (<?= e($t['year_name']) ?>)</option><?php endforeach; ?>
      </select>
    </div>
    <div class="mb-3">
      <label class="form-label">Examination Name</label>
      <input class="form-control" name="exam_name" placeholder="e.g. Mid-Term 2026" required>
    </div>
    <div class="row">
      <div class="col-6 mb-3">
        <label class="form-label">Max CA Marks</label>
        <input type="number" step="0.1" class="form-control" name="max_ca" value="30" required>
      </div>
      <div class="col-6 mb-3">
        <label class="form-label">Max Exam Marks</label>
        <input type="number" step="0.1" class="form-control" name="max_exam" value="70" required>
      </div>
    </div>
    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-arrow-right-circle me-1"></i>Continue to Enter Marks</button>
  </form>
</div>

<script>
document.getElementById('classSubject').addEventListener('change', function () {
  var parts = this.value.split('|');
  document.getElementById('class_id').value = parts[0] || '';
  document.getElementById('subject_id').value = parts[1] || '';
});
// initialize on load if pre-selected
window.addEventListener('DOMContentLoaded', function () {
  document.getElementById('classSubject').dispatchEvent(new Event('change'));
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
