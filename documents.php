<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $fileName = handleFileUpload($_FILES['document'] ?? [], UPLOAD_DOCS_DIR, 'doc');
    if ($fileName) {
        $stmt = $db->prepare("INSERT INTO documents (title, category, file_name, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([trim($_POST['title']), trim($_POST['category']), $fileName, 'uploads/documents/' . $fileName, $_SESSION['user_id']]);
        flash('success', 'Document uploaded successfully.');
        logActivity($_SESSION['user_id'], 'upload_document', "Uploaded document: {$_POST['title']}");
    }
    redirect('/admin/documents.php');
}

if (isset($_GET['delete'])) {
    $stmt = $db->prepare("SELECT file_path FROM documents WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    if ($row = $stmt->fetch()) {
        $full = __DIR__ . '/../' . $row['file_path'];
        if (is_file($full)) unlink($full);
        $db->prepare("DELETE FROM documents WHERE id = ?")->execute([$_GET['delete']]);
        flash('success', 'Document removed.');
    }
    redirect('/admin/documents.php');
}

$pg = paginate((int)$db->query("SELECT COUNT(*) c FROM documents")->fetch()['c'], 25);
$documents = $db->query("SELECT d.*, u.name AS uploader FROM documents d LEFT JOIN users u ON d.uploaded_by = u.id ORDER BY d.uploaded_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}")->fetchAll();

$pageTitle = 'Documents';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Documents</h3>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="bi bi-cloud-upload me-1"></i>Upload Document</button>
</div>
<p class="text-muted">Manage Joining Instructions, School Rules, Fee Structure, Examination Regulations, etc. These are downloadable by parents.</p>

<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Title</th><th>Category</th><th>Uploaded By</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($documents as $d): ?>
        <tr>
          <td><?= e($d['title']) ?></td>
          <td><span class="badge bg-secondary"><?= e($d['category']) ?></span></td>
          <td><?= e($d['uploader'] ?? '—') ?></td>
          <td><?= e($d['uploaded_at']) ?></td>
          <td class="text-nowrap">
            <a href="<?= BASE_URL ?>/<?= e($d['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i></a>
            <a href="?delete=<?= $d['id'] ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="bi bi-trash"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$documents): ?><tr><td colspan="5" class="text-center text-muted">No documents uploaded yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= renderPagination($pg, 'document') ?>
</div>

<div class="modal fade" id="uploadModal" tabindex="-1">
  <div class="modal-dialog"><form method="POST" enctype="multipart/form-data" class="modal-content">
    <input type="hidden" name="action" value="upload">
    <div class="modal-header"><h5 class="modal-title">Upload Document</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Title</label><input class="form-control" name="title" placeholder="e.g. Form I Joining Instructions 2027" required></div>
      <div class="mb-2"><label class="form-label">Category</label>
        <select class="form-select" name="category" required>
          <option>Joining Instructions</option>
          <option>School Rules and Regulations</option>
          <option>Fee Structure</option>
          <option>Examination Regulations</option>
          <option>Other</option>
        </select>
      </div>
      <div class="mb-2"><label class="form-label">File (PDF/DOC/DOCX, max 10MB)</label><input type="file" class="form-control" name="document" accept=".pdf,.doc,.docx" required></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Upload</button></div>
  </form></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
