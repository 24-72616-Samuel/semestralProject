<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_role(['customer']);
$title = 'My profile';
const MAX_SAVED_VEHICLES = 5;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'details') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            flash('error', 'Please enter your name (up to 100 characters).');
        } elseif ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,30}$/', $phone)) {
            flash('error', 'Enter a valid mobile number, for example 0917 123 4567.');
        } else {
            $pdo->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?')->execute([$name, $phone === '' ? null : $phone, $user['id']]);
            $_SESSION['user']['name'] = $name;
            flash('success', 'Profile updated.');
        }
    } elseif ($action === 'password') {
        $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$user['id']]);
        $new = $_POST['new_password'] ?? '';
        if (!password_verify($_POST['current_password'] ?? '', (string)$st->fetchColumn())) {
            flash('error', 'Your current password is not correct.');
        } elseif (strlen($new) < 8) {
            flash('error', 'The new password must be at least 8 characters.');
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            flash('error', 'The new passwords do not match.');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            remember_forget_all($pdo, (int)$user['id']);   // other devices must log in again
            session_regenerate_id(true);
            flash('success', 'Password changed.');
        }
    } elseif ($action === 'add_vehicle') {
        $plate = strtoupper(preg_replace('/\s+/', ' ', trim($_POST['plate'] ?? '')));
        $label = trim($_POST['label'] ?? '');
        $count = $pdo->prepare('SELECT COUNT(*) FROM customer_vehicles WHERE user_id = ?');
        $count->execute([$user['id']]);
        if ($plate === '' || mb_strlen($plate) > 20 || mb_strlen($label) > 60) {
            flash('error', 'Enter a plate number (up to 20 characters) and an optional name (up to 60).');
        } elseif ((int)$count->fetchColumn() >= MAX_SAVED_VEHICLES) {
            flash('error', 'You can save up to ' . MAX_SAVED_VEHICLES . ' vehicles. Remove one first.');
        } else {
            $ins = $pdo->prepare('INSERT IGNORE INTO customer_vehicles (user_id, plate, label) VALUES (?,?,?)');
            $ins->execute([$user['id'], $plate, $label === '' ? null : $label]);
            flash($ins->rowCount() ? 'success' : 'error', $ins->rowCount() ? 'Vehicle saved.' : 'That plate is already saved.');
        }
    } elseif ($action === 'remove_vehicle') {
        $pdo->prepare('DELETE FROM customer_vehicles WHERE id = ? AND user_id = ?')->execute([(int)($_POST['id'] ?? 0), $user['id']]);
        flash('success', 'Vehicle removed.');
    }
    redirect('customer/profile.php');
}

$st = $pdo->prepare('SELECT name, email, phone, created_at FROM users WHERE id = ?');
$st->execute([$user['id']]);
$me = $st->fetch();

$st = $pdo->prepare("SELECT COUNT(*) total, SUM(status = 'completed') done, COALESCE(SUM(CASE WHEN status = 'completed' THEN total_price END), 0) spent
    FROM reservations WHERE user_id = ?");
$st->execute([$user['id']]);
$sum = $st->fetch();

$st = $pdo->prepare('SELECT * FROM customer_vehicles WHERE user_id = ? ORDER BY plate');
$st->execute([$user['id']]);
$vehicles = $st->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<?php page_head('My profile', 'Your details, password and saved vehicles.'); ?>
<div class="stats" style="max-width:640px">
  <div class="card stat shadow-sm"><div class="card-body"><span><?= (int)$sum['total'] ?></span><small>Bookings</small></div></div>
  <div class="card stat shadow-sm"><div class="card-body"><span><?= (int)$sum['done'] ?></span><small>Completed washes</small></div></div>
  <div class="card stat shadow-sm"><div class="card-body"><span><?= peso($sum['spent']) ?></span><small>Total spent</small></div></div>
  <div class="card stat shadow-sm"><div class="card-body"><span><?= e(date('M Y', strtotime($me['created_at']))) ?></span><small>Member since</small></div></div>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <form method="post" class="card card-body shadow-sm vstack gap-3 mb-4">
      <h2 class="h5 mb-0">Personal details</h2>
      <?= csrf_field() ?><input type="hidden" name="action" value="details">
      <label class="form-label w-100">Full name <input class="form-control" name="name" required maxlength="100" autocomplete="name" value="<?= e($me['name']) ?>"></label>
      <label class="form-label w-100">Email <input class="form-control" value="<?= e($me['email']) ?>" disabled><small class="text-muted">Your email is your login, so it can't be changed here. Message the shop if you need it updated.</small></label>
      <label class="form-label w-100">Mobile number <input class="form-control" name="phone" maxlength="30" autocomplete="tel" value="<?= e($me['phone'] ?? '') ?>"></label>
      <button class="btn btn-primary align-self-start">Save changes</button>
    </form>

    <form method="post" class="card card-body shadow-sm vstack gap-3 mb-4">
      <h2 class="h5 mb-0">Change password</h2>
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <label class="form-label w-100">Current password <input class="form-control" type="password" name="current_password" autocomplete="current-password" required></label>
      <label class="form-label w-100">New password <small class="text-muted">(min 8 characters)</small> <input class="form-control" type="password" name="new_password" minlength="8" autocomplete="new-password" required></label>
      <label class="form-label w-100">Confirm new password <input class="form-control" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></label>
      <button class="btn btn-primary align-self-start">Change password</button>
    </form>
  </div>

  <div class="col-lg-6">
    <div class="card card-body shadow-sm mb-4">
      <h2 class="h5">My vehicles</h2>
      <p class="text-muted small">Saved plates show up as suggestions when you book. Up to <?= MAX_SAVED_VEHICLES ?>.</p>
      <ul class="list-group list-group-flush mb-3">
        <?php foreach ($vehicles as $v): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center px-0">
            <span><strong><?= e($v['plate']) ?></strong><?= $v['label'] ? ' <span class="text-muted">· ' . e($v['label']) . '</span>' : '' ?></span>
            <form method="post" data-confirm="Remove this vehicle?"><?= csrf_field() ?><input type="hidden" name="action" value="remove_vehicle"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="btn btn-danger btn-sm">Remove</button></form>
          </li>
        <?php endforeach; ?>
        <?php if (!$vehicles): ?><li class="list-group-item px-0 text-muted">No saved vehicles yet.</li><?php endif; ?>
      </ul>
      <?php if (count($vehicles) < MAX_SAVED_VEHICLES): ?>
      <form method="post" class="vstack gap-2">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_vehicle">
        <div class="row g-2">
          <div class="col-sm-5"><input class="form-control" name="plate" placeholder="Plate number" required maxlength="20"></div>
          <div class="col-sm-7"><input class="form-control" name="label" placeholder="Name, e.g. Red Vios (optional)" maxlength="60"></div>
        </div>
        <button class="btn btn-outline-primary align-self-start">+ Save vehicle</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
