<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/catalog.php';
$user = require_login();
$title = 'Receipt';

$st = $pdo->prepare("SELECT r.*, p.name AS package, COALESCE(u.name, r.customer_name, 'Walk-in customer') AS customer, pay.amount, pay.prepaid, pay.cash_received, pay.change_given, pay.reference_no, pay.method, pay.receipt_no, pay.paid_at
    FROM reservations r
    JOIN packages p ON p.id = r.package_id
    LEFT JOIN users u ON u.id = r.user_id
    JOIN payments pay ON pay.reservation_id = r.id
    WHERE r.id = ?");
$st->execute([(int)($_GET['id'] ?? 0)]);
$r = $st->fetch();
$onlineRefs = [];
if ($r && (float)$r['prepaid'] > 0) {
    $q = $pdo->prepare("SELECT reference_no FROM online_payments WHERE reservation_id = ? AND status = 'verified' ORDER BY id");
    $q->execute([$r['id']]);
    $onlineRefs = $q->fetchAll(PDO::FETCH_COLUMN);
}

// Customers can only see their own receipts; staff can see all.
if (!$r || (!is_staff() && (int)$r['user_id'] !== $user['id'])) { http_response_code(404); exit('Receipt not found.'); }

require __DIR__ . '/includes/header.php';
?>
<div class="card receipt">
  <h2><?= e(SHOP_NAME) ?></h2>
  <p>Receipt <strong><?= e($r['receipt_no']) ?></strong> · <?= e($r['paid_at']) ?></p>
  <hr>
  <p>Customer: <?= e($r['customer']) ?><br>
     Vehicle: <?= e($r['vehicle_plate']) ?> (<?= e(vehicle_label($r['vehicle_type'])) ?>)<br>
     Service: <?= e($r['package']) ?><br>
     Date: <?= e($r['scheduled_at']) ?></p>
  <?php $prepaid = (float)$r['prepaid']; $balance = round((float)$r['amount'] - $prepaid, 2); ?>
  <p class="price">Total: <?= peso($r['amount']) ?><?= $prepaid <= 0 ? ' (' . e(strtoupper($r['method'])) . ')' : '' ?></p>
  <?php if ($prepaid > 0): ?>
    <p>Paid online (GCash<?= $onlineRefs ? ', ref ' . e(implode(', ', $onlineRefs)) : '' ?>): <?= peso($prepaid) ?><br>
       Balance paid at the shop: <?= peso($balance) ?><?= $balance > 0 ? ' (' . e(strtoupper($r['method'])) . ')' : '' ?></p>
  <?php endif; ?>
  <?php if ($r['reference_no']): ?><p>Reference no.: <?= e($r['reference_no']) ?></p><?php endif; ?>
  <?php if ($r['cash_received'] !== null): ?><p>Cash received: <?= peso($r['cash_received']) ?> · Change: <?= peso($r['change_given'] ?? 0) ?></p><?php endif; ?>
  <button class="btn btn-primary" onclick="window.print()">Print</button>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
