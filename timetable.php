<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        $stmt = $db->prepare(
            "INSERT INTO timetable (class_id, subject_id, teacher_id, academic_year_id, day_of_week, start_time, end_time, room)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            (int)$_POST['class_id'], (int)$_POST['subject_id'], (int)$_POST['teacher_id'], (int)$_POST['academic_year_id'],
            $_POST['day_of_week'], $_POST['start_time'], $_POST['end_time'], trim($_POST['room']),
        ]);
        flash('success', 'Timetable entry added.');
    } catch (Exception $ex) { flash('error', 'Could not add entry: ' . $ex->getMessage()); }
    redirect('/admin/timetable.php');
}
if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM timetable WHERE id = ?")->execute([(int)$_GET['delete']]);
    flash('success', 'Timetable entry removed.');
    redirect('/admin/timetable.php');
}

// -------------------------------------------------------------------
// Auto-generate the whole-school timetable (see includes/timetable_generator.php).
//
// O-Level and A-Level classes get DIFFERENT schedules: O-Level uses 45-minute
// periods and every subject of a class is mutually exclusive; A-Level uses
// 90-minute double sessions and only subjects that share a combination
// (PCB, PCM, ...) are mutually exclusive.
//
//  - "Auto-Generate" only fills in missing slots; existing entries are never
//    changed or removed.
//  - "Clear & Regenerate" first deletes this academic year's whole timetable
//    and rebuilds it from scratch (use it after changing assignments or
//    combinations, or if an earlier run produced a poor layout).
// -------------------------------------------------------------------
require_once __DIR__ . '/../includes/timetable_generator.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['auto_generate', 'regenerate'], true)) {
    $yearRow = $db->query("SELECT id, year_name FROM academic_years WHERE is_current = 1 LIMIT 1")->fetch();
    if (!$yearRow) {
        flash('error', 'No current academic year is set (Admin > Academic Years). Set one first.');
        redirect('/admin/timetable.php');
    }
    $yearId = (int)$yearRow['id'];
    $isRegenerate = ($_POST['action'] === 'regenerate');

    try {
        $db->beginTransaction();
        if ($isRegenerate) {
            $db->prepare("DELETE FROM timetable WHERE academic_year_id = ?")->execute([$yearId]);
        }
        $result = generateTimetable($db, $yearId);
        $db->commit();
    } catch (Throwable $ex) {
        if ($db->inTransaction()) { $db->rollBack(); }
        appLog('Timetable generation failed: ' . $ex->getMessage(), 'ERROR');
        flash('error', 'Could not generate the timetable: ' . $ex->getMessage());
        redirect('/admin/timetable.php');
    }

    $placed = $result['placed'];
    $o = $result['placed_by_level']['O-Level'] ?? 0;
    $a = $result['placed_by_level']['A-Level'] ?? 0;
    logActivity($_SESSION['user_id'], $isRegenerate ? 'regenerate_timetable' : 'auto_generate_timetable',
        "Generated $placed timetable slot(s) for {$yearRow['year_name']} (O-Level $o, A-Level $a)");

    $summary = "$placed slot(s) placed - O-Level: $o, A-Level: $a.";
    if ($result['unplaced']) {
        $list = implode('; ', array_slice($result['unplaced'], 0, 5)) . (count($result['unplaced']) > 5 ? '; ...' : '');
        flash('error', "$summary Could not fit " . count($result['unplaced']) . " assignment(s) - the week is full for that teacher or class: $list. "
            . 'Add entries manually or reduce periods/week for those.');
    } else {
        flash('success', ($isRegenerate ? 'Timetable cleared and regenerated. ' : 'Timetable generated (existing entries left untouched). ') . $summary);
    }
    redirect('/admin/timetable.php');
}

// ---- Listing: filter by level / class, and paginate (the whole-school timetable is large) ----
$levelFilter = in_array($_GET['level'] ?? '', ['O-Level', 'A-Level'], true) ? $_GET['level'] : '';
$classFilter = (int)($_GET['class_id'] ?? 0);
$where = ['1=1'];
$params = [];
if ($levelFilter !== '') { $where[] = 'c.level = ?'; $params[] = $levelFilter; }
if ($classFilter > 0)    { $where[] = 'tt.class_id = ?'; $params[] = $classFilter; }
$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) c FROM timetable tt JOIN classes c ON tt.class_id = c.id WHERE $whereSql");
$countStmt->execute($params);
$pg = paginate((int)$countStmt->fetch()['c'], 100);

$entriesStmt = $db->prepare(
    "SELECT tt.*, c.name AS class_name, c.level AS class_level, sub.name AS subject_name, u.name AS teacher_name
     FROM timetable tt
     JOIN classes c ON tt.class_id = c.id
     JOIN subjects sub ON tt.subject_id = sub.id
     JOIN teachers t ON tt.teacher_id = t.id
     JOIN users u ON t.user_id = u.id
     WHERE $whereSql
     ORDER BY c.level, c.name, FIELD(tt.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), tt.start_time
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
);
$entriesStmt->execute($params);
$entries = $entriesStmt->fetchAll();
$comboMap = subjectCombinationCodes();
$currentYearRow = $db->query("SELECT year_name FROM academic_years WHERE is_current = 1 LIMIT 1")->fetch();

$classes = $db->query("SELECT * FROM classes ORDER BY name")->fetchAll();
$subjects = $db->query("SELECT * FROM subjects ORDER BY name")->fetchAll();
$teachers = $db->query("SELECT t.id, u.name FROM teachers t JOIN users u ON t.user_id = u.id ORDER BY u.name")->fetchAll();
$years = $db->query("SELECT * FROM academic_years ORDER BY year_name DESC")->fetchAll();

