<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();

$fields = [
    'school_name'          => 'School Name',
    'school_website'       => 'School Website',
    'school_address'       => 'School Address',
    'school_phone'         => 'School Phone',
    'school_email'         => 'School Email',
    'grading_pass_mark'    => 'Pass Mark (%)',
    'password_expiry_days' => 'Password Expiry (days)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['school_phone'])) {
        $schoolPhone = normalizeTzPhone($_POST['school_phone']);
        if ($schoolPhone === null) {
            flash('error', 'School ' . lcfirst(TZ_PHONE_ERROR));
            redirect('/admin/settings.php');
        }
        $_POST['school_phone'] = $schoolPhone;
    }
    $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
    foreach ($fields as $key => $label) {
        if (isset($_POST[$key])) {
            $stmt->execute([trim($_POST[$key]), $key]);
        }
    }
    Cache::forget('settings');
    flash('success', 'Settings updated successfully.');
    logActivity($_SESSION['user_id'], 'update_settings', 'Updated system settings');
    redirect('/admin/settings.php');
}

$current = [];
foreach (array_keys($fields) as $key) { $current[$key] = getSetting($key); }

$pageTitle = 'System Settings';
include __DIR__ . '/../includes/header.php';
?>
<h3 class="mb-4">System Settings</h3>

<div class="card stat-card p-4" style="max-width:600px;">
  <form method="POST">
    <?php foreach ($fields as $key => $label): ?>
      <div class="mb-3">
        <label class="form-label"><?= e($label) ?></label>
        <input class="form-control" name="<?= e($key) ?>" value="<?= e($key === 'school_phone' ? formatTzPhone($current[$key]) : $current[$key]) ?>" <?= $key === 'school_phone' ? tzPhoneAttrs() : '' ?>>
      </div>
    <?php endforeach; ?>
    <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Save Settings</button>
  </form>
</div>
<p class="text-muted small mt-3">
  These settings control school-wide details used across the system (e.g. the pass mark
  used in Reports &amp; Statistics, and how often staff/parents must change their password).
  Academic year and term setup is managed separately
  under <a href="academic_years.php">Academic Years</a>.
</p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
