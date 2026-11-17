<?php
// Usage: $title = 'Page'; require __DIR__ . '/header.php';
$u = current_user();
$here = basename($_SERVER['SCRIPT_NAME']);
$nav = function (string $file, string $label, string $path) use ($here): string {
    $active = $here === $file ? ' active' : '';
    return '<li class="nav-item"><a class="nav-link' . $active . '" href="' . url($path) . '">' . $label . '</a></li>';
};
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'Home') ?> · <?= e(SHOP_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
  <link rel="stylesheet" href="<?= asset('assets/css/ledger.css') ?>">
  <?php foreach (($extraCss ?? []) as $css): ?><link rel="stylesheet" href="<?= asset($css) ?>">
  <?php endforeach; ?>
</head>
<body class="<?= $u ? 'has-sidebar' : '' ?><?= !empty($homePage) ? ' home-page' : '' ?>">
<?php if ($u): /* ===== Logged in (customer / owner / admin / staff): sidebar layout, menu comes from $_SESSION['user']['role'] ===== */
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
    $roleLabel = ucfirst($u['role']);
    $nowHr = (int)date('G'); $shopOpen = $nowHr >= OPEN_HOUR && $nowHr < CLOSE_HOUR;
?>
<div class="app-shell">
  <aside class="sidebar" id="sidebar" aria-label="Main menu">
    <a class="brand sidebar-brand" href="<?= url(is_staff() ? 'admin/dashboard.php' : 'customer/dashboard.php') ?>"><img class="brand-logo" src="<?= url('assets/img/logo.png') ?>" alt="">EAYILA <span class="text-red">CARWASH</span></a>
    <nav class="sidebar-nav">
      <?php require_once __DIR__ . '/messages.php'; $unreadMsgs = chat_unread($pdo, $u); ?>
      <?php foreach (sidebar_menu($u['role']) as $section => $items): ?>
        <div class="sidebar-section"><?= e($section) ?></div>
        <?php foreach ($items as [$label, $path, $ico]):
            $active = str_ends_with($script, '/' . $path); ?>
          <a class="side-link<?= $active ? ' active' : '' ?>" href="<?= url($path) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= e($label) ?></span><?php if ($unreadMsgs > 0 && str_ends_with($path, 'messages.php')): ?><span class="side-badge" aria-label="<?= (int)$unreadMsgs ?> unread"><?= $unreadMsgs > 99 ? '99+' : (int)$unreadMsgs ?></span><?php endif; ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-shopinfo">
      <span class="open-pill <?= $shopOpen ? 'is-open' : 'is-closed' ?>"><?= $shopOpen ? 'Open now' : 'Closed' ?></span>
      <div><?= icon('clock') ?>Daily <?= e(hours_label()) ?></div>
      <div><?= icon('phone') ?><?= e(SHOP_PHONE) ?></div>
      <div><?= icon('mail') ?><?= e(SHOP_EMAIL) ?></div>
    </div>
    <div class="sidebar-user">
      <div class="sidebar-avatar"><?= e(strtoupper(mb_substr($u['name'], 0, 1))) ?></div>
      <div class="sidebar-user-text"><strong><?= $u['role'] === 'customer' ? '<a class="text-reset text-decoration-none" href="' . url('customer/profile.php') . '">' . e($u['name']) . '</a>' : e($u['name']) ?></strong><small><?= e($roleLabel) ?></small></div>
      <form method="post" action="<?= url('logout.php') ?>" class="sidebar-logout-form"><?= csrf_field() ?><button class="sidebar-logout" title="Logout" aria-label="Logout"><?= icon('logout') ?></button></form>
    </div>
  </aside>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <div class="app-main">
    <div class="mobile-bar">
      <button type="button" class="mobile-toggle" id="sidebarToggle" aria-label="Open menu" aria-controls="sidebar"><?= icon('menu') ?></button>
      <span class="brand"><img class="brand-logo" src="<?= url('assets/img/logo.png') ?>" alt="">EAYILA <span class="text-red">CARWASH</span></span>
    </div>
    <div class="app-topbar">
      <div class="topbar-info" aria-label="Shop information">
        <span title="<?= e(SHOP_ADDRESS) ?>"><?= icon('pin') ?><?= e(SHOP_ADDRESS) ?></span>
        <span><?= icon('phone') ?><?= e(SHOP_PHONE) ?></span>
        <span><?= icon('mail') ?><?= e(SHOP_EMAIL) ?></span>
        <span><?= icon('clock') ?>Daily <?= e(hours_label()) ?></span>
        <span class="open-pill <?= $shopOpen ? 'is-open' : 'is-closed' ?>"><?= $shopOpen ? 'Open now' : 'Closed' ?></span>
      </div>
      <a class="topbar-bell" href="<?= url(is_staff() ? 'admin/messages.php' : 'customer/messages.php') ?>" title="Messages" aria-label="Messages<?= $unreadMsgs > 0 ? ', ' . (int)$unreadMsgs . ' unread' : '' ?>"><?= icon('bell') ?><?php if ($unreadMsgs > 0): ?><span class="topbar-count"><?= $unreadMsgs > 99 ? '99+' : (int)$unreadMsgs ?></span><?php endif; ?></a>
      <span class="topbar-user"><?= icon('user') ?><?= e($u['name']) ?></span>
    </div>
<?php elseif (empty($authPage)): /* ===== Guest: top navbar (skipped on the login page) ===== */ ?>
<nav class="navbar navbar-expand-lg navbar-dark topbar sticky-top">
  <div class="container">
    <a class="navbar-brand brand" href="<?= url('index.php') ?>"><img class="brand-logo" src="<?= url('assets/img/logo.png') ?>" alt="">EAYILA <span class="text-red">CARWASH</span></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav ms-auto me-lg-3 mb-2 mb-lg-0">
        <?= $nav('index.php', 'Home', 'index.php') ?>
        <li class="nav-item"><a class="nav-link" href="<?= url('index.php#rates') ?>">Services &amp; Rates</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= url('index.php#gallery') ?>">Our Work</a></li>
        <?= $nav('queue.php', 'Live Queue', 'queue.php') ?>
        <li class="nav-item"><a class="nav-link" href="<?= url('index.php#location') ?>">Contact</a></li>
      </ul>
      <div class="d-flex gap-2 align-items-center">
        <a class="btn btn-outline-light btn-sm rounded-pill px-3" href="<?= url('login.php') ?>">Login</a>
        <a class="btn btn-red btn-sm rounded-pill px-3" href="<?= url('register.php') ?>">Sign up</a>
      </div>
    </div>
  </div>
</nav>
<?php endif; ?>

<?php $toasts = flashes(); ?>
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1090">
  <?php foreach ($toasts as [$type, $msg]): ?>
    <div class="toast align-items-center <?= $type === 'warning' ? 'text-dark' : 'text-white' ?> border-0 bg-<?= $type === 'error' ? 'danger' : ($type === 'warning' ? 'warning' : 'success') ?>" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="5000">
      <div class="d-flex">
        <div class="toast-body"><?= e($msg) ?></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<main class="<?= !empty($fullWidth) ? '' : 'container py-4' ?>">
