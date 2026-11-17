<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/reviews.php';
$staff = require_role(STAFF_ROLES);
$title = 'Customer reviews';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle') {
        $pdo->prepare('UPDATE reviews SET is_visible = 1 - is_visible WHERE id = ?')->execute([$id]);
        flash('success', 'Review visibility updated.');
    } elseif ($action === 'reply') {
        $reply = trim((string)($_POST['reply'] ?? ''));
        if (mb_strlen($reply) > 1000) {
            flash('error', 'Reply is too long (max 1000 characters).');
        } else {
            $pdo->prepare('UPDATE reviews SET staff_reply = ? WHERE id = ?')->execute([$reply === '' ? null : $reply, $id]);
            $uid = $pdo->prepare('SELECT user_id FROM reviews WHERE id = ?'); $uid->execute([$id]);
            if ($reply !== '' && ($cid = $uid->fetchColumn())) notify($pdo, (int)$cid, 'The shop replied to your review.', 'app');
            flash('success', $reply === '' ? 'Reply removed.' : 'Reply saved.');
        }
    }
    redirect('admin/reviews.php' . (isset($_GET['stars']) ? '?stars=' . (int)$_GET['stars'] : ''));
}

$stats = review_stats($pdo, false);
$filter = clean_rating($_GET['stars'] ?? 0);
$sql = "SELECT rv.*, u.name AS customer, p.name AS package FROM reviews rv
    JOIN users u ON u.id = rv.user_id JOIN packages p ON p.id = rv.package_id"
    . ($filter ? ' WHERE rv.rating = ' . $filter : '') . ' ORDER BY rv.id DESC LIMIT 200';
$rows = $pdo->query($sql)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<?php page_head('Customer reviews', 'See ratings, reply publicly and hide reviews from the public page.'); ?>
<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="card shadow-sm h-100"><div class="card-body text-center">
    <div class="rating-big"><?= $stats['count'] ? number_format($stats['avg'], 1) : '—' ?></div>
    <?= stars_html($stats['avg'], 'lg') ?>
    <div class="text-muted small mt-1"><?= (int)$stats['count'] ?> review<?= $stats['count'] === 1 ? '' : 's' ?></div>
  </div></div></div>
  <div class="col-md-8"><div class="card shadow-sm h-100"><div class="card-body">
    <?php foreach ($stats['dist'] as $star => $n): $pct = $stats['count'] ? round($n / $stats['count'] * 100) : 0; ?>
      <a class="dist-row" href="<?= url('admin/reviews.php?stars=' . $star) ?>">
        <span><?= $star ?> ★</span><span class="dist-bar"><i style="width:<?= $pct ?>%"></i></span><span class="text-muted"><?= $n ?></span>
      </a>
    <?php endforeach; ?>
    <?php if ($filter): ?><a class="small" href="<?= url('admin/reviews.php') ?>">Clear filter (showing <?= $filter ?>-star only)</a><?php endif; ?>
  </div></div></div>
</div>

<?php foreach ($rows as $rv): ?>
  <div class="card shadow-sm mb-3"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-2">
      <div><strong><?= e($rv['customer']) ?></strong> <span class="text-muted">· <?= e($rv['package']) ?></span></div>
      <small class="text-muted"><?= e(date('M j, Y g:i A', strtotime($rv['created_at']))) ?> · Booking #<?= (int)$rv['reservation_id'] ?></small>
    </div>
    <?= stars_html($rv['rating']) ?> <?php if (!$rv['is_visible']): ?><span class="pill cancelled">Hidden</span><?php endif; ?>
    <?php if ($rv['comment']): ?><p class="mt-2 mb-2"><?= nl2br(e($rv['comment'])) ?></p><?php else: ?><p class="text-muted fst-italic mt-2 mb-2">No comment.</p><?php endif; ?>
    <form method="post" class="vstack gap-2">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$rv['id'] ?>">
      <textarea class="form-control form-control-sm" name="reply" rows="2" maxlength="1000" placeholder="Write a public reply…"><?= e($rv['staff_reply'] ?? '') ?></textarea>
      <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm" name="action" value="reply">Save reply</button>
        <button class="btn btn-outline-primary btn-sm" name="action" value="toggle"><?= $rv['is_visible'] ? 'Hide from public page' : 'Show on public page' ?></button>
      </div>
    </form>
  </div></div>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="text-muted">No reviews yet. Customers can rate completed bookings from My Reviews.</p><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
