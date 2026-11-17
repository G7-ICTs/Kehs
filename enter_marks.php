<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['teacher']);
$db = getDB();
$teacherId = currentTeacherId();

$examId = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
$stmt = $db->prepare(
    "SELECT e.*, c.name AS class_name, sub.name AS subject_name, t.name AS term_name
     FROM exams e JOIN classes c ON e.class_id = c.id JOIN subjects sub ON e.subject_id = sub.id
     JOIN terms t ON e.term_id = t.id
     WHERE e.id = ? AND e.teacher_id = ?"
);
$stmt->execute([$examId, $teacherId]);
$exam = $stmt->fetch();

if (!$exam) {
    flash('error', 'Exam not found or you do not have access to it.');
    redirect('/teacher/select_exam.php');
}

$locked = in_array($exam['status'], ['approved', 'published'], true);

// -------- Save marks --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_marks') {
    if ($locked) {
        flash('error', 'This exam has been approved/published and can no longer be edited. Contact the admin.');
        redirect('/teacher/enter_marks.php?exam_id=' . $examId);
    }
    $ca = $_POST['ca'] ?? [];
    $examMarks = $_POST['exam_marks'] ?? [];

    $upsert = $db->prepare(
        "INSERT INTO results (exam_id, student_id, ca_marks, exam_marks, grade, entered_by)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE ca_marks = VALUES(ca_marks), exam_marks = VALUES(exam_marks),
                                  grade = VALUES(grade), entered_by = VALUES(entered_by)"
    );

    $maxCa = (float)$exam['max_ca'];
    $maxExam = (float)$exam['max_exam'];
    $outOfRange = false;

    foreach ($ca as $studentId => $caVal) {
        $studentId = (int)$studentId;
        $caVal = $caVal === '' ? null : (float)$caVal;
        $examVal = isset($examMarks[$studentId]) && $examMarks[$studentId] !== '' ? (float)$examMarks[$studentId] : null;

        // Server-side validation: the HTML "max" attribute is client-side only
        // and can be bypassed, so re-check bounds before saving.
        if ($caVal !== null && ($caVal < 0 || $caVal > $maxCa)) {
            $caVal = max(0, min($caVal, $maxCa));
            $outOfRange = true;
        }
        if ($examVal !== null && ($examVal < 0 || $examVal > $maxExam)) {
            $examVal = max(0, min($examVal, $maxExam));
            $outOfRange = true;
        }

        $total = (float)($caVal ?? 0) + (float)($examVal ?? 0);
        $grade = ($caVal === null && $examVal === null) ? null : computeGrade($total);
        $upsert->execute([$examId, $studentId, $caVal, $examVal, $grade, $_SESSION['user_id']]);
    }

    if ($outOfRange) {
        flash('error', 'Some marks exceeded the maximum allowed and were automatically capped. Please review the table below.');
    } else {
        flash('success', 'Marks saved successfully.');
    }
    logActivity($_SESSION['user_id'], 'enter_marks', "Entered marks for exam id $examId");
    redirect('/teacher/enter_marks.php?exam_id=' . $examId);
}

// -------- Submit for approval --------
if (isset($_GET['submit_approval'])) {
    $db->prepare("UPDATE exams SET status = 'submitted' WHERE id = ? AND teacher_id = ?")->execute([$examId, $teacherId]);
    flash('success', 'Results submitted for admin approval.');
    logActivity($_SESSION['user_id'], 'submit_results', "Submitted exam id $examId for approval");
    redirect('/teacher/enter_marks.php?exam_id=' . $examId);
}

$students = $db->prepare(
    "SELECT s.id, s.admission_no, s.full_name,
            r.ca_marks, r.exam_marks, r.total, r.grade
     FROM students s
     LEFT JOIN results r ON r.student_id = s.id AND r.exam_id = ?
     WHERE s.class_id = ? AND s.status = 'active'
     ORDER BY s.full_name"
);
$students->execute([$examId, $exam['class_id']]);
$students = $students->fetchAll();

$pageTitle = 'Enter Marks';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h3 class="mb-0"><?= e($exam['subject_name']) ?> — <?= e($exam['class_name']) ?></h3>
    <small class="text-muted"><?= e($exam['name']) ?> &middot; <?= e($exam['term_name']) ?>
      &middot; <span class="badge badge-status-<?= e($exam['status']) ?>"><?= e(ucfirst($exam['status'])) ?></span>
    </small>
  </div>
  <div>
    <?php if (!$locked && $exam['status'] !== 'submitted'): ?>
      <a href="?exam_id=<?= $examId ?>&submit_approval=1" class="btn btn-outline-success btn-sm"
         onclick="return confirm('Submit these results for admin approval? You will not be able to edit marks after admin approval.');">
        <i class="bi bi-send-check me-1"></i>Submit for Approval
      </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($locked): ?>
  <div class="alert alert-info">These results are <?= e($exam['status']) ?> and locked from editing.</div>
<?php elseif ($exam['status'] === 'submitted'): ?>
  <div class="alert alert-warning">Results submitted — awaiting admin approval. You can still edit until approved.</div>
<?php endif; ?>

