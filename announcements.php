<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $stmt = $db->prepare("INSERT INTO announcements (title, body, audience, posted_by) VALUES (?, ?, ?, ?)");
    $stmt->execute([trim($_POST['title']), trim($_POST['body']), $_POST['audience'], $_SESSION['user_id']]);
    flash('success', 'Announcement posted.');
    logActivity($_SESSION['user_id'], 'post_announcement', "Posted: {$_POST['title']}");
    redirect('/admin/announcements.php');
}
if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM announcements WHERE id = ?")->execute([$_GET['delete']]);
    flash('success', 'Announcement removed.');
    redirect('/admin/announcements.php');
}

$pg = paginate((int)$db->query("SELECT COUNT(*) c FROM announcements")->fetch()['c'], 25);
$announcements = $db->query(
    "SELECT a.*, u.name AS author FROM announcements a LEFT JOIN users u ON a.posted_by = u.id
     ORDER BY a.created_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
)->fetchAll();

$pageTitle = 'Announcements';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Announcements</h3>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg me-1"></i>New Announcement</button>
</div>

<div class="card stat-card p-3">
  <?php foreach ($announcements as $a): ?>
    <div class="border-bottom pb-3 mb-3">
      <div class="d-flex justify-content-between">
        <h6 class="mb-1"><?= e($a['title']) ?> <span class="badge bg-secondary text-capitalize"><?= e($a['audience']) ?></span></h6>
        <a href="?delete=<?= $a['id'] ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="bi bi-trash"></i></a>
      </div>
      <p class="mb-1"><?= nl2br(e($a['body'])) ?></p>
      <small class="text-muted">By <?= e($a['author'] ?? 'System') ?> on <?= e($a['created_at']) ?></small>
    </div>
  <?php endforeach; ?>
  <?php if (!$announcements): ?><p class="text-muted mb-0">No announcements yet.</p><?php endif; ?>
  <?= renderPagination($pg, 'announcement') ?>
</div>

<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <input type="hidden" name="action" value="create">
    <div class="modal-header"><h5 class="modal-title">New Announcement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Title</label><input class="form-control" name="title" required></div>
      <div class="mb-2"><label class="form-label">Message</label><textarea class="form-control" name="body" rows="4" required></textarea></div>
      <div class="mb-2"><label class="form-label">Audience</label>
        <select class="form-select" name="audience">
          <option value="all">Everyone</option>
          <option value="teacher">Teachers only</option>
          <option value="parent">Parents only</option>
          <option value="student">Students only</option>
        </select>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Post</button></div>
  </form></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
