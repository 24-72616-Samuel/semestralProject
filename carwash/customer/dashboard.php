<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/catalog.php';
require __DIR__ . '/../includes/photos.php';
require __DIR__ . '/../includes/reviews.php';
require __DIR__ . '/../includes/online_payments.php';
require __DIR__ . '/../includes/scheduling.php';
$user = require_role(['customer']);
$title = 'My bookings';

// Cancel (only own bookings that haven't started)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id'])) {
    csrf_check();
    $rid = (int)$_POST['cancel_id'];
    $st = $pdo->prepare("UPDATE reservations SET status='cancelled'
        WHERE id = ? AND user_id = ? AND status IN ('pending','confirmed')");
    $st->execute([$rid, $user['id']]);
    if ($st->rowCount()) {
        $refund = online_release_on_cancel($pdo, $rid);
        notify($pdo, $user['id'], 'Your booking was cancelled.' . ($refund > 0 ? ' Your online payment of ' . peso($refund) . ' will be returned by the shop.' : ''), 'email');
        flash('success', 'Booking cancelled.' . ($refund > 0 ? ' The shop will refund your online payment of ' . peso($refund) . '.' : ''));
    }
    redirect('customer/dashboard.php');
}

// Send an online (GCash) payment for one of my bookings: deposit or full price + reference number (+ optional screenshot)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('error', 'Your screenshot is too large to upload. Try a smaller one, or just type the reference number.');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_id'])) {
    csrf_check();
    $rid = (int)$_POST['pay_id'];
    $st = $pdo->prepare("SELECT * FROM reservations WHERE id = ? AND user_id = ? AND status IN ('pending','confirmed')");
    $st->execute([$rid, $user['id']]);
    $rv = $st->fetch();
    $ref = strtoupper(trim($_POST['reference_no'] ?? ''));
    $kind = ($_POST['kind'] ?? '') === 'deposit' && online_deposit_allowed() ? 'deposit' : 'full';
    if (!$rv || $rv['vehicle_type'] === null) {
        flash('error', 'You can pay once the shop has set your vehicle size and price.');
    } elseif (online_has_pending($pdo, $rid) || online_verified_total($pdo, $rid) > 0) {
        flash('error', 'You already sent a payment for this booking.');
    } elseif (!valid_reference($ref)) {
        flash('error', 'Enter the GCash reference number (6-30 letters/numbers).');
    } elseif (reference_in_use($pdo, $ref)) {
        flash('error', 'That reference number was already used.');
    } else {
        [$files, $err] = photos_save($_FILES['proof'] ?? [], false, 1);
        if ($err) {
            flash('error', $err);
        } else {
            $total = (float)$rv['total_price'];
            $amount = $kind === 'deposit' ? online_deposit_amount($total) : round($total, 2);
            $pdo->prepare('INSERT INTO online_payments (reservation_id, kind, amount, reference_no, proof_file) VALUES (?,?,?,?,?)')
                ->execute([$rid, $kind, $amount, $ref, $files[0] ?? null]);
            notify($pdo, $user['id'], 'We received your GCash payment of ' . peso($amount) . ' (ref ' . $ref . '). The shop will verify it shortly.', 'app');
            flash('success', 'Payment sent (' . peso($amount) . '). The shop will verify it and confirm your booking.');
        }
    }
    redirect('customer/dashboard.php');
}

