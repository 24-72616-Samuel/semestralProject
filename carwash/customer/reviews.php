<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/reviews.php';
$user = require_role(['customer']);
$title = 'My reviews';

// Reservation being rated (from the "Rate" button on My Bookings)
$rid = (int)($_GET['reservation'] ?? $_POST['reservation_id'] ?? 0);
$target = null;
if ($rid) {
    $st = $pdo->prepare("SELECT r.id, r.scheduled_at, p.name AS package, rv.id AS review_id
        FROM reservations r JOIN packages p ON p.id = r.package_id
        LEFT JOIN reviews rv ON rv.reservation_id = r.id
        WHERE r.id = ? AND r.user_id = ? AND r.status = 'completed'");
    $st->execute([$rid, $user['id']]);
    $target = $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $rating = clean_rating($_POST['rating'] ?? 0);
    $comment = trim((string)($_POST['comment'] ?? ''));
    if (!$target) {
        flash('error', 'You can only review your own completed bookings.');
    } elseif ($target['review_id']) {
        flash('error', 'You already reviewed this booking.');
    } elseif (!$rating) {
        flash('error', 'Please choose a star rating from 1 to 5.');
        redirect('customer/reviews.php?reservation=' . $rid);
    } elseif (mb_strlen($comment) > 1000) {
        flash('error', 'Please keep your comment under 1000 characters.');
        redirect('customer/reviews.php?reservation=' . $rid);
    } else {
        $pkg = $pdo->prepare('SELECT package_id FROM reservations WHERE id = ?');
        $pkg->execute([$rid]);
        try {
            $pdo->prepare('INSERT INTO reviews (reservation_id, user_id, package_id, rating, comment) VALUES (?,?,?,?,?)')
                ->execute([$rid, $user['id'], (int)$pkg->fetchColumn(), $rating, $comment === '' ? null : $comment]);
            flash('success', 'Thank you for your feedback!');
        } catch (PDOException $ex) {
            flash('error', 'You already reviewed this booking.');
        }
    }
    redirect('customer/reviews.php');
}

// Completed bookings still waiting for a review + the customer's past reviews
$st = $pdo->prepare("SELECT r.id, r.scheduled_at, p.name AS package FROM reservations r
    JOIN packages p ON p.id = r.package_id
    WHERE r.user_id = ? AND r.status = 'completed' AND NOT EXISTS (SELECT 1 FROM reviews rv WHERE rv.reservation_id = r.id)
    ORDER BY r.scheduled_at DESC");
$st->execute([$user['id']]);
$pending = $st->fetchAll();

$st = $pdo->prepare("SELECT rv.*, p.name AS package FROM reviews rv JOIN packages p ON p.id = rv.package_id
    WHERE rv.user_id = ? ORDER BY rv.id DESC");
$st->execute([$user['id']]);
$mine = $st->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<?php page_head('My reviews', 'Rate your completed washes and see your past feedback.'); ?>
<p class="text-muted">Tell us how your wash went. Your rating helps other customers and helps the shop improve.</p>

<?php if ($target && !$target['review_id']): ?>
<form method="post" class="card card-body shadow-sm vstack gap-3 mb-4" style="max-width:640px">
  <h2 class="h5 mb-0">Rate: <?= e($target['package']) ?> <small class="text-muted fw-normal">· <?= e(date('M j, Y', strtotime($target['scheduled_at']))) ?></small></h2>
  <?= csrf_field() ?><input type="hidden" name="reservation_id" value="<?= (int)$target['id'] ?>">
  <fieldset>
    <legend class="form-label fs-6">Your rating</legend>
    <div class="star-input">
      <?php for ($i = 5; $i >= 1; $i--): ?>
        <input type="radio" name="rating" id="rate<?= $i ?>" value="<?= $i ?>" required>
        <label for="rate<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>"><?= icon('star-fill') ?><span class="visually-hidden"><?= $i ?> star<?= $i > 1 ? 's' : '' ?></span></label>
      <?php endfor; ?>
    </div>
  </fieldset>
  <label class="form-label w-100">Comment <span class="text-muted">(optional)</span>
    <textarea class="form-control" name="comment" rows="4" maxlength="1000" placeholder="What did you like? What could we do better?"></textarea></label>
  <div class="d-flex gap-2"><button class="btn btn-primary">Submit review</button><a class="btn btn-outline-primary" href="<?= url('customer/reviews.php') ?>">Cancel</a></div>
</form>
<?php endif; ?>

<?php if ($pending): ?>
<h2 class="h5">Waiting for your feedback</h2>
<div class="table-responsive card shadow-sm mb-4"><table class="table align-middle mb-0">
  <tbody>
  <?php foreach ($pending as $r): ?>
    <tr><td><?= e(date('M j, Y g:i A', strtotime($r['scheduled_at']))) ?></td><td><?= e($r['package']) ?></td>
        <td class="text-end"><a class="btn btn-primary btn-sm" href="<?= url('customer/reviews.php?reservation=' . $r['id']) ?>">Rate this wash</a></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<h2 class="h5">Your past reviews</h2>
<?php foreach ($mine as $rv): ?>
  <div class="card shadow-sm mb-3" style="max-width:760px"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-2">
      <strong><?= e($rv['package']) ?></strong>
      <small class="text-muted"><?= e(date('M j, Y', strtotime($rv['created_at']))) ?><?= $rv['is_visible'] ? '' : ' · hidden by the shop' ?></small>
    </div>
    <?= stars_html($rv['rating']) ?>
    <?php if ($rv['comment']): ?><p class="mt-2 mb-0"><?= nl2br(e($rv['comment'])) ?></p><?php endif; ?>
    <?php if ($rv['staff_reply']): ?><div class="review-reply mt-3"><strong>Reply from Eayila Carwash</strong><br><?= nl2br(e($rv['staff_reply'])) ?></div><?php endif; ?>
  </div></div>
<?php endforeach; ?>
<?php if (!$mine): ?><p class="text-muted">No reviews yet. Once a booking is completed you can rate it here.</p><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
