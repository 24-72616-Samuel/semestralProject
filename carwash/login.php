<?php
require __DIR__ . '/includes/bootstrap.php';
$title = 'Login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $lock = $_SESSION['login_lock'] ?? 0;
    if ($lock > time()) {
        flash('error', 'Too many failed attempts. Please wait ' . ceil(($lock - time()) / 60) . ' minute(s) and try again.');
        redirect('login.php');
    }
    $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();

    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        session_regenerate_id(true);
        unset($_SESSION['login_fails'], $_SESSION['login_lock']);
        $_SESSION['user'] = ['id' => (int)$user['id'], 'name' => $user['name'], 'role' => $user['role']];
        if (!empty($_POST['remember'])) remember_issue($pdo, (int)$user['id']);
        redirect(in_array($user['role'], STAFF_ROLES, true) ? 'admin/dashboard.php' : 'customer/dashboard.php');
    }
    $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
    if ($_SESSION['login_fails'] >= 5) { $_SESSION['login_lock'] = time() + 300; $_SESSION['login_fails'] = 0; }
    flash('error', 'Invalid email or password.');
}
$authPage = true;      // no top navbar / footer on this page
$fullWidth = true;
require __DIR__ . '/includes/header.php';
?>
<div class="auth-page">
  <section class="auth-main">
    <div class="auth-card">
    <a class="brand auth-brand" href="<?= url('index.php') ?>"><img class="brand-logo" src="<?= url('assets/img/logo.png') ?>" alt="">EAYILA <span class="text-red">CARWASH</span></a>
    <form method="post" autocomplete="on">
      <h2 class="auth-title">Log in</h2>
      <p class="auth-sub">Enter your email or username and password to continue.</p>
      <?= csrf_field() ?>

      <label class="auth-field" for="email">Email or username</label>
      <div class="auth-input">
        <?= icon('mail') ?>
        <input id="email" class="form-control" type="text" name="email" placeholder="Email or username" autocomplete="username" required autofocus value="<?= e($email ?? '') ?>">
      </div>

      <label class="auth-field" for="password">Password</label>
      <div class="auth-input">
        <?= icon('lock') ?>
        <input id="password" class="form-control" type="password" name="password" placeholder="Your password" autocomplete="current-password" required>
        <button type="button" class="auth-eye" id="togglePw" aria-label="Show password" aria-pressed="false"><?= icon('eye') ?></button>
      </div>

      <label class="auth-remember"><input class="form-check-input" type="checkbox" name="remember" value="1" id="remember"<?= !empty($_POST['remember']) ? ' checked' : '' ?>> <span>Remember me on this device</span></label>

      <button class="btn btn-red auth-submit">Log in</button>

      <p class="auth-switch">New here? <a href="<?= url('register.php') ?>" data-auth-swap>Create an account</a></p>
      <a class="auth-back" href="<?= url('index.php') ?>"><?= icon('arrow-left') ?> Back to home</a>
    </form>
    </div>
  </section>
  <aside class="auth-side">
    <span class="auth-bubble b1"></span><span class="auth-bubble b2"></span>
    <div class="auth-side-body">
      <h1 class="auth-headline">Precision Care<br>for Every Ride.</h1>
      <p class="auth-tagline">Operating Hours: <?= e(hours_label()) ?></p>
    </div>
  </aside>
</div>
<script>
document.getElementById('togglePw').addEventListener('click', function () {
  const pw = document.getElementById('password');
  const show = pw.type === 'password';
  pw.type = show ? 'text' : 'password';
  this.setAttribute('aria-pressed', show ? 'true' : 'false');
  this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
