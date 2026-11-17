<?php
require_once __DIR__ . '/config/config.php';
requireLogin();
$db = getDB();
$userId = $_SESSION['user_id'];

$user = $db->prepare("SELECT * FROM users WHERE id = ?");
$user->execute([$userId]);
$user = $user->fetch();

// -------- Update profile details --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'update_profile') {
    $name = trim($_POST['name'] ?? '');
    $phone = normalizeTzPhone($_POST['phone'] ?? '');
    if ($name === '') {
        flash('error', 'Name cannot be empty.');
    } elseif ($phone === null) {
        flash('error', TZ_PHONE_ERROR);
    } else {
        try {
            $userRepo = new UserRepository($db);
            $userRepo->updateProfile($userId, [
                'name' => $name,
                'phone' => $phone,
                'address' => trim($_POST['address'] ?? ''),
                'bio' => trim($_POST['bio'] ?? ''),
            ]);
            $_SESSION['user_name'] = $name;
            flash('success', 'Profile updated successfully.');
            logActivity($userId, 'update_profile', 'Updated own profile');
        } catch (PDOException $ex) {
            // Typically: this database was built before the phone/address/bio
            // columns existed. database/optimize.sql adds them (safe to re-run).
            appLog('Profile update failed: ' . $ex->getMessage(), 'ERROR');
            flash('error', 'Could not save your profile - the database is missing some profile columns. Ask an administrator to import database/optimize.sql.');
        }
    }
    redirect('/profile.php');
}

// -------- Change password (delegates to the layered AuthController) --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'change_password') {
    $error = authController()->changePassword(
        $userId,
        $_POST['current_password'] ?? '',
        $_POST['new_password'] ?? '',
        $_POST['confirm_password'] ?? ''
    );
    if ($error) {
        flash('error', $error);
    } else {
        flash('success', 'Password changed successfully.');
    }
    redirect('/profile.php');
}

$pageTitle = 'My Profile';
include __DIR__ . '/includes/header.php';
?>
<h3 class="mb-4">My Profile</h3>

<div class="row g-4">
  <div class="col-md-6">
    <div class="card stat-card p-4">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="mb-0">Profile Details</h6>
        <span class="badge bg-primary text-capitalize"><i class="bi bi-person-badge me-1"></i><?= e($_SESSION['user_role']) ?></span>
      </div>
      <form method="POST">
        <input type="hidden" name="form_action" value="update_profile">
        <div class="mb-2"><label class="form-label">Full Name</label><input class="form-control" name="name" value="<?= e($user['name']) ?>" required></div>
        <div class="mb-2"><label class="form-label">User ID / Reg No.</label><input class="form-control" value="<?= e($user['username']) ?>" disabled>
          <small class="text-muted">Contact an administrator to change your User ID.</small></div>
        <div class="mb-2"><label class="form-label">Email <span class="text-muted">(optional, for notifications)</span></label><input class="form-control" name="email" value="<?= e($user['email'] ?? '') ?>" disabled>
          <small class="text-muted">Contact an administrator to change your email.</small></div>
        <div class="mb-2">
          <label class="form-label">Phone</label>
          <div class="input-icon-group">
            <i class="bi bi-telephone"></i>
            <input type="tel" class="form-control" name="phone" value="<?= e(formatTzPhone($user['phone'] ?? '')) ?>" <?= tzPhoneAttrs() ?>>
            <small class="text-muted d-block mt-1">Tanzanian numbers only (+255).</small>
          </div>
        </div>
        <div class="mb-2"><label class="form-label">Address</label><input class="form-control" name="address" value="<?= e($user['address'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label">Bio</label><textarea class="form-control" name="bio" rows="3"><?= e($user['bio'] ?? '') ?></textarea></div>
        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Save Changes</button>
      </form>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card stat-card p-4">
      <h6 class="mb-3">Change Password</h6>
      <?php if (!empty($_SESSION['password_expired'])): ?>
        <div class="alert alert-warning py-2 small">Your password has expired (30-day policy) and must be changed to continue using the system.</div>
      <?php endif; ?>
      <form method="POST">
        <input type="hidden" name="form_action" value="change_password">
        <div class="mb-2"><label class="form-label">Current Password</label><input type="password" class="form-control" name="current_password" required></div>
        <div class="mb-2"><label class="form-label">New Password</label><input type="password" class="form-control" name="new_password" minlength="8" required></div>
        <div class="mb-3"><label class="form-label">Confirm New Password</label><input type="password" class="form-control" name="confirm_password" minlength="8" required></div>
        <button class="btn btn-warning" type="submit"><i class="bi bi-shield-lock me-1"></i>Change Password</button>
      </form>
      <p class="text-muted small mt-3 mb-0">Policy: passwords must be changed at least every 30 days.</p>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