$st = $pdo->prepare("SELECT r.*, p.name AS package, b.name AS bay, pay.id AS paid, rv.rating AS my_rating
    FROM reservations r
    JOIN packages p ON p.id = r.package_id
    LEFT JOIN bays b ON b.id = r.bay_id
    LEFT JOIN payments pay ON pay.reservation_id = r.id
    LEFT JOIN reviews rv ON rv.reservation_id = r.id
    WHERE r.user_id = ? ORDER BY r.scheduled_at DESC");
$st->execute([$user['id']]);
$rows = $st->fetchAll();

$photos = photos_for($pdo, array_column($rows, 'id'));
$online = online_for($pdo, array_column($rows, 'id'));

$st = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 5');
$st->execute([$user['id']]);
$notes = $st->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<?php page_head('My bookings', 'Track your washes, pay online and download receipts.', '<a class="btn btn-primary btn-sm" href="' . url('customer/book.php') . '">+ Book a wash</a>'); ?>
<?php $promoNow = $pdo->query("SELECT title, discount_percent, end_date FROM promos WHERE is_active = 1 AND CURDATE() BETWEEN start_date AND end_date ORDER BY discount_percent DESC LIMIT 1")->fetch(); ?>
<?php if ($promoNow): ?>
<div class="promo-banner"><b><?= rtrim(rtrim(number_format((float)$promoNow['discount_percent'], 2), '0'), '.') ?>% off</b><span><?= e($promoNow['title']) ?> · until <?= e(date('M j', strtotime($promoNow['end_date']))) ?></span><a class="btn btn-primary btn-sm" href="<?= url('customer/book.php') ?>">Book now</a></div>
<?php endif; ?>
<p><a class="btn btn-primary" href="<?= url('customer/book.php') ?>">+ New booking</a></p>
<div class="table-responsive card shadow-sm mb-4"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>When</th><th>Package</th><th>Plate</th><th>Bay</th><th>Total</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e(date('M j, Y g:i A', strtotime($r['scheduled_at']))) ?></td>
      <td><?= e($r['package']) ?></td>
      <td>
        <?php if (!empty($photos[(int)$r['id']])): ?><div class="vthumbs"><?php foreach ($photos[(int)$r['id']] as $phId): ?><a href="<?= url('photo.php?id=' . $phId) ?>" target="_blank" rel="noopener"><img src="<?= url('photo.php?id=' . $phId) ?>" alt="Your vehicle photo" loading="lazy"></a><?php endforeach; ?></div><?php endif; ?>
        <?= e($r['vehicle_plate']) ?><br>
        <small class="text-muted"><?= $r['vehicle_type'] === null ? 'Size: the shop is checking your photos' : e(vehicle_label($r['vehicle_type'])) ?></small></td>
      <td><?= e($r['bay'] ?? '—') ?></td>
      <td><?php if ($r['vehicle_type'] === null): ?>from <?= peso($r['total_price']) ?><br><small class="text-muted">final price after size check</small><?php else: ?><?= peso($r['total_price']) ?><?php endif; ?>
        <?php $ops = $online[(int)$r['id']] ?? []; $op = $ops[0] ?? null; if ($op): ?>
          <div class="mt-1"><?= online_pill($op) ?></div>
          <small class="text-muted d-block"><?= e(ucfirst($op['kind'])) ?> <?= peso($op['amount']) ?> · ref <?= e($op['reference_no']) ?></small>
          <?php if ($op['status'] === 'verified' && $r['status'] !== 'completed'): ?><small class="text-muted d-block">Balance at the shop: <?= peso(max(0, (float)$r['total_price'] - (float)$op['amount'])) ?></small><?php endif; ?>
          <?php if ($op['status'] === 'rejected' && $op['note']): ?><small class="text-danger d-block"><?= e($op['note']) ?></small><?php endif; ?>
          <?php if ($op['status'] === 'refund_due'): ?><small class="text-muted d-block">The shop will refund this to your GCash.</small><?php endif; ?>
        <?php endif; ?></td>
      <td><span class="pill <?= e($r['status']) ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span></td>
      <td>
        <?php if (in_array($r['status'], ['pending', 'confirmed'], true)): ?>
          <div class="row-actions">
            <?php if (can_reschedule($r)): ?><a class="btn btn-outline-primary btn-sm" href="<?= url('customer/reschedule.php?id=' . (int)$r['id']) ?>">Reschedule</a><?php endif; ?>
            <form method="post" data-confirm="Cancel this booking?">
              <?= csrf_field() ?><input type="hidden" name="cancel_id" value="<?= $r['id'] ?>">
              <button class="btn btn-danger btn-sm">Cancel</button>
            </form>
          </div>
        <?php else: ?>
          <?php if ($r['paid']): ?><a class="btn btn-primary btn-sm" href="<?= url('receipt.php?id=' . $r['id']) ?>">Receipt</a><?php endif; ?>
          <?php if ($r['status'] === 'completed'): ?>
            <?php if ($r['my_rating']): ?><div class="mt-1"><?= stars_html($r['my_rating']) ?></div>
            <?php else: ?><a class="btn btn-outline-primary btn-sm" href="<?= url('customer/reviews.php?reservation=' . $r['id']) ?>">★ Rate</a><?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php
      $ops = $online[(int)$r['id']] ?? [];
      $live = array_filter($ops, fn($o) => in_array($o['status'], ['pending', 'verified'], true));
      if (in_array($r['status'], ['pending', 'confirmed'], true) && !$live):
        if ($r['vehicle_type'] === null): if ($r['payment_mode'] === 'online'): ?>
    <tr class="table-light"><td colspan="7"><small class="text-muted">You chose to pay online. You can pay here once the shop checks your photos and sets your vehicle size.</small></td></tr>
        <?php endif; else:
          $tot = (float)$r['total_price']; $dep = online_deposit_amount($tot); ?>
    <tr class="table-light"><td colspan="7">
      <details <?= $r['payment_mode'] === 'online' ? 'open' : '' ?>>
        <summary class="fw-semibold">Pay online with GCash<?= $r['payment_mode'] === 'online' ? ' <span class="badge bg-warning text-dark">needed to confirm your booking</span>' : '' ?></summary>
        <form method="post" enctype="multipart/form-data" class="vstack gap-2 mt-2" style="max-width:520px">
          <?= csrf_field() ?><input type="hidden" name="pay_id" value="<?= (int)$r['id'] ?>">
          <div class="small">1. Send the amount through GCash to <strong><?= e(SHOP_GCASH_NAME) ?></strong> · <strong><?= e(SHOP_GCASH_NUMBER) ?></strong>.<br>2. Type the GCash reference number below (a screenshot is optional but helps).</div>
          <?php if (is_file(__DIR__ . '/../assets/img/gcash-qr.png')): ?><img src="<?= url('assets/img/gcash-qr.png') ?>" alt="GCash QR code" style="max-width:180px">
          <?php endif; ?>
          <?php if (online_deposit_allowed()): ?>
            <label class="form-check"><input class="form-check-input" type="radio" name="kind" value="deposit" checked> <span class="form-check-label">Deposit <?= (int)ONLINE_DEPOSIT_PERCENT ?>% — <strong><?= peso($dep) ?></strong> <small class="text-muted">(balance <?= peso($tot - $dep) ?> paid at the shop)</small></span></label>
            <label class="form-check"><input class="form-check-input" type="radio" name="kind" value="full"> <span class="form-check-label">Pay in full — <strong><?= peso($tot) ?></strong></span></label>
          <?php else: ?>
            <div class="small">Amount to pay: <strong><?= peso($tot) ?></strong> (full price)</div>
          <?php endif; ?>
          <input class="form-control" name="reference_no" required maxlength="30" placeholder="GCash reference number" autocomplete="off">
          <input class="form-control" type="file" name="proof[]" accept="image/*">
          <button class="btn btn-primary align-self-start btn-sm">Send payment</button>
        </form>
      </details>
    </td></tr>
        <?php endif;
      endif; ?>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7">No bookings yet.</td></tr><?php endif; ?>
  </tbody>
</table></div>

<h2>Notifications</h2>
<ul class="plain">
  <?php foreach ($notes as $n): ?>
    <li><small><?= e($n['created_at']) ?> · <?= e($n['channel']) ?></small> — <?= e($n['message']) ?></li>
  <?php endforeach; ?>
  <?php if (!$notes): ?><li>No notifications.</li><?php endif; ?>
</ul>
<?php require __DIR__ . '/../includes/footer.php'; ?>
