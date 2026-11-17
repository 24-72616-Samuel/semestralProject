<?php
// Reservation list + actions (used by admin/reservations.php).
$filter = is_string($_GET['filter'] ?? null) ? $_GET['filter'] : 'today';
require_once __DIR__ . '/photos.php';
require_once __DIR__ . '/online_payments.php';
$where = $filter === 'all' ? '1=1' : ($filter === 'verify' ? "r.id IN (SELECT reservation_id FROM online_payments WHERE status = 'pending')" : ($filter === 'refund' ? "r.id IN (SELECT reservation_id FROM online_payments WHERE status = 'refund_due')" : ($filter === 'pending' ? "r.status = 'pending'" : ($filter === 'size' ? "r.status IN ('pending','confirmed') AND r.vehicle_type IS NULL" : 'DATE(r.scheduled_at) = CURDATE()'))));
$verifyCount = (int)$pdo->query("SELECT COUNT(DISTINCT reservation_id) FROM online_payments WHERE status = 'pending'")->fetchColumn();
$refundCount = (int)$pdo->query("SELECT COUNT(DISTINCT reservation_id) FROM online_payments WHERE status = 'refund_due'")->fetchColumn();
$needSizeCount = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status IN ('pending','confirmed') AND vehicle_type IS NULL")->fetchColumn();
$pkgs = load_packages($pdo, false);
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();
$activeBays = $pdo->query('SELECT id, name FROM bays WHERE is_active = 1 ORDER BY id')->fetchAll();
$rows = $pdo->query("SELECT r.*, p.name AS package, COALESCE(u.name, r.customer_name, 'Walk-in customer') AS customer, COALESCE(u.phone, r.customer_phone) AS phone, b.name AS bay, pay.id AS paid
    FROM reservations r
    JOIN packages p ON p.id = r.package_id
    LEFT JOIN users u ON u.id = r.user_id
    LEFT JOIN bays b ON b.id = r.bay_id
    LEFT JOIN payments pay ON pay.reservation_id = r.id
    WHERE $where ORDER BY r.scheduled_at DESC LIMIT 200")->fetchAll();
$liveRes = [];   // live bookings by bay, loaded once (instead of one query per bay per row)
foreach ($pdo->query("SELECT id, bay_id, scheduled_at, end_at FROM reservations WHERE status IN ('pending','confirmed','in_progress')") as $lr) $liveRes[(int)$lr['bay_id']][] = $lr;
$bayFree = function (int $bayId, string $start, string $end, int $exId) use ($liveRes): bool {
    foreach ($liveRes[$bayId] ?? [] as $x) if ((int)$x['id'] !== $exId && $x['scheduled_at'] < $end && $x['end_at'] > $start) return false;
    return true;
};
$photos = photos_for($pdo, array_column($rows, 'id'));
$online = online_for($pdo, array_column($rows, 'id'));
?>
<?php page_head('Reservations', 'Accept bookings, assign bays, verify GCash payments and complete washes in one ledger.', '<a class="btn btn-primary btn-sm" href="' . url('admin/walkin.php') . '">+ Walk-in</a>', 'reservations'); ?>
<div class="stats">
  <div class="card stat"><div class="card-body"><span><?= $pendingCount ?></span><small>Bookings to accept</small></div></div>
  <div class="card stat"><div class="card-body"><span><?= $verifyCount ?></span><small>Pending verification queue</small></div></div>
  <div class="card stat"><div class="card-body"><span><?= $refundCount ?></span><small>Refunds due</small></div></div>
  <div class="card stat"><div class="card-body"><span><?= $needSizeCount ?></span><small>Vehicles needing size</small></div></div>
</div>
<?php $tab = fn(string $f, string $label, int $n = 0) => '<a class="' . ($filter === $f ? 'active' : '') . '" href="?filter=' . $f . '#reservations">' . $label . ($n ? ' <span class="tab-count">' . $n . '</span>' : '') . '</a>'; ?>
<div class="ledger-tabs">
  <?= $tab('today', 'Today') ?>
  <?= $tab('pending', 'To accept', $pendingCount) ?>
  <?= $tab('size', 'Needs size', $needSizeCount) ?>
  <?= $tab('verify', 'Verify payments', $verifyCount) ?>
  <?= $tab('refund', 'Refunds', $refundCount) ?>
  <?= $tab('all', 'All') ?>
</div>
<div class="table-responsive card shadow-sm mb-4"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>When</th><th>Customer</th><th>Plate</th><th>Package</th><th>Bay</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e(date('M j g:i A', strtotime($r['scheduled_at']))) ?></td>
      <td><?= e($r['customer']) ?><?= $r['source'] === 'walkin' ? ' <span class="badge bg-secondary">Walk-in</span>' : '' ?><br><small><?= e($r['phone']) ?></small></td>
      <td>
        <?php if (!empty($photos[(int)$r['id']])): ?><div class="vthumbs"><?php foreach ($photos[(int)$r['id']] as $phId): ?><a href="<?= url('photo.php?id=' . $phId) ?>" target="_blank" rel="noopener" title="Open photo"><img src="<?= url('photo.php?id=' . $phId) ?>" alt="Vehicle photo" loading="lazy"></a><?php endforeach; ?></div><?php endif; ?>
        <?= e($r['vehicle_plate']) ?><br>
        <?php if ($r['vehicle_type'] === null): ?><span class="badge bg-warning text-dark">Needs size</span><?php else: ?><small><?= e(vehicle_label($r['vehicle_type'])) ?></small><?php endif; ?></td>
      <td><?= e($r['package']) ?></td>
      <td><?= e($r['bay'] ?? '—') ?></td>
      <td><?= $r['vehicle_type'] === null ? 'from ' : '' ?><?= peso($r['total_price']) ?>
        <?php $ops = $online[(int)$r['id']] ?? []; $op = $ops[0] ?? null;
        if ($op): ?><div class="mt-1"><?= online_pill($op) ?></div><small class="text-muted d-block"><?= e(ucfirst($op['kind'])) ?> <?= peso($op['amount']) ?></small>
        <?php elseif ($r['payment_mode'] === 'online' && in_array($r['status'], ['pending', 'confirmed'], true)): ?><div class="mt-1"><span class="badge bg-info text-dark">Will pay online</span></div><?php endif; ?></td>
      <td><span class="pill <?= e($r['status']) ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span></td>
      <td class="actions">
        <?php foreach ($ops as $op): if (!in_array($op['status'], ['pending', 'refund_due'], true)) continue; ?>
          <div class="small border rounded p-2 mb-2 bg-light">
            <div><strong><?= e(ONLINE_STATUS_LABELS[$op['status']]) ?>:</strong> GCash <?= peso($op['amount']) ?> (<?= e($op['kind']) ?>) · ref <strong><?= e($op['reference_no']) ?></strong>
              <?php if ($op['proof_file']): ?> · <a href="<?= url('proof.php?id=' . (int)$op['id']) ?>" target="_blank" rel="noopener">screenshot</a><?php endif; ?></div>
            <div class="text-muted"><?= $op['status'] === 'pending' ? 'Check your GCash for this amount and reference, then:' : 'The booking was cancelled. Send it back through GCash, then mark it refunded.' ?></div>
            <form method="post" class="d-flex flex-wrap gap-1 mt-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="pid" value="<?= (int)$op['id'] ?>">
              <input class="form-control form-control-sm w-auto" name="note" maxlength="200" placeholder="<?= $op['status'] === 'pending' ? 'Reason if rejecting' : 'Note (optional)' ?>">
              <?php if ($op['status'] === 'pending'): ?>
                <button name="action" value="verify_payment" class="btn btn-primary btn-sm">Confirm payment</button>
                <button name="action" value="reject_payment" class="btn btn-outline-danger btn-sm" data-confirm="Reject this payment?">Reject</button>
              <?php else: ?>
                <button name="action" value="refund_done" class="btn btn-primary btn-sm" data-confirm="Mark as refunded to the customer?">Refunded</button>
                <button name="action" value="refund_keep" class="btn btn-outline-danger btn-sm" data-confirm="Keep the money (no refund)?">Keep (no refund)</button>
              <?php endif; ?>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if (in_array($r['status'], ['pending', 'confirmed', 'in_progress'], true)):
            $pk = $pkgs[(int)$r['package_id']] ?? ['prices' => [], 'category' => '']; ?>
          <form method="post" class="mb-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
            <select class="form-select form-select-sm" name="size" aria-label="Vehicle size" required>
              <?php if ($r['vehicle_type'] === null): ?><option value="">Pick size…</option><?php endif; ?>
              <?php foreach (sizes_for($pk['category']) as $sKey => [$sLabel]): if (!isset($pk['prices'][$sKey])) continue; ?>
                <option value="<?= e($sKey) ?>" <?= $r['vehicle_type'] === $sKey ? 'selected' : '' ?>><?= e($sLabel) ?> — <?= peso($pk['prices'][$sKey]) ?></option>
              <?php endforeach; ?>
            </select>
            <button name="action" value="set_size" class="btn btn-outline-primary btn-sm"><?= $r['vehicle_type'] === null ? 'Set size' : 'Change size' ?></button></form>
          <form method="post" class="mb-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
            <select class="form-select form-select-sm" name="bay_id" aria-label="Change bay">
              <?php foreach ($activeBays as $b):
                  $free = $bayFree((int)$b['id'], $r['scheduled_at'], $r['end_at'], (int)$r['id']);
                  $cur = (int)$b['id'] === (int)$r['bay_id']; ?>
                <option value="<?= $b['id'] ?>" <?= $cur ? 'selected' : '' ?>><?= e($b['name']) ?><?= $cur ? ' (current)' : ($free ? '' : ' – in use now') ?></option>
              <?php endforeach; ?>
            </select>
            <button name="action" value="change_bay" class="btn btn-outline-primary btn-sm">Change bay</button></form>
        <?php endif; ?>
        <?php if ($r['status'] === 'pending'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
            <?php $needPay = $r['payment_mode'] === 'online' && !array_filter($ops, fn($o) => $o['status'] === 'verified'); ?>
            <button name="action" value="accept" class="btn btn-primary btn-sm" <?= $r['vehicle_type'] === null ? 'disabled title="Set the vehicle size first"' : ($needPay ? 'disabled title="Waiting for the online payment to be verified"' : '') ?>>Accept</button>
            <button name="action" value="cancel" class="btn btn-danger btn-sm" data-confirm="Decline this booking?">Decline</button></form>
        <?php elseif ($r['status'] === 'confirmed'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button name="action" value="start" class="btn btn-primary btn-sm">Start</button>
            <button name="action" value="cancel" class="btn btn-danger btn-sm" data-confirm="Cancel?">Cancel</button></form>
        <?php elseif ($r['status'] === 'in_progress'): ?>
          <?php $tot = round((float)$r['total_price'], 2);
                $pre = min(array_sum(array_map(fn($o) => $o['status'] === 'verified' ? (float)$o['amount'] : 0, $ops)), $tot);
                $bal = round($tot - $pre, 2);
                $hasPending = (bool)array_filter($ops, fn($o) => $o['status'] === 'pending'); ?>
          <form method="post" class="pay-form" data-balance="<?= $bal ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
            <div class="small mb-1">Total <?= peso($tot) ?><?php if ($pre > 0): ?> · paid online −<?= peso($pre) ?><?php endif; ?> · <strong>Balance <?= peso($bal) ?></strong></div>
            <?php if ($bal > 0): ?>
              <select class="form-select form-select-sm mb-1 pf-method" name="method"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="card">Card</option></select>
              <div class="pf-cash">
                <input class="form-control form-control-sm mb-1 pf-received" type="number" name="cash_received" min="0" step="0.01" placeholder="Cash received (₱)" value="<?= e($bal) ?>">
                <div class="small mb-1 pf-change"></div>
              </div>
              <input class="form-control form-control-sm mb-1 pf-ref" name="reference_no" maxlength="30" placeholder="Reference no." style="display:none" autocomplete="off">
            <?php else: ?><div class="small text-success mb-1">Fully paid online. Nothing to collect.</div><?php endif; ?>
            <button name="action" value="complete" class="btn btn-primary btn-sm" <?= $hasPending ? 'disabled title="Verify the online payment first"' : '' ?>>Complete & pay</button></form>
        <?php elseif ($r['paid']): ?>
          <a class="btn btn-outline-primary btn-sm" href="<?= url('receipt.php?id=' . $r['id']) ?>"><?= icon('printer') ?>Receipt</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8">Nothing here.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<script>
(function () {
  if (window.__payFormInit) return; window.__payFormInit = true;
  const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  document.querySelectorAll('.pay-form').forEach(f => {
    const bal = parseFloat(f.dataset.balance) || 0, m = f.querySelector('.pf-method');
    if (!m) return;
    const cash = f.querySelector('.pf-cash'), rec = f.querySelector('.pf-received'), chg = f.querySelector('.pf-change'), ref = f.querySelector('.pf-ref');
    const upd = () => {
      const isCash = m.value === 'cash';
      cash.style.display = isCash ? '' : 'none'; ref.style.display = isCash ? 'none' : '';
      ref.placeholder = m.value === 'gcash' ? 'GCash reference no. (required)' : 'Card reference no. (optional)';
      const diff = (parseFloat(rec.value) || 0) - bal;
      chg.textContent = !isCash ? '' : diff < -0.004 ? 'Short by ' + peso(-diff) : 'Change: ' + peso(Math.max(0, diff));
      chg.className = 'small mb-1 pf-change ' + (diff < -0.004 ? 'text-danger' : 'text-success fw-bold');
    };
    m.addEventListener('change', upd); rec.addEventListener('input', upd); upd();
  });
})();
</script>
