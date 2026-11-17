<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_year') {
    try {
        $db->beginTransaction();
        if (!empty($_POST['is_current'])) {
            $db->exec("UPDATE academic_years SET is_current = 0");
        }
        $stmt = $db->prepare("INSERT INTO academic_years (year_name, start_date, end_date, is_current) VALUES (?, ?, ?, ?)");
        $stmt->execute([trim($_POST['year_name']), $_POST['start_date'] ?: null, $_POST['end_date'] ?: null, !empty($_POST['is_current']) ? 1 : 0]);
        $db->commit();
        Cache::forget('current_academic_year_id');
        flash('success', 'Academic year added.');
    } catch (Exception $ex) {
        $db->rollBack();
        flash('error', 'Could not add academic year: ' . $ex->getMessage());
    }
    redirect('/admin/academic_years.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_term') {
    try {
        $db->prepare("INSERT INTO terms (academic_year_id, name) VALUES (?, ?)")
           ->execute([(int)$_POST['academic_year_id'], trim($_POST['name'])]);
        flash('success', 'Term added.');
    } catch (Exception $ex) { flash('error', 'Could not add term: ' . $ex->getMessage()); }
    redirect('/admin/academic_years.php');
}

if (isset($_GET['set_current'])) {
    $db->exec("UPDATE academic_years SET is_current = 0");
    $db->prepare("UPDATE academic_years SET is_current = 1 WHERE id = ?")->execute([$_GET['set_current']]);
    Cache::forget('current_academic_year_id');
    flash('success', 'Current academic year updated.');
    redirect('/admin/academic_years.php');
}

$years = $db->query("SELECT * FROM academic_years ORDER BY year_name DESC")->fetchAll();
$terms = $db->query(
    "SELECT t.*, ay.year_name FROM terms t JOIN academic_years ay ON t.academic_year_id = ay.id ORDER BY ay.year_name DESC"
)->fetchAll();

$pageTitle = 'Academic Years';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-4">Academic Years &amp; Terms</h3>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card stat-card p-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0">Academic Years</h6>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#yearModal"><i class="bi bi-plus-lg"></i></button>
      </div>
      <table class="table table-sm">
        <thead><tr><th>Year</th><th>Start</th><th>End</th><th>Current</th></tr></thead>
        <tbody>
        <?php foreach ($years as $y): ?>
          <tr>
            <td><?= e($y['year_name']) ?></td><td><?= e($y['start_date']) ?></td><td><?= e($y['end_date']) ?></td>
            <td>
              <?php if ($y['is_current']): ?>
                <span class="badge bg-success">Current</span>
              <?php else: ?>
                <a href="?set_current=<?= $y['id'] ?>" class="btn btn-sm btn-outline-secondary">Set current</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card stat-card p-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0">Terms</h6>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#termModal"><i class="bi bi-plus-lg"></i></button>
      </div>
      <table class="table table-sm">
        <thead><tr><th>Term</th><th>Academic Year</th></tr></thead>
        <tbody>
        <?php foreach ($terms as $t): ?>
          <tr><td><?= e($t['name']) ?></td><td><?= e($t['year_name']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="yearModal" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <input type="hidden" name="action" value="create_year">
    <div class="modal-header"><h5 class="modal-title">Add Academic Year</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Year Name</label><input class="form-control" name="year_name" placeholder="e.g. 2027" required></div>
      <div class="mb-2"><label class="form-label">Start Date</label><input type="date" class="form-control" name="start_date"></div>
      <div class="mb-2"><label class="form-label">End Date</label><input type="date" class="form-control" name="end_date"></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="is_current" id="isCurrent"><label class="form-check-label" for="isCurrent">Set as current academic year</label></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form></div>
</div>

<div class="modal fade" id="termModal" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <input type="hidden" name="action" value="create_term">
    <div class="modal-header"><h5 class="modal-title">Add Term</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Academic Year</label>
        <select class="form-select" name="academic_year_id" required>
          <?php foreach ($years as $y): ?><option value="<?= $y['id'] ?>"><?= e($y['year_name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="mb-2"><label class="form-label">Term Name</label><input class="form-control" name="name" placeholder="e.g. Term 1, Mid-Term" required></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
