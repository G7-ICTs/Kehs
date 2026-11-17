<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

// A-Level subject combinations (PCB, PCM, ...) and which subjects each one
// takes. This mapping is what lets the timetable generator schedule A-Level
// classes differently from O-Level: subjects in the SAME combination must not
// overlap, subjects that never share a combination can run at the same time.
// Combinations apply to A-Level classes only - O-Level (secondary) students
// don't have one.

$tableReady = true;
try {
    $db->query("SELECT 1 FROM combination_subjects LIMIT 1");
} catch (Throwable $ex) {
    $tableReady = false;
}

if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $comboId = (int)($_POST['combination_id'] ?? 0);
        $subjectIds = array_unique(array_map('intval', $_POST['subject_ids'] ?? []));
        try {
            $db->beginTransaction();
            $db->prepare("DELETE FROM combination_subjects WHERE combination_id = ?")->execute([$comboId]);
            $ins = $db->prepare("INSERT IGNORE INTO combination_subjects (combination_id, subject_id) VALUES (?, ?)");
            foreach ($subjectIds as $sid) {
                if ($sid > 0) { $ins->execute([$comboId, $sid]); }
            }
            $db->commit();
            Cache::forget('subject_combination_codes');
            logActivity($_SESSION['user_id'], 'update_combination_subjects', "Updated subjects for combination #$comboId");
            flash('success', 'Combination subjects saved. Re-run the timetable generator to apply the change.');
        } catch (Throwable $ex) {
            if ($db->inTransaction()) { $db->rollBack(); }
            flash('error', 'Could not save: ' . $ex->getMessage());
        }
        redirect('/admin/combinations.php');
    }

    if ($action === 'auto_match') {
        // Match each name in "Physics, Chemistry, Mathematics" to an existing subject by name.
        $subjects = $db->query("SELECT id, name FROM subjects")->fetchAll();
        $combos = $db->query("SELECT id, code, name FROM combinations")->fetchAll();
        $ins = $db->prepare("INSERT IGNORE INTO combination_subjects (combination_id, subject_id) VALUES (?, ?)");
        $added = 0;
        $missing = [];
        foreach ($combos as $c) {
            foreach (explode(',', $c['name']) as $part) {
                $part = trim($part);
                if (preg_match('/\(([^)]+)\)/', $part, $m)) { $part = trim($m[1]); } // "Language (English)" -> "English"
                $found = null;
                foreach ($subjects as $s) {
                    if (strcasecmp($s['name'], $part) === 0) { $found = (int)$s['id']; break; }
                }
                if ($found === null) { $missing[$part] = true; continue; }
                $ins->execute([(int)$c['id'], $found]);
                $added += $ins->rowCount();
            }
        }
        Cache::forget('subject_combination_codes');
        logActivity($_SESSION['user_id'], 'auto_match_combinations', "Auto-matched combination subjects ($added new link(s))");
        if ($missing) {
            flash('success', "Matched $added subject link(s). No subject named: " . implode(', ', array_keys($missing))
                . ' - add them under Subjects, then run Auto-match again.');
        } else {
            flash('success', "Matched $added subject link(s).");
        }
        redirect('/admin/combinations.php');
    }
}

$combinations = $db->query("SELECT * FROM combinations ORDER BY code")->fetchAll();
$subjects = $db->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll();
$selected = [];
if ($tableReady) {
    foreach ($db->query("SELECT combination_id, subject_id FROM combination_subjects")->fetchAll() as $r) {
        $selected[(int)$r['combination_id']][(int)$r['subject_id']] = true;
    }
}

$pageTitle = 'Combinations';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h3 class="mb-0">A-Level Combinations</h3>
  <?php if ($tableReady): ?>
  <form method="POST" class="d-inline">
    <input type="hidden" name="action" value="auto_match">
    <button class="btn btn-success btn-sm" type="submit"><i class="bi bi-magic me-1"></i>Auto-match subjects by name</button>
  </form>
  <?php endif; ?>
</div>

<div class="alert alert-info py-2 small">
  <strong>Advanced (A-Level) students only.</strong> Combinations apply to Form V and Form VI. Secondary (O-Level, Form I-IV)
  students have <strong>no combination</strong> - they all take the same subjects.
  Tick the subjects each combination studies; the timetable generator uses this so that, for example, Biology (PCB)
  and Mathematics (PCM) can be taught at the same time, while Physics, Chemistry and Biology (all in PCB) never overlap.
</div>

<?php if (!$tableReady): ?>
  <div class="alert alert-warning">The <code>combination_subjects</code> table doesn't exist in this database yet.
    Import <code>database/optimize.sql</code> in phpMyAdmin (it is safe to run more than once), then reload this page.</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($combinations as $c): ?>
    <div class="col-md-6 col-xl-4">
      <form method="POST" class="card stat-card p-3 h-100">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="combination_id" value="<?= (int)$c['id'] ?>">
        <h6 class="mb-0"><span class="badge bg-info text-dark me-1"><?= e($c['code']) ?></span><?= e($c['name']) ?></h6>
        <hr class="my-2">
        <div class="mb-2" style="max-height:200px;overflow:auto;">
          <?php foreach ($subjects as $s): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="subject_ids[]" value="<?= (int)$s['id'] ?>"
                     id="c<?= (int)$c['id'] ?>s<?= (int)$s['id'] ?>" <?= !empty($selected[(int)$c['id']][(int)$s['id']]) ? 'checked' : '' ?>>
              <label class="form-check-label" for="c<?= (int)$c['id'] ?>s<?= (int)$s['id'] ?>"><?= e($s['name']) ?></label>
            </div>
          <?php endforeach; ?>
          <?php if (!$subjects): ?><p class="text-muted small mb-0">No subjects yet (Admin &gt; Subjects).</p><?php endif; ?>
        </div>
        <button class="btn btn-primary btn-sm mt-auto" type="submit"><i class="bi bi-save me-1"></i>Save</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (!$combinations): ?><p class="text-muted">No combinations defined.</p><?php endif; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