$pageTitle = 'Timetable';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">Teaching Timetable</h3>
    <?php if ($currentYearRow): ?><small class="text-muted">Current academic year: <?= e($currentYearRow['year_name']) ?></small><?php endif; ?>
  </div>
  <div class="d-flex gap-1 flex-wrap">
    <form method="POST" class="d-inline" onsubmit="return confirm('Auto-generate the whole-school timetable? This only fills in missing slots for teacher/subject/class assignments that don\'t have enough periods scheduled yet - existing entries are never changed or removed.');">
      <input type="hidden" name="action" value="auto_generate">
      <button class="btn btn-success btn-sm" type="submit"><i class="bi bi-magic me-1"></i>Auto-Generate Timetable</button>
    </form>
    <form method="POST" class="d-inline" onsubmit="return confirm('Clear the ENTIRE timetable for the current academic year and rebuild it from scratch? Every existing entry (including ones you added by hand) will be deleted. This cannot be undone.');">
      <input type="hidden" name="action" value="regenerate">
      <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Clear &amp; Regenerate</button>
    </form>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg me-1"></i>Add Entry</button>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="border rounded p-2 h-100 small"><span class="badge bg-secondary me-1">O-Level</span>
      <strong>Form I-IV (secondary):</strong> 45-minute periods; every student takes every subject, so a class's subjects never overlap.</div>
  </div>
  <div class="col-md-6">
    <div class="border rounded p-2 h-100 small"><span class="badge bg-info text-dark me-1">A-Level</span>
      <strong>Form V-VI (advanced):</strong> 90-minute double sessions; only subjects in the same combination (PCB, PCM, ...) are kept apart, so different combinations can learn at the same time.
      Set each combination's subjects under <a href="<?= BASE_URL ?>/admin/combinations.php">Combinations</a>.</div>
  </div>
</div>
<p class="text-muted small">Auto-Generate uses each class's teacher/subject assignments (Admin ▸ Assign Teachers, "Periods per Week":
  45-minute periods for O-Level, 90-minute sessions for A-Level).</p>

<form class="row g-2 mb-3 align-items-end" method="GET">
  <div class="col-auto">
    <label class="form-label small text-muted mb-1">Level</label>
    <select class="form-select form-select-sm" name="level">
      <option value="">All levels</option>
      <?php foreach (['O-Level', 'A-Level'] as $lv): ?><option <?= $levelFilter===$lv?'selected':'' ?>><?= $lv ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label small text-muted mb-1">Class</label>
    <select class="form-select form-select-sm" name="class_id">
      <option value="">All classes</option>
      <?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $classFilter==$c['id']?'selected':'' ?>><?= e($c['name']) ?> (<?= e($c['level']) ?>)</option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
    <a href="?" class="btn btn-sm btn-outline-secondary">Reset</a>
  </div>
</form>

<div class="card stat-card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Class</th><th>Level</th><th>Day</th><th>Time</th><th>Subject</th><th>Combinations</th><th>Teacher</th><th>Room</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($entries as $e): ?>
        <tr>
          <td><?= e($e['class_name']) ?></td>
          <td><span class="badge <?= $e['class_level']==='A-Level'?'bg-info text-dark':'bg-secondary' ?>"><?= e($e['class_level']) ?></span></td>
          <td><?= e($e['day_of_week']) ?></td>
          <td><?= e(substr($e['start_time'],0,5)) ?> - <?= e(substr($e['end_time'],0,5)) ?></td>
          <td><?= e($e['subject_name']) ?></td>
          <td>
            <?php if ($e['class_level'] === 'A-Level'): ?>
              <?php if (!empty($comboMap[(int)$e['subject_id']])): foreach ($comboMap[(int)$e['subject_id']] as $code): ?>
                <span class="badge bg-light text-dark border"><?= e($code) ?></span>
              <?php endforeach; else: ?><span class="text-muted small">all</span><?php endif; ?>
            <?php else: ?><span class="text-muted small">— N/A</span><?php endif; ?>
          </td>
          <td><?= e($e['teacher_name']) ?></td><td><?= e($e['room']) ?></td>
          <td><a href="?delete=<?= $e['id'] ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="bi bi-trash"></i></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$entries): ?><tr><td colspan="9" class="text-center text-muted">No timetable entries yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= renderPagination($pg, 'entry') ?>
</div>
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <input type="hidden" name="action" value="create">
    <div class="modal-header"><h5 class="modal-title">Add Timetable Entry</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="row g-2">
        <div class="col-6"><label class="form-label">Class</label><select class="form-select" name="class_id" required>
          <?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label">Subject</label><select class="form-select" name="subject_id" required>
          <?php foreach ($subjects as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label">Teacher</label><select class="form-select" name="teacher_id" required>
          <?php foreach ($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label">Academic Year</label><select class="form-select" name="academic_year_id" required>
          <?php foreach ($years as $y): ?><option value="<?= $y['id'] ?>"><?= e($y['year_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label">Day</label><select class="form-select" name="day_of_week" required>
          <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d): ?><option><?= $d ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label">Room</label><input class="form-control" name="room"></div>
        <div class="col-6"><label class="form-label">Start Time</label><input type="time" class="form-control" name="start_time" required></div>
        <div class="col-6"><label class="form-label">End Time</label><input type="time" class="form-control" name="end_time" required></div>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
