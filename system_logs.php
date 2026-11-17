<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

$tab = $_GET['tab'] ?? 'activity';

// -------- Activity Log (per-user actions: logins, creates, deletes, etc.) --------
$userFilter   = $_GET['user_id'] ?? '';
$actionFilter = trim($_GET['action_filter'] ?? '');
$fromDate     = $_GET['from'] ?? '';
$toDate       = $_GET['to'] ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 40;

$where = [];
$params = [];
if ($userFilter !== '') { $where[] = 'a.user_id = ?'; $params[] = (int)$userFilter; }
if ($actionFilter !== '') { $where[] = 'a.action = ?'; $params[] = $actionFilter; }
if ($fromDate !== '') { $where[] = 'a.created_at >= ?'; $params[] = $fromDate . ' 00:00:00'; }
if ($toDate !== '') { $where[] = 'a.created_at <= ?'; $params[] = $toDate . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $db->prepare("SELECT COUNT(*) c FROM activity_log a $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetch()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT a.*, u.name AS user_name, u.username AS user_username
     FROM activity_log a LEFT JOIN users u ON a.user_id = u.id
     $whereSql ORDER BY a.created_at DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$allUsers = $db->query("SELECT id, name, username FROM users ORDER BY name")->fetchAll();
$distinctActions = $db->query("SELECT DISTINCT action FROM activity_log ORDER BY action")->fetchAll();

// -------- Application Log (PHP errors / appLog() events) --------
$appLogLines = [];
if (is_file(APP_LOG_PATH)) {
    $all = file(APP_LOG_PATH, FILE_IGNORE_NEW_LINES);
    $appLogLines = array_slice($all, -300); // last 300 lines
    $appLogLines = array_reverse($appLogLines);
}

if (($_GET['clear_app_log'] ?? '') === '1' && $tab === 'application') {
    @file_put_contents(APP_LOG_PATH, '');
    logActivity($_SESSION['user_id'], 'clear_app_log', 'Cleared application log file');
    redirect('/admin/system_logs.php?tab=application');
}

$pageTitle = 'System Logs';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-1">System Logs</h3>
<p class="text-muted mb-4">Review what every user has done on the system, and check application-level
  errors/warnings if something goes wrong.</p>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab=='activity'?'active':'' ?>" href="?tab=activity"><i class="bi bi-clock-history me-1"></i>User Activity Log</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab=='application'?'active':'' ?>" href="?tab=application"><i class="bi bi-terminal me-1"></i>Application Log</a></li>
</ul>

<?php if ($tab === 'activity'): ?>
  <div class="card stat-card p-3 mb-3">
    <form class="row g-2" method="GET">
      <input type="hidden" name="tab" value="activity">
      <div class="col-md-3">
        <select class="form-select form-select-sm" name="user_id">
          <option value="">-- All Users --</option>
          <?php foreach ($allUsers as $u): ?>
            <option value="<?= $u['id'] ?>" <?= $userFilter==$u['id']?'selected':'' ?>><?= e($u['name']) ?> (<?= e($u['username']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select class="form-select form-select-sm" name="action_filter">
          <option value="">-- All Actions --</option>
          <?php foreach ($distinctActions as $a): ?>
            <option value="<?= e($a['action']) ?>" <?= $actionFilter==$a['action']?'selected':'' ?>><?= e($a['action']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><input type="date" class="form-control form-control-sm" name="from" value="<?= e($fromDate) ?>" placeholder="From"></div>
      <div class="col-md-2"><input type="date" class="form-control form-control-sm" name="to" value="<?= e($toDate) ?>" placeholder="To"></div>
      <div class="col-md-2 d-flex gap-2">
        <button class="btn btn-sm btn-primary flex-fill" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
        <a class="btn btn-sm btn-outline-secondary" href="?tab=activity">Reset</a>
      </div>
    </form>
  </div>

  <div class="card stat-card p-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td class="text-nowrap small"><?= e($l['created_at']) ?></td>
            <td><?= $l['user_name'] ? e($l['user_name']) . ' <span class="text-muted small">(' . e($l['user_username']) . ')</span>' : '<span class="text-muted">System</span>' ?></td>
            <td><span class="badge bg-secondary"><?= e($l['action']) ?></span></td>
            <td class="small"><?= e($l['details']) ?></td>
            <td class="small text-muted"><?= e($l['ip_address'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="5" class="text-center text-muted">No activity recorded yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
      <nav class="mt-2">
        <ul class="pagination pagination-sm mb-0">
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p==$page?'active':'' ?>">
              <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page'=>$p])) ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </div>

<?php else: ?>
  <div class="card stat-card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <p class="text-muted small mb-0">Last <?= count($appLogLines) ?> line(s) of <code><?= e(str_replace(__DIR__ . '/../', '', APP_LOG_PATH)) ?></code> — PHP errors/warnings and internal application events. Most recent first.</p>
      <a href="?tab=application&clear_app_log=1" class="btn btn-sm btn-outline-danger confirm-delete"><i class="bi bi-trash me-1"></i>Clear Log</a>
    </div>
    <?php if ($appLogLines): ?>
      <pre class="bg-dark text-light p-3 rounded small" style="max-height:520px; overflow:auto; white-space:pre-wrap;"><?php foreach ($appLogLines as $line) echo e($line) . "\n"; ?></pre>
    <?php else: ?>
      <p class="text-muted text-center py-4 mb-0">No application log entries yet — that's a good sign.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
