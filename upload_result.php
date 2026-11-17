<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['teacher']);
$db = getDB();
$teacherId = currentTeacherId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $examId = (int)$_POST['exam_id'];
    // verify ownership
    $check = $db->prepare("SELECT id FROM exams WHERE id = ? AND teacher_id = ?");
    $check->execute([$examId, $teacherId]);
    if (!$check->fetch()) {
        flash('error', 'Invalid exam selection.');
        redirect('/teacher/upload_result.php');
    }
    $fileName = handleFileUpload($_FILES['result_file'] ?? [], UPLOAD_RESULTS_DIR, 'result');
    if ($fileName) {
        $stmt = $db->prepare("INSERT INTO result_files (exam_id, file_name, file_path, uploaded_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$examId, $fileName, 'uploads/results/' . $fileName, $_SESSION['user_id']]);
        flash('success', 'Result file uploaded successfully.');
        logActivity($_SESSION['user_id'], 'upload_result_file', "Uploaded result file for exam id $examId");
    }
    redirect('/teacher/upload_result.php');
}

$exams = $db->prepare(
    "SELECT e.id, e.name, c.name AS class_name, sub.name AS subject_name, t.name AS term_name
     FROM exams e JOIN classes c ON e.class_id = c.id JOIN subjects sub ON e.subject_id = sub.id
     JOIN terms t ON e.term_id = t.id
     WHERE e.teacher_id = ? ORDER BY e.created_at DESC"
);
$exams->execute([$teacherId]);
$exams = $exams->fetchAll();

$files = $db->prepare(
    "SELECT rf.*, e.name AS exam_name, c.name AS class_name, sub.name AS subject_name
     FROM result_files rf
     JOIN exams e ON rf.exam_id = e.id
     JOIN classes c ON e.class_id = c.id
     JOIN subjects sub ON e.subject_id = sub.id
     WHERE e.teacher_id = ? ORDER BY rf.uploaded_at DESC"
);
$files->execute([$teacherId]);
$files = $files->fetchAll();

$pageTitle = 'Upload Result File';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-4">Upload Result File</h3>

<div class="card stat-card p-4 mb-4" style="max-width:600px;">
  <form method="POST" enctype="multipart/form-data">
    <div class="mb-3">
      <label class="form-label">Examination</label>
      <select class="form-select" name="exam_id" required>
        <option value="">-- Select Exam --</option>
        <?php foreach ($exams as $ex): ?>
          <option value="<?= $ex['id'] ?>"><?= e($ex['subject_name']) ?> — <?= e($ex['class_name']) ?> — <?= e($ex['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="mb-3">
      <label class="form-label">Result File (PDF/DOC/DOCX, max 10MB)</label>
      <input type="file" class="form-control" name="result_file" accept=".pdf,.doc,.docx" required>
    </div>
    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-cloud-upload me-1"></i>Upload</button>
  </form>
</div>

<h5 class="mb-3">My Uploaded Files</h5>
<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Exam</th><th>Class</th><th>Subject</th><th>Uploaded</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($files as $f): ?>
        <tr>
          <td><?= e($f['exam_name']) ?></td><td><?= e($f['class_name']) ?></td><td><?= e($f['subject_name']) ?></td>
          <td><?= e($f['uploaded_at']) ?></td>
          <td><a href="<?= BASE_URL ?>/<?= e($f['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$files): ?><tr><td colspan="5" class="text-center text-muted">No files uploaded yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
