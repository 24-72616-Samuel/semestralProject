<?php
require __DIR__ . '/includes/bootstrap.php';
$title = 'Sign up';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $pass = $_POST['password'] ?? '';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
        flash('error', 'Enter your name, a valid email, and a password of at least 8 characters.');
    } else {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) {
            flash('error', 'That email is already registered.');
        } else {
            $pdo->prepare('INSERT INTO users (name,email,phone,password_hash,role) VALUES (?,?,?,?,?)')
                ->execute([$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT), 'customer']);
            flash('success', 'Account created. Please log in.');
            redirect('login.php');
        }
    }
}
$authPage = true;
$fullWidth = true;
require __DIR__ . '/includes/header.php';
?>
<div class="auth-page is-register">
  <section class="auth-main">
    <div class="auth-card">
      <a class="brand auth-brand" href="<?= url('index.php') ?>"><img class="brand-logo" src="<?= url('assets/img/logo.png') ?>" alt="">EAYILA <span class="text-red">CARWASH</span></a>
      <form method="post" autocomplete="on">
        <h2 class="auth-title">Create account</h2>
        <p class="auth-sub">Sign up to book a wash and keep your receipts.</p>
        <?= csrf_field() ?>

        <label class="auth-field" for="name">Full name</label>
        <div class="auth-input"><?= icon('user') ?><input id="name" class="form-control" name="name" autocomplete="name" required value="<?= e($_POST['name'] ?? '') ?>"></div>

        <label class="auth-field" for="email">Email</label>
        <div class="auth-input"><?= icon('mail') ?><input id="email" class="form-control" type="email" name="email" placeholder="you@example.com" autocomplete="email" required value="<?= e($_POST['email'] ?? '') ?>"></div>

        <label class="auth-field" for="phone">Mobile number</label>
        <div class="auth-input"><?= icon('phone') ?><input id="phone" class="form-control" name="phone" autocomplete="tel" value="<?= e($_POST['phone'] ?? '') ?>"></div>

        <label class="auth-field" for="password">Password (min 8 characters)</label>
        <div class="auth-input">
          <?= icon('lock') ?>
          <input id="password" class="form-control" type="password" name="password" autocomplete="new-password" required minlength="8">
          <button type="button" class="auth-eye" id="togglePw" aria-label="Show password" aria-pressed="false"><?= icon('eye') ?></button>
        </div>

        <button class="btn btn-red auth-submit">Sign up</button>
        <p class="auth-switch">Already have an account? <a href="<?= url('login.php') ?>" data-auth-swap>Log in</a></p>
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
