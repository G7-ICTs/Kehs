<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();
require_once __DIR__ . '/../includes/ai_engine.php';

$stats = [
    'teachers' => $db->query("SELECT COUNT(*) c FROM teachers")->fetch()['c'],
    'students' => $db->query("SELECT COUNT(*) c FROM students")->fetch()['c'],
    'parents'  => $db->query("SELECT COUNT(*) c FROM parents_guardians")->fetch()['c'],
    'classes'  => $db->query("SELECT COUNT(*) c FROM classes")->fetch()['c'],
    'subjects' => $db->query("SELECT COUNT(*) c FROM subjects")->fetch()['c'],
    'pending'  => $db->query("SELECT COUNT(*) c FROM exams WHERE status = 'submitted'")->fetch()['c'],
];

// Students by level (active students)
$levelCounts = ['O-Level' => 0, 'A-Level' => 0];
foreach ($db->query(
    "SELECT c.level, COUNT(s.id) n FROM students s JOIN classes c ON s.class_id = c.id WHERE s.status = 'active' GROUP BY c.level"
)->fetchAll() as $r) { $levelCounts[$r['level']] = (int)$r['n']; }

// Result sheets by status
$examStatus = ['published' => 0, 'submitted' => 0, 'draft' => 0, 'approved' => 0];
foreach ($db->query("SELECT status, COUNT(*) n FROM exams GROUP BY status")->fetchAll() as $r) { $examStatus[$r['status']] = (int)$r['n']; }

// Smart Assistant: timetable coverage + top findings + class overview
$year = aiYear($db);
$cov = aiCoverage($db, $year ? (int)$year['id'] : null);
$snap = aiSnapshot($db, $year);
$insights = array_slice(array_filter(aiInsights($snap, $cov), fn($i) => $i['level'] !== 'success'), 0, 3);
$classList = $snap['classes'];
usort($classList, fn($a, $b) => $b['students'] <=> $a['students']);
$classList = array_slice($classList, 0, 6);
$teachersFree = count(array_filter($snap['teachers'], fn($t) => $t['assignments'] === 0));

$announcements = $db->query(
    "SELECT a.title, a.created_at, u.name AS author FROM announcements a LEFT JOIN users u ON a.posted_by = u.id
     ORDER BY a.created_at DESC LIMIT 5"
)->fetchAll();

$recentActivity = $db->query(
    "SELECT a.*, u.name FROM activity_log a LEFT JOIN users u ON a.user_id = u.id
     ORDER BY a.created_at DESC LIMIT 8"
)->fetchAll();

function dashInitials(?string $name): string {
    $parts = preg_split('/\s+/', trim((string)$name));
    $i = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1)) . (count($parts) > 1 ? mb_strtoupper(mb_substr(end($parts), 0, 1)) : '');
    return $i ?: '?';
}
function dashColor(?string $name): string {
    $palette = ['#2f80ed', '#198754', '#e67e22', '#8e44ad', '#c0392b', '#16a085', '#34495e'];
    return $palette[abs(crc32((string)$name)) % count($palette)];
}
$levelColors = ['critical' => '#dc3545', 'warning' => '#e0a800', 'info' => '#0dcaf0'];

$pageTitle = 'Admin Dashboard';
$bodyClass = 'dashboard-bg';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <h3 class="mb-0">Admin Dashboard</h3>
  <?php if ($year): ?><span class="badge bg-light text-dark border">Academic year <?= e($year['year_name']) ?></span><?php endif; ?>
</div>

<div class="dash-card mb-3" style="height:auto">
  <form method="GET" action="students.php" class="row g-2 align-items-end">
    <div class="col-md-6">
      <label class="form-label small text-muted mb-1"><i class="bi bi-search me-1"></i>Quick Student Search</label>
      <input type="text" class="form-control" name="search" placeholder="Search by admission number (e.g. <?= e(SCHOOL_CODE) ?>.0001) or name…">
    </div>
    <div class="col-auto"><button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Search</button></div>
  </form>
</div>

<!-- Row 1: students / results / quick actions -->
<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="dash-card">
      <div class="dash-title">Students</div>
      <div class="dash-sub"><?= (int)$stats['classes'] ?> classes · <?= (int)$stats['subjects'] ?> subjects</div>
      <div class="metric-row">
        <div><div class="metric-num text-primary"><?= (int)$stats['students'] ?></div><div class="metric-label">Total</div></div>
        <div><div class="metric-num text-success"><?= $levelCounts['O-Level'] ?></div><div class="metric-label">O-Level</div></div>
        <div><div class="metric-num text-info"><?= $levelCounts['A-Level'] ?></div><div class="metric-label">A-Level</div></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="dash-card">
      <div class="dash-title">Results</div>
      <div class="dash-sub">Result sheets by status</div>
      <div class="metric-row">
        <div><div class="metric-num text-success"><?= $examStatus['published'] ?></div><div class="metric-label">Published</div></div>
        <div><div class="metric-num text-danger"><?= (int)$stats['pending'] ?></div><div class="metric-label">Awaiting approval</div></div>
        <div><div class="metric-num text-secondary"><?= $examStatus['draft'] ?></div><div class="metric-label">Draft</div></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="dash-card">
      <div class="dash-title mb-2">Quick Actions</div>
      <div class="qa-row">
        <a class="qa-item" href="teachers.php"><i class="bi bi-person-plus"></i><br>Teacher</a>
        <a class="qa-item" href="students.php"><i class="bi bi-mortarboard"></i><br>Student</a>
        <a class="qa-item" href="assign_teacher.php"><i class="bi bi-link-45deg"></i><br>Assign</a>
        <a class="qa-item" href="exams_approval.php"><i class="bi bi-check2-square"></i><br>Approve</a>
        <a class="qa-item" href="documents.php"><i class="bi bi-file-earmark-arrow-up"></i><br>Document</a>
        <a class="qa-item" href="ai_assistant.php"><i class="bi bi-stars"></i><br>Assistant</a>
      </div>
    </div>
  </div>
