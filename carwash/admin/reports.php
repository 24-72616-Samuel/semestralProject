<?php
require __DIR__ . '/../includes/bootstrap.php';
require_role(STAFF_ROLES);
$title = 'Reports';

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

// CSV export of the sales report
if (isset($_GET['csv'])) {
    $st = $pdo->prepare("SELECT pay.receipt_no, pay.paid_at, COALESCE(u.name, r.customer_name, 'Walk-in customer') AS customer, p.name AS package, pay.method, pay.amount
        FROM payments pay JOIN reservations r ON r.id = pay.reservation_id
        LEFT JOIN users u ON u.id = r.user_id JOIN packages p ON p.id = r.package_id
        WHERE DATE(pay.paid_at) BETWEEN ? AND ? ORDER BY pay.paid_at");
    $st->execute([$from, $to]);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Receipt', 'Paid at', 'Customer', 'Package', 'Method', 'Amount']);
    foreach ($st as $row) fputcsv($out, array_map('csv_safe', $row));
    exit;
}

$st = $pdo->prepare('SELECT DATE(paid_at) AS day, COUNT(*) AS n, SUM(amount) AS total FROM payments
    WHERE DATE(paid_at) BETWEEN ? AND ? GROUP BY DATE(paid_at) ORDER BY day');
$st->execute([$from, $to]);
$daily = $st->fetchAll();

$st = $pdo->prepare("SELECT p.name, COUNT(*) AS n, SUM(pay.amount) AS total FROM payments pay
    JOIN reservations r ON r.id = pay.reservation_id JOIN packages p ON p.id = r.package_id
    WHERE DATE(pay.paid_at) BETWEEN ? AND ? GROUP BY p.id ORDER BY total DESC");
$st->execute([$from, $to]);
$byPackage = $st->fetchAll();

$st = $pdo->prepare("SELECT u.name, u.email, COUNT(*) AS visits, SUM(pay.amount) AS spent FROM payments pay
    JOIN reservations r ON r.id = pay.reservation_id JOIN users u ON u.id = r.user_id
    WHERE DATE(pay.paid_at) BETWEEN ? AND ? GROUP BY u.id ORDER BY spent DESC LIMIT 10");
$st->execute([$from, $to]);
$topCustomers = $st->fetchAll();

$grand = array_sum(array_column($daily, 'total'));
$max = max(1, ...array_map('floatval', array_column($daily, 'total') ?: [1]));

require __DIR__ . '/../includes/header.php';
?>
<?php page_head('Reports &amp; analytics', 'Sales and service history for the selected dates.'); ?>
<form class="inline-form d-flex flex-wrap gap-2 align-items-center mb-3" method="get">
  From <input class="form-control" type="date" name="from" value="<?= e($from) ?>"> To <input class="form-control" type="date" name="to" value="<?= e($to) ?>">
  <button class="btn btn-primary btn-sm">Apply</button>
  <a class="btn btn-outline-primary btn-sm" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&csv=1">Download CSV</a>
</form>
<div class="stats"><div class="card stat shadow-sm"><div class="card-body"><span><?= peso($grand) ?></span><small>Total sales</small></div></div>
  <div class="card stat shadow-sm"><div class="card-body"><span><?= array_sum(array_column($daily, 'n')) ?></span><small>Services completed</small></div></div></div>

<h2>Daily sales</h2>
<div class="card card-body shadow-sm mb-4">
  <?php foreach ($daily as $d): $w = round($d['total'] / $max * 100); ?>
    <div class="row align-items-center g-2 mb-2">
      <div class="col-4 col-md-2 small"><?= e($d['day']) ?></div>
      <div class="col-8 col-md-8"><div class="progress" style="height:16px"><div class="progress-bar" role="progressbar" style="width:<?= $w ?>%" aria-valuenow="<?= $w ?>" aria-valuemin="0" aria-valuemax="100"></div></div></div>
      <div class="col-12 col-md-2 text-md-end fw-semibold"><?= peso($d['total']) ?></div>
    </div>
  <?php endforeach; ?>
  <?php if (!$daily): ?><p class="mb-0 text-muted">No sales in this range.</p><?php endif; ?>
</div>

<h2>Service history by package</h2>
<div class="table-responsive card shadow-sm mb-4"><table class="table table-hover align-middle mb-0"><thead><tr><th>Package</th><th>Count</th><th>Sales</th></tr></thead><tbody>
  <?php foreach ($byPackage as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= (int)$r['n'] ?></td><td><?= peso($r['total']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>

<h2>Top customers</h2>
<div class="table-responsive card shadow-sm mb-4"><table class="table table-hover align-middle mb-0"><thead><tr><th>Customer</th><th>Email</th><th>Visits</th><th>Spent</th></tr></thead><tbody>
  <?php foreach ($topCustomers as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['email']) ?></td><td><?= (int)$r['visits'] ?></td><td><?= peso($r['spent']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
