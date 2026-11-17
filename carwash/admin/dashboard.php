<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/scheduling.php';
require __DIR__ . '/../includes/reviews.php';
require_role(STAFF_ROLES);
$title = 'Dashboard';

$today = $pdo->query("SELECT
    COUNT(*) AS bookings,
    SUM(status='completed') AS completed,
    SUM(status IN ('pending','confirmed')) AS upcoming
  FROM reservations WHERE DATE(scheduled_at) = CURDATE() AND status <> 'cancelled'")->fetch();
$sales = $pdo->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(paid_at) = CURDATE()')->fetchColumn();
$ySales = $pdo->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(paid_at) = CURDATE() - INTERVAL 1 DAY')->fetchColumn();
$yBook = $pdo->query("SELECT COUNT(*) FROM reservations WHERE DATE(scheduled_at) = CURDATE() - INTERVAL 1 DAY AND status <> 'cancelled'")->fetchColumn();
$trend = function ($now, $prev): string {
    if ((float)$prev <= 0) return '<div class="stat-trend">No data yesterday</div>';
    $pct = round((($now - $prev) / $prev) * 100);
    return '<div class="stat-trend ' . ($pct >= 0 ? 'up' : 'down') . '">' . ($pct >= 0 ? '▲ +' : '▼ ') . $pct . '% vs yesterday</div>';
};
$nextUp = $pdo->query("SELECT r.id, r.vehicle_plate, r.scheduled_at, r.status, p.name AS package
    FROM reservations r JOIN packages p ON p.id = r.package_id
    WHERE r.status IN ('pending','confirmed') AND r.scheduled_at >= NOW() - INTERVAL 30 MINUTE AND DATE(r.scheduled_at) = CURDATE()
    ORDER BY r.scheduled_at LIMIT 5")->fetchAll();
$bayEnd = [];
foreach ($pdo->query("SELECT bay_id, end_at FROM reservations WHERE status = 'in_progress' AND bay_id IS NOT NULL") as $row) { $bayEnd[(int)$row['bay_id']] = $row['end_at']; }
$customers = $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
$snap = queue_snapshot($pdo);
$rev = review_stats($pdo, false);

// ---- Chart data (last 7 days for the bar charts, last 30 days for the pies)
$days = []; for ($i = 6; $i >= 0; $i--) { $days[date('Y-m-d', strtotime("-$i day"))] = date('D j', strtotime("-$i day")); }
$salesBy = array_fill_keys(array_keys($days), 0.0);
foreach ($pdo->query('SELECT DATE(paid_at) d, SUM(amount) t FROM payments WHERE paid_at >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(paid_at)') as $row) {
    if (isset($salesBy[$row['d']])) $salesBy[$row['d']] = (float)$row['t'];
}
$bookBy = array_fill_keys(array_keys($days), 0);
foreach ($pdo->query("SELECT DATE(scheduled_at) d, COUNT(*) n FROM reservations WHERE status <> 'cancelled' AND scheduled_at >= CURDATE() - INTERVAL 6 DAY AND DATE(scheduled_at) <= CURDATE() GROUP BY DATE(scheduled_at)") as $row) {
    if (isset($bookBy[$row['d']])) $bookBy[$row['d']] = (int)$row['n'];
}
$svc = $pdo->query("SELECT p.name, COUNT(*) n FROM reservations r JOIN packages p ON p.id = r.package_id
    WHERE r.status <> 'cancelled' AND r.scheduled_at >= CURDATE() - INTERVAL 30 DAY GROUP BY p.id ORDER BY n DESC LIMIT 6")->fetchAll();
$stat = $pdo->query("SELECT status, COUNT(*) n FROM reservations WHERE scheduled_at >= CURDATE() - INTERVAL 30 DAY GROUP BY status ORDER BY n DESC")->fetchAll();
$charts = [
    'labels' => array_values($days), 'sales' => array_values($salesBy), 'bookings' => array_values($bookBy),
    'svcLabels' => array_column($svc, 'name'), 'svcData' => array_map('intval', array_column($svc, 'n')),
    'stLabels' => array_map(fn($r) => ucfirst(str_replace('_', ' ', $r['status'])), $stat), 'stData' => array_map('intval', array_column($stat, 'n')),
];

require __DIR__ . '/../includes/header.php';
?>
<?php page_head('Dashboard', 'Today\'s bookings, sales and bay activity at a glance.', '<a class="btn btn-outline-secondary btn-sm" href="' . url('admin/messages.php') . '">Messages</a><a class="btn btn-outline-secondary btn-sm" href="' . url('admin/walkin.php') . '">+ Walk-in</a><a class="btn btn-primary btn-sm" href="' . url('admin/reservations.php') . '">Manage reservations</a>'); ?>
<div class="stats">
  <div class="card stat shadow-sm"><div class="card-body"><span><?= (int)$today['bookings'] ?></span><small>Bookings today</small><?= $trend((int)$today['bookings'], $yBook) ?></div></div>
  <div class="card stat shadow-sm"><div class="card-body"><span><?= (int)$today['completed'] ?></span><small>Completed</small></div></div>
  <div class="card stat shadow-sm"><div class="card-body"><span><?= (int)$today['upcoming'] ?></span><small>Waiting</small></div></div>
  <div class="card stat shadow-sm"><div class="card-body"><span><?= peso($sales) ?></span><small>Sales today</small><?= $trend((float)$sales, (float)$ySales) ?></div></div>
  <div class="card stat shadow-sm"><div class="card-body"><span><?= (int)$customers ?></span><small>Customers</small></div></div>
  <a class="card stat shadow-sm text-decoration-none" href="<?= url('admin/reviews.php') ?>"><div class="card-body"><span><?= $rev['count'] ? number_format($rev['avg'], 1) . ' ★' : '—' ?></span><small>Rating · <?= (int)$rev['count'] ?> review<?= $rev['count'] === 1 ? '' : 's' ?></small></div></a>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-body"><h3 class="h6 text-uppercase chart-title">Sales · last 7 days</h3><div class="chart-box"><canvas id="chSales"></canvas></div></div></div></div>
  <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-body"><h3 class="h6 text-uppercase chart-title">Bookings per day · last 7 days</h3><div class="chart-box"><canvas id="chBookings"></canvas></div></div></div></div>
  <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-body"><h3 class="h6 text-uppercase chart-title">Popular services · last 30 days</h3><div class="chart-box"><?php if ($charts['svcData']): ?><canvas id="chServices"></canvas><?php else: ?><div class="empty-state"><strong>No bookings yet</strong>Charts appear after the first booking.</div><?php endif; ?></div></div></div></div>
  <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-body"><h3 class="h6 text-uppercase chart-title">Booking status · last 30 days</h3><div class="chart-box"><?php if ($charts['stData']): ?><canvas id="chStatus"></canvas><?php else: ?><div class="empty-state"><strong>No bookings yet</strong>Charts appear after the first booking.</div><?php endif; ?></div></div></div></div>
</div>

<h2 class="h4">Next up today</h2>
<div class="card mb-4"><div class="card-body">
<?php if ($nextUp): ?>
  <ul class="next-up">
  <?php foreach ($nextUp as $n): ?>
    <li><span class="nu-time"><?= e(date('g:i A', strtotime($n['scheduled_at']))) ?></span>
      <span class="nu-main"><?= e($n['vehicle_plate']) ?><small><?= e($n['package']) ?></small></span>
      <span class="pill <?= e($n['status']) ?>"><?= e(str_replace('_', ' ', $n['status'])) ?></span></li>
  <?php endforeach; ?>
  </ul>
<?php else: ?>
  <div class="empty-state"><strong>No more bookings today</strong>Add a walk-in to fill the gap.<div class="mt-2"><a class="btn btn-outline-secondary btn-sm" href="<?= url('admin/walkin.php') ?>">+ Add walk-in</a></div></div>
<?php endif; ?>
</div></div>

<h2 class="h4">Bays · (<?= $snap['busy_bays'] ?>/<?= $snap['total_bays'] ?> busy · est. wait <?= $snap['est_wait_min'] ?> min)</h2>
<div class="grid">
  <?php foreach ($snap['bays'] as $b): ?>
    <div class="card bay shadow-sm hover-lift <?= $b['occupied_by'] ? 'busy' : 'free' ?>"><div class="card-body">
      <h5 class="card-title"><?= e($b['name']) ?></h5>
      <p class="mb-0"><?= $b['occupied_by'] ? e($b['occupied_by']) : 'Available' ?></p>
      <?php if ($b['occupied_by'] && isset($bayEnd[(int)$b['id']])): $left = (int)ceil((strtotime($bayEnd[(int)$b['id']]) - time()) / 60); ?>
        <span class="bay-timer"><?= $left > 0 ? $left . ' min left' : 'Finishing up' ?></span>
      <?php endif; ?>
    </div></div>
  <?php endforeach; ?>
</div>


<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (!window.Chart) return;
  const d = <?= json_encode($charts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const red = '#2f6b4a', palette = ['#1f2a24', '#2f6b4a', '#f2b84b', '#9b1c3d', '#6f7770', '#cfc8b8'];
  Chart.defaults.font.family = "Inter, system-ui, sans-serif";
  const opts = { responsive: true, maintainAspectRatio: false };
  const bar = (id, label, data, money) => { const el = document.getElementById(id); if (!el) return;
    new Chart(el, { type: 'bar', data: { labels: d.labels, datasets: [{ label, data, backgroundColor: red, borderRadius: 6 }] },
      options: { ...opts, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => money ? '₱' + Number(c.parsed.y).toLocaleString() : c.parsed.y } } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0, callback: v => money ? '₱' + v.toLocaleString() : v } }, x: { grid: { display: false } } } } }); };
  bar('chSales', 'Sales', d.sales, true);
  bar('chBookings', 'Bookings', d.bookings, false);
  const pie = (id, labels, data) => { const el = document.getElementById(id); if (!el) return;
    new Chart(el, { type: 'pie', data: { labels, datasets: [{ data, backgroundColor: palette, borderColor: '#fbfaf6', borderWidth: 2 }] },
      options: { ...opts, plugins: { legend: { position: 'right', labels: { boxWidth: 12 } } } } }); };
  pie('chServices', d.svcLabels, d.svcData);
  pie('chStatus', d.stLabels, d.stData);
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
