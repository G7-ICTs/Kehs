<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['teacher']);
$announcements = getAnnouncementsFor('teacher');
$pageTitle = 'Announcements';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-4">Announcements</h3>
<div class="card stat-card p-3">
  <?php foreach ($announcements as $a): ?>
    <div class="border-bottom pb-3 mb-3">
      <h6 class="mb-1"><?= e($a['title']) ?></h6>
      <p class="mb-1"><?= nl2br(e($a['body'])) ?></p>
      <small class="text-muted">By <?= e($a['author'] ?? 'School Administration') ?> on <?= e($a['created_at']) ?></small>
    </div>
  <?php endforeach; ?>
  <?php if (!$announcements): ?><p class="text-muted mb-0">No announcements yet.</p><?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
