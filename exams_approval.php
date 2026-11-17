<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

if (isset($_GET['approve'])) {
    $db->prepare("UPDATE exams SET status = 'approved' WHERE id = ?")->execute([$_GET['approve']]);
    flash('success', 'Results approved.');
    logActivity($_SESSION['user_id'], 'approve_exam', "Approved exam id {$_GET['approve']}");
    redirect('/admin/exams_approval.php');
}
if (isset($_GET['publish'])) {
    $db->prepare("UPDATE exams SET status = 'published' WHERE id = ?")->execute([$_GET['publish']]);
    flash('success', 'Results published. Parents and students can now view them.');
    logActivity($_SESSION['user_id'], 'publish_exam', "Published exam id {$_GET['publish']}");
    redirect('/admin/exams_approval.php');
}
if (isset($_GET['reject'])) {
    $db->prepare("UPDATE exams SET status = 'draft' WHERE id = ?")->execute([$_GET['reject']]);
    flash('success', 'Sent back to teacher for revision.');
    redirect('/admin/exams_approval.php');
}

$exams = $db->query(
    "SELECT e.*, c.name AS class_name, sub.name AS subject_name, t.name AS term_name,
            u.name AS teacher_name,
            (SELECT COUNT(*) FROM results r WHERE r.exam_id = e.id) AS result_count
     FROM exams e
     JOIN classes c ON e.class_id = c.id
     JOIN subjects sub ON e.subject_id = sub.id
     JOIN terms t ON e.term_id = t.id
     JOIN teachers tt ON e.teacher_id = tt.id
     JOIN users u ON tt.user_id = u.id
     ORDER BY FIELD(e.status,'submitted','approved','draft','published'), e.created_at DESC"
)->fetchAll();

$pageTitle = 'Approve Results';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-4">Exam Results — Review &amp; Approval</h3>

<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Exam</th><th>Class</th><th>Subject</th><th>Term</th><th>Teacher</th><th>Entries</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($exams as $ex): ?>
        <tr>
          <td><?= e($ex['name']) ?></td>
          <td><?= e($ex['class_name']) ?></td>
          <td><?= e($ex['subject_name']) ?></td>
          <td><?= e($ex['term_name']) ?></td>
          <td><?= e($ex['teacher_name']) ?></td>
          <td><?= (int)$ex['result_count'] ?></td>
          <td><span class="badge badge-status-<?= e($ex['status']) ?>"><?= e(ucfirst($ex['status'])) ?></span></td>
          <td class="text-nowrap">
            <?php if ($ex['status'] === 'submitted'): ?>
              <a href="?approve=<?= $ex['id'] ?>" class="btn btn-sm btn-success">Approve</a>
              <a href="?reject=<?= $ex['id'] ?>" class="btn btn-sm btn-outline-secondary">Send Back</a>
            <?php elseif ($ex['status'] === 'approved'): ?>
              <a href="?publish=<?= $ex['id'] ?>" class="btn btn-sm btn-primary">Publish</a>
            <?php elseif ($ex['status'] === 'published'): ?>
              <span class="text-success small"><i class="bi bi-check2-circle"></i> Visible to parents</span>
            <?php else: ?>
              <span class="text-muted small">Awaiting teacher submission</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$exams): ?><tr><td colspan="8" class="text-center text-muted">No exams found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="text-muted small mt-3">
  Workflow: Teacher enters marks (<strong>draft</strong>) → Teacher submits (<strong>submitted</strong>) →
  Admin approves (<strong>approved</strong>) → Admin publishes (<strong>published</strong>, visible to parents/students).
</p>
<?php include __DIR__ . '/../includes/footer.php'; ?>
