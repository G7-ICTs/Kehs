<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();
// generateTempPassword() lives in includes/functions.php (shared with
// admin/teachers.php and admin/parents.php).

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resolve') {
    $requestId = (int)$_POST['request_id'];
    $stmt = $db->prepare("SELECT * FROM password_reset_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();

    if (!$req) {
        flash('error', 'Request not found.');
    } elseif (!$req['user_id']) {
        flash('error', 'That User ID did not match any account - nothing to reset. You can mark it dismissed instead.');
    } else {
        $tempPassword = generateTempPassword();
        $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?")
           ->execute([password_hash($tempPassword, PASSWORD_DEFAULT), $req['user_id']]);
        $db->prepare("UPDATE password_reset_requests SET status='resolved', resolved_at=NOW(), resolved_by=? WHERE id=?")
           ->execute([$_SESSION['user_id'], $requestId]);
        logActivity($_SESSION['user_id'], 'reset_password', "Reset password for user id {$req['user_id']} via request #$requestId");
        flash('success', "Temporary password generated: \"$tempPassword\" — give this to the user directly. They'll be asked to change it on next login.");
    }
    redirect('/admin/password_resets.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss') {
    $db->prepare("UPDATE password_reset_requests SET status='resolved', resolved_at=NOW(), resolved_by=? WHERE id=?")
       ->execute([$_SESSION['user_id'], (int)$_POST['request_id']]);
    flash('success', 'Request dismissed.');
    redirect('/admin/password_resets.php');
}

$requests = $db->query(
    "SELECT pr.*, u.name AS user_name, u.username AS user_username, r.name AS role_name
     FROM password_reset_requests pr
     LEFT JOIN users u ON pr.user_id = u.id
     LEFT JOIN roles r ON u.role_id = r.id
     ORDER BY (pr.status = 'pending') DESC, pr.requested_at DESC"
)->fetchAll();

$pageTitle = 'Password Reset Requests';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-1">Password Reset Requests</h3>
<p class="text-muted mb-4">Submitted from the "Forgot your password?" link on the login page. Generate a
  temporary password and hand it to the user directly (in person or by phone) — they'll be required to
  change it the next time they log in.</p>

<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Requested</th><th>User ID Submitted</th><th>Matched Account</th><th>Role</th><th>Note</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($requests as $r): ?>
        <tr>
          <td class="text-nowrap"><?= e($r['requested_at']) ?></td>
          <td><code><?= e($r['username_submitted']) ?></code></td>
          <td><?= $r['user_id'] ? e($r['user_name']) : '<span class="text-danger">No match found</span>' ?></td>
          <td><?php if ($r['role_name']): ?><span class="badge bg-secondary text-capitalize"><?= e($r['role_name']) ?></span><?php endif; ?></td>
          <td><?= e($r['note'] ?? '—') ?></td>
          <td>
            <?php if ($r['status'] === 'pending'): ?>
              <span class="badge bg-warning text-dark">Pending</span>
            <?php else: ?>
              <span class="badge bg-success">Resolved</span>
            <?php endif; ?>
          </td>
          <td class="text-nowrap">
            <?php if ($r['status'] === 'pending'): ?>
              <?php if ($r['user_id']): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Generate a new temporary password for this user? Their old password will stop working immediately.');">
                  <input type="hidden" name="action" value="resolve">
                  <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                  <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-key me-1"></i>Reset Password</button>
                </form>
              <?php endif; ?>
              <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="dismiss">
                <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Dismiss</button>
              </form>
            <?php else: ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$requests): ?><tr><td colspan="7" class="text-center text-muted">No password reset requests yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