</div>

<!-- Row 2: timetable donut / classes / announcements -->
<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="dash-card">
      <div class="dash-title">Timetable</div>
      <div class="dash-sub">Required weekly lessons already scheduled</div>
      <div class="d-flex align-items-center gap-4">
        <div class="donut" style="--p:<?= (int)$cov['pct'] ?>;--c:<?= $cov['pct'] >= 100 ? '#198754' : ($cov['pct'] >= 60 ? '#e0a800' : '#dc3545') ?>"><span><?= (int)$cov['pct'] ?>%</span></div>
        <div>
          <div class="text-muted small">Required</div><div class="metric-num text-primary fs-4"><?= (int)$cov['need'] ?></div>
          <div class="text-muted small mt-2">Scheduled</div><div class="metric-num text-success fs-4"><?= (int)$cov['placed'] ?></div>
        </div>
      </div>
      <a href="timetable.php" class="btn btn-sm btn-outline-primary mt-3">Open timetable</a>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="dash-card">
      <div class="dash-title">Classes</div>
      <div class="dash-sub">Largest classes · active students</div>
      <?php foreach ($classList as $c): ?>
        <div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
          <span><?= e($c['name']) ?> <span class="badge <?= $c['level'] === 'A-Level' ? 'bg-info text-dark' : 'bg-secondary' ?>"><?= e($c['level']) ?></span></span>
          <strong><?= (int)$c['students'] ?></strong>
        </div>
      <?php endforeach; ?>
      <?php if (!$classList): ?><p class="text-muted small mb-0">No classes yet.</p><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="dash-card">
      <div class="dash-title">Announcements</div>
      <div class="dash-sub">Password reset requests pending - <?= (int)$snap['pending_resets'] ?></div>
      <?php foreach ($announcements as $a): ?>
        <div class="feed-item">
          <span class="avatar-circle" style="background:<?= dashColor($a['author']) ?>"><?= e(dashInitials($a['author'])) ?></span>
          <div><div class="name"><?= e($a['author'] ?? 'System') ?></div><div class="text"><?= e(mb_strimwidth($a['title'], 0, 60, '…')) ?></div></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$announcements): ?><p class="text-muted small mb-0">No announcements yet.</p><?php endif; ?>
    </div>
  </div>
</div>

<!-- Row 3: teachers / smart assistant / reports -->
<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="dash-card">
      <div class="dash-title">Teachers</div>
      <div class="dash-sub"><?= (int)$stats['parents'] ?> parents registered</div>
      <div class="metric-row">
        <div><div class="metric-num text-primary"><?= (int)$stats['teachers'] ?></div><div class="metric-label">Total</div></div>
        <div><div class="metric-num text-success"><?= (int)$stats['teachers'] - $teachersFree ?></div><div class="metric-label">Teaching</div></div>
        <div><div class="metric-num text-warning"><?= $teachersFree ?></div><div class="metric-label">Free</div></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="dash-card">
      <div class="dash-title"><i class="bi bi-stars text-warning me-1"></i>Smart Assistant</div>
      <div class="dash-sub">Top things needing attention</div>
      <?php foreach ($insights as $i): ?>
        <div class="small mb-1"><i class="bi bi-circle-fill me-1" style="font-size:.5rem;color:<?= $levelColors[$i['level']] ?? '#999' ?>"></i><?= e($i['title']) ?></div>
      <?php endforeach; ?>
      <?php if (!$insights): ?><p class="small text-success mb-1"><i class="bi bi-check-circle me-1"></i>No problems found.</p><?php endif; ?>
      <a href="ai_assistant.php" class="btn btn-sm btn-outline-primary mt-2">Open assistant</a>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="dash-card">
      <div class="dash-title">Reports</div>
      <div class="dash-sub">Shortcuts</div>
      <ul class="list-clean">
        <li><a href="reports.php">Reports &amp; Statistics</a><i class="bi bi-chevron-right"></i></li>
        <li><a href="exams_approval.php">Results awaiting approval</a><strong><?= (int)$stats['pending'] ?></strong></li>
        <li><a href="system_logs.php">System logs</a><i class="bi bi-chevron-right"></i></li>
      </ul>
    </div>
  </div>
</div>

<div class="dash-card" style="height:auto">
  <div class="dash-title mb-2">Recent Activity</div>
  <ul class="list-group list-group-flush small">
    <?php foreach ($recentActivity as $a): ?>
      <li class="list-group-item px-0">
        <strong><?= e($a['name'] ?? 'System') ?></strong> — <?= e($a['action']) ?>
        <span class="text-muted d-block"><?= e($a['created_at']) ?></span>
      </li>
    <?php endforeach; ?>
    <?php if (!$recentActivity): ?><li class="list-group-item px-0 text-muted">No activity yet.</li><?php endif; ?>
  </ul>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