<?php if (!$locked): ?>
<div class="card stat-card p-3 mb-3">
  <div class="row g-2 align-items-center">
    <div class="col-md-6">
      <label class="form-label small text-muted mb-1">Choose which students to enter/update marks for</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" id="studentSearch" class="form-control" placeholder="Search by name or admission no. to find a student…">
      </div>
    </div>
    <div class="col-md-6 text-md-end">
      <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllBtn">
        <i class="bi bi-check-all me-1"></i>Select All</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="selectNoneBtn">
        <i class="bi bi-square me-1"></i>Select None</button>
    </div>
  </div>
  <small class="text-muted mt-2 mb-0">Only <strong>checked</strong> students' mark fields are editable and saved.
    Uncheck a student to skip them this round (e.g. absent, already finalized, or you're only updating a few
    students) without touching their existing marks.</small>
</div>
<?php endif; ?>

<form method="POST">
  <input type="hidden" name="form_action" value="save_marks">
  <input type="hidden" name="exam_id" value="<?= $examId ?>">
  <div class="card stat-card p-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle" id="marksTable">
        <thead>
          <tr>
            <th style="width:40px;"><?php if (!$locked): ?><input type="checkbox" id="headerCheckAll" checked><?php endif; ?></th>
            <th>Admission No.</th><th>Student</th>
            <th style="width:120px;">CA (max <?= e($exam['max_ca']) ?>)</th>
            <th style="width:120px;">Exam (max <?= e($exam['max_exam']) ?>)</th>
            <th>Total</th><th>Grade</th><th>Remark</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($students as $s): ?>
          <tr class="student-row" data-search="<?= e(strtolower($s['full_name'] . ' ' . $s['admission_no'])) ?>">
            <td>
              <?php if (!$locked): ?>
                <input type="checkbox" class="include-check" checked
                       data-target-row="row-<?= $s['id'] ?>">
              <?php endif; ?>
            </td>
            <td><?= e($s['admission_no']) ?></td>
            <td><?= e($s['full_name']) ?></td>
            <td><input type="number" step="0.1" min="0" max="<?= e($exam['max_ca']) ?>" class="form-control form-control-sm ca-input"
                       id="row-<?= $s['id'] ?>-ca"
                       name="ca[<?= $s['id'] ?>]" value="<?= e($s['ca_marks']) ?>" <?= $locked?'disabled':'' ?>></td>
            <td><input type="number" step="0.1" min="0" max="<?= e($exam['max_exam']) ?>" class="form-control form-control-sm exam-input"
                       id="row-<?= $s['id'] ?>-exam"
                       name="exam_marks[<?= $s['id'] ?>]" value="<?= e($s['exam_marks']) ?>" <?= $locked?'disabled':'' ?>></td>
            <td class="total-cell"><?= $s['total'] !== null ? e($s['total']) : '-' ?></td>
            <td class="grade-<?= e($s['grade']) ?>"><?= e($s['grade']) ?></td>
            <td class="text-muted small"><?= e(remarkForGrade($s['grade'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$students): ?><tr><td colspan="8" class="text-center text-muted">No students in this class.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if (!$locked): ?>
      <button type="submit" class="btn btn-primary mt-2"><i class="bi bi-save me-1"></i>Save Marks for Checked Students</button>
    <?php endif; ?>
  </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
  function setRowEnabled(checkbox) {
    var caInput = document.getElementById(checkbox.dataset.targetRow + '-ca');
    var examInput = document.getElementById(checkbox.dataset.targetRow + '-exam');
    [caInput, examInput].forEach(function (input) {
      if (!input) return;
      input.disabled = !checkbox.checked;
      input.closest('tr').classList.toggle('table-secondary', !checkbox.checked);
    });
  }

  document.querySelectorAll('.include-check').forEach(function (cb) {
    setRowEnabled(cb); // apply initial state
    cb.addEventListener('change', function () { setRowEnabled(cb); });
  });

  var headerCheckAll = document.getElementById('headerCheckAll');
  if (headerCheckAll) {
    headerCheckAll.addEventListener('change', function () {
      document.querySelectorAll('.include-check').forEach(function (cb) {
        cb.checked = headerCheckAll.checked;
        setRowEnabled(cb);
      });
    });
  }

  var selectAllBtn = document.getElementById('selectAllBtn');
  var selectNoneBtn = document.getElementById('selectNoneBtn');
  if (selectAllBtn) selectAllBtn.addEventListener('click', function () {
    document.querySelectorAll('.include-check').forEach(function (cb) { cb.checked = true; setRowEnabled(cb); });
    if (headerCheckAll) headerCheckAll.checked = true;
  });
  if (selectNoneBtn) selectNoneBtn.addEventListener('click', function () {
    document.querySelectorAll('.include-check').forEach(function (cb) { cb.checked = false; setRowEnabled(cb); });
    if (headerCheckAll) headerCheckAll.checked = false;
  });

  var searchInput = document.getElementById('studentSearch');
  if (searchInput) {
    searchInput.addEventListener('input', function () {
      var q = searchInput.value.trim().toLowerCase();
      document.querySelectorAll('.student-row').forEach(function (row) {
        row.style.display = (!q || row.dataset.search.indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
