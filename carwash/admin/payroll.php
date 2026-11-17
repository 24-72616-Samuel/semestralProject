<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/payroll.php';
$me = require_role(ADMIN_ROLES);   // pay information is for owner/admin only
$title = 'Payroll';

$valid = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$validMonth = fn($m) => is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m);
$tab = in_array($_GET['tab'] ?? '', ['attendance', 'payout', 'report', 'employees'], true) ? $_GET['tab'] : 'attendance';
$today = date('Y-m-d');

// ---- POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'attendance') {
        $date = $_POST['date'] ?? '';
        if (!$valid($date) || $date > $today) {
            flash('error', 'Pick a valid date (not in the future).');
            redirect('admin/payroll.php?tab=attendance');
        }
        if (date_is_paid($pdo, $date)) {
            flash('error', 'That date is already in a paid payroll period. Undo the payout first if you really need to change attendance.');
            redirect('admin/payroll.php?tab=attendance&date=' . $date);
        }
        $posted = (array)($_POST['present'] ?? []);
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM attendance WHERE work_date = ?')->execute([$date]);
        $ins = $pdo->prepare('INSERT INTO attendance (employee_id, work_date, shift, marked_by) SELECT id, ?, ?, ? FROM employees WHERE id = ?');
        foreach (array_keys(SHIFTS) as $shift) {
            $ids = array_map('intval', (array)($posted[$shift] ?? []));
            foreach (array_unique($ids) as $id) $ins->execute([$date, $shift, $me['id'], $id]);
        }
        $pdo->commit();
        flash('success', 'Attendance saved for ' . date('M j, Y', strtotime($date)) . '.');
        redirect('admin/payroll.php?tab=attendance&date=' . $date);
    } elseif ($action === 'add_employee') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) flash('error', 'Enter the employee name.');
        else { $pdo->prepare('INSERT INTO employees (name) VALUES (?)')->execute([$name]); flash('success', 'Employee added.'); }
        redirect('admin/payroll.php?tab=employees');
    } elseif ($action === 'toggle_employee') {
        $pdo->prepare('UPDATE employees SET is_active = 1 - is_active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        redirect('admin/payroll.php?tab=employees');
    } elseif ($action === 'rename_employee') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '' && mb_strlen($name) <= 100) $pdo->prepare('UPDATE employees SET name = ? WHERE id = ?')->execute([$name, (int)($_POST['id'] ?? 0)]);
        redirect('admin/payroll.php?tab=employees');
    } elseif ($action === 'mark_paid' || $action === 'undo_paid') {
        $month = $_POST['month'] ?? '';
        $half = ($_POST['half'] ?? '') === 'second' ? 'second' : 'first';
        if (!$validMonth($month)) { flash('error', 'Pick a valid month.'); redirect('admin/payroll.php?tab=payout'); }
        $per = pay_periods($month)[$half];
        $back = 'admin/payroll.php?tab=payout&month=' . $month;
        if ($action === 'undo_paid') {
            $pdo->prepare('DELETE FROM payouts WHERE period_start = ? AND period_end = ?')->execute([$per['from'], $per['to']]);
            flash('success', $per['label'] . ' reopened. You can edit attendance for those dates again.');
            redirect($back);
        }
        if ($per['to'] > $today) { flash('error', 'That pay period is not over yet (' . date('M j', strtotime($per['to'])) . '). You can release it on or after that date.'); redirect($back); }
        if (period_payouts($pdo, $per['from'], $per['to'])) { flash('error', 'That period is already marked as paid.'); redirect($back); }
        $pr = compute_payroll($pdo, $per['from'], $per['to']);
        $ins = $pdo->prepare('INSERT INTO payouts (employee_id, period_start, period_end, amount, paid_by) VALUES (?,?,?,?,?)');
        $n = 0;
        $pdo->beginTransaction();
        foreach ($pr['people'] as $id => $p) {
            if ($p['centavos'] <= 0) continue;
            $ins->execute([$id, $per['from'], $per['to'], number_format($p['centavos'] / 100, 2, '.', ''), $me['id']]);
            $n++;
        }
        $pdo->commit();
        if (!$n) flash('error', 'Nobody earned anything in that period, so nothing was recorded.');
        else {
            flash('success', $per['label'] . ' marked as paid for ' . $n . ' employee' . ($n > 1 ? 's' : '') . ' (' . peso_c($pr['totals']['paid']) . ').');
            if ($pr['totals']['unassigned'] > 0) flash('error', peso_c($pr['totals']['unassigned']) . ' of staff pool was not paid out because nobody was marked present for some shifts with sales.');
        }
        redirect($back);
    }
}

$from = $valid($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = $valid($_GET['to'] ?? '') ? $_GET['to'] : $today;
if ($to < $from) [$from, $to] = [$to, $from];
$month = $validMonth($_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');

// ---- CSV exports
if (isset($_GET['csv']) && $tab === 'payout') {
    $periods = pay_periods($month);
    $p1 = compute_payroll($pdo, $periods['first']['from'], $periods['first']['to']);
    $p2 = compute_payroll($pdo, $periods['second']['from'], $periods['second']['to']);
    $paid1 = period_payouts($pdo, $periods['first']['from'], $periods['first']['to']);
    $paid2 = period_payouts($pdo, $periods['second']['from'], $periods['second']['to']);
    $ids = array_unique(array_merge(array_keys($p1['people']), array_keys($p2['people'])));
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payout_' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee', $periods['first']['label'] . ' (PHP)', '15th status', $periods['second']['label'] . ' (PHP)', 'Month-end status', 'Month total (PHP)']);
    foreach ($ids as $id) {
        $a = $p1['people'][$id]['centavos'] ?? 0; $b = $p2['people'][$id]['centavos'] ?? 0;
        fputcsv($out, [csv_safe($p1['people'][$id]['name'] ?? $p2['people'][$id]['name']), number_format($a / 100, 2, '.', ''), isset($paid1[$id]) ? 'Paid' : 'Unpaid',
            number_format($b / 100, 2, '.', ''), isset($paid2[$id]) ? 'Paid' : 'Unpaid', number_format(($a + $b) / 100, 2, '.', '')]);
    }
    exit;
}
if (isset($_GET['csv'])) {
    $pr = compute_payroll($pdo, $from, $to);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payroll_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee', 'Days present', 'Morning shifts', 'Afternoon shifts', 'Pay (PHP)']);
    foreach ($pr['people'] as $p) fputcsv($out, [csv_safe($p['name']), $p['days'], $p['morning'], $p['afternoon'], number_format($p['centavos'] / 100, 2, '.', '')]);
    fputcsv($out, []);
    fputcsv($out, ['Date', 'Shift', 'Sales (PHP)', 'Staff pool ' . STAFF_SHARE_PERCENT . '% (PHP)', 'Present', 'Share each (PHP)']);
    foreach ($pr['days'] as $d) foreach (SHIFTS as $key => $label) {
        $sh = $d['shifts'][$key];
        if (!$sh['sales'] && !$sh['present']) continue;
        $names = array_map(fn($id) => $pr['names'][$id] ?? '?', $sh['present']);
        fputcsv($out, [$d['date'], $label, number_format($sh['sales'] / 100, 2, '.', ''), number_format($sh['pool'] / 100, 2, '.', ''), csv_safe(implode(', ', $names)),
            $sh['present'] ? number_format(intdiv($sh['pool'], count($sh['present'])) / 100, 2, '.', '') : '']);
    }
    exit;
}

$employees = $pdo->query('SELECT * FROM employees ORDER BY is_active DESC, name')->fetchAll();
$activeEmployees = array_values(array_filter($employees, fn($x) => $x['is_active']));
$staffPct = rtrim(rtrim(number_format(STAFF_SHARE_PERCENT, 2), '0'), '.');
$shopPct = rtrim(rtrim(number_format(100 - STAFF_SHARE_PERCENT, 2), '0'), '.');
$cutLabel = date('g:i A', strtotime(sprintf('%02d:00', SHIFT_CUTOFF_HOUR)));

$date = $valid($_GET['date'] ?? '') && $_GET['date'] <= $today ? $_GET['date'] : $today;
if ($tab === 'attendance') {
    $presentBy = ['morning' => [], 'afternoon' => []];
    $st = $pdo->prepare('SELECT employee_id, shift FROM attendance WHERE work_date = ?'); $st->execute([$date]);
    foreach ($st as $r) $presentBy[$r['shift']][] = (int)$r['employee_id'];
    $dayPay = compute_payroll($pdo, $date, $date)['days'][0] ?? null;
    $dateLocked = date_is_paid($pdo, $date);
    // employees shown: active ones, plus any inactive one who is already marked that day (so saving never wipes their record)
    $markedIds = array_unique(array_merge($presentBy['morning'], $presentBy['afternoon']));
    $attEmployees = array_values(array_filter($employees, fn($x) => $x['is_active'] || in_array((int)$x['id'], $markedIds, true)));
}
if ($tab === 'report') $pr = compute_payroll($pdo, $from, $to);
if ($tab === 'payout') {
    $periods = pay_periods($month);
    $pp = []; $paid = [];
    foreach ($periods as $k => $per) {
        $pp[$k] = compute_payroll($pdo, $per['from'], $per['to']);
        $paid[$k] = period_payouts($pdo, $per['from'], $per['to']);
    }
    $ids = array_values(array_unique(array_merge(array_keys($pp['first']['people']), array_keys($pp['second']['people']))));
    $nameOf = fn($id) => $pp['first']['people'][$id]['name'] ?? $pp['second']['people'][$id]['name'] ?? '?';
    usort($ids, fn($a, $b) => strcasecmp($nameOf($a), $nameOf($b)));
}

require __DIR__ . '/../includes/header.php';
$tabUrl = fn($t) => url('admin/payroll.php?tab=' . $t);
$tabs = ['attendance' => 'Attendance', 'payout' => 'Pay day', 'report' => 'Report', 'employees' => 'Employees'];
?>
<?php page_head('Payroll', 'Staff share: <strong>' . $staffPct . '%</strong> of each shift\'s sales, split equally among the people who worked that shift.'); ?>
<ul class="nav nav-pills">
  <?php foreach ($tabs as $k => $label): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= $tabUrl($k) ?>"><?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'attendance'): ?>
  <form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-3">
    <input type="hidden" name="tab" value="attendance">
    <label class="form-label mb-0">Date <input class="form-control" type="date" name="date" max="<?= $today ?>" value="<?= e($date) ?>"></label>
    <button class="btn btn-outline-primary">Go</button>
  </form>
  <?php if (!$attEmployees): ?>
    <div class="card card-body"><p class="mb-2">No employees yet.</p><a class="btn btn-primary align-self-start" href="<?= $tabUrl('employees') ?>">Add employees</a></div>
  <?php else: ?>
    <?php if ($dateLocked): ?><div class="alert alert-info">This date is already paid, so it can't be changed. To edit it, open <a href="<?= $tabUrl('payout') ?>&month=<?= e(substr($date, 0, 7)) ?>">Pay day</a> and undo that payment.</div><?php endif; ?>
    <form method="post" class="card shadow-sm" style="max-width:640px">
      <?= csrf_field() ?><input type="hidden" name="action" value="attendance"><input type="hidden" name="date" value="<?= e($date) ?>">
      <div class="card-body pb-2">
        <h2 class="h5 mb-0">Who worked on <?= e(date('D, M j, Y', strtotime($date))) ?>?</h2>
        <small class="text-muted">Morning = before <?= e($cutLabel) ?>. Afternoon = <?= e($cutLabel) ?> onward.</small>
      </div>
      <div class="table-responsive"><table class="table align-middle mb-0 att-table">
        <thead><tr><th>Employee</th>
          <?php foreach (SHIFTS as $key => $label): $sh = $dayPay['shifts'][$key] ?? ['sales' => 0, 'pool' => 0]; ?>
            <th class="text-center shift-head" data-pool="<?= (int)$sh['pool'] ?>" data-shift="<?= e($key) ?>"><?= e($label) ?><div class="fw-normal text-muted text-lowercase small">sales <?= peso_c($sh['sales']) ?></div></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($attEmployees as $x): ?>
          <tr>
            <td><?= e($x['name']) ?><?= $x['is_active'] ? '' : ' <small class="text-muted">(inactive)</small>' ?></td>
            <?php foreach (SHIFTS as $key => $label): ?>
              <td class="text-center"><input class="form-check-input shift-cb" style="width:1.4rem;height:1.4rem" type="checkbox" data-shift="<?= e($key) ?>" name="present[<?= $key ?>][]" value="<?= (int)$x['id'] ?>" aria-label="<?= e($x['name'] . ' ' . $label) ?>"
                <?= in_array((int)$x['id'], $presentBy[$key], true) ? 'checked' : '' ?> <?= $dateLocked ? 'disabled' : '' ?>></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        <tr class="table-light"><td class="small text-muted">Each person gets</td>
          <?php foreach (SHIFTS as $key => $label): ?><td class="text-center small fw-semibold shift-est" data-shift="<?= e($key) ?>">–</td><?php endforeach; ?>
        </tr>
        </tbody>
      </table></div>
      <div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <button class="btn btn-primary" <?= $dateLocked ? 'disabled' : '' ?>>Save attendance</button>
        <?php if (!$dateLocked): ?><button type="button" class="btn btn-outline-primary" id="tickAll">Tick everyone</button><?php endif; ?>
      </div>
    </form>
    <script>
    (function () {
      var fmt = function (c) { return '₱' + (c / 100).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
      var update = function () {
        document.querySelectorAll('.shift-head').forEach(function (h) {
          var shift = h.dataset.shift, pool = parseInt(h.dataset.pool, 10) || 0;
          var n = document.querySelectorAll('.shift-cb[data-shift="' + shift + '"]:checked').length;
          document.querySelector('.shift-est[data-shift="' + shift + '"]').textContent = !pool ? '–' : n ? fmt(Math.floor(pool / n)) : 'nobody ticked';
        });
      };
      document.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('shift-cb')) update(); });
      var all = document.getElementById('tickAll');
      if (all) all.addEventListener('click', function () { document.querySelectorAll('.shift-cb').forEach(function (c) { c.checked = true; }); update(); });
      update();
    })();
    </script>
  <?php endif; ?>

<?php elseif ($tab === 'payout'): ?>
  <form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-4">
    <input type="hidden" name="tab" value="payout">
    <label class="form-label mb-0">Month <input class="form-control" type="month" name="month" value="<?= e($month) ?>"></label>
    <button class="btn btn-outline-primary">Show</button>
    <a class="btn btn-outline-primary" href="?tab=payout&month=<?= e($month) ?>&csv=1">Download CSV</a>
    <button type="button" class="btn btn-outline-primary" onclick="window.print()">Print</button>
  </form>

  <div class="row g-3 mb-4">
  <?php foreach ($periods as $k => $per):
      $t = $pp[$k]['totals']; $isPaid = (bool)$paid[$k]; $over = $per['to'] <= $today; ?>
    <div class="col-md-6"><div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div>
          <h2 class="h5 mb-0"><?= $k === 'first' ? '15th pay' : 'Month-end pay' ?></h2>
          <div class="text-muted small"><?= e(date('M j', strtotime($per['from']))) ?> – <?= e(date('M j', strtotime($per['to']))) ?></div>
        </div>
        <span class="pill <?= $isPaid ? 'completed' : ($over ? 'pending' : 'confirmed') ?>"><?= $isPaid ? 'Paid' : ($over ? 'Ready to pay' : 'Not yet') ?></span>
      </div>
      <div class="fs-3 fw-bold my-2"><?= peso_c($t['paid']) ?></div>
      <?php if ($isPaid): ?>
        <form method="post" onsubmit="return confirm('Undo this payment? Attendance for these dates can be edited again.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="undo_paid"><input type="hidden" name="month" value="<?= e($month) ?>"><input type="hidden" name="half" value="<?= e($k) ?>">
          <button class="btn btn-outline-danger btn-sm">Undo</button></form>
      <?php elseif ($over): ?>
        <form method="post" onsubmit="return confirm('Mark this pay as paid? Attendance for these dates will be locked.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="month" value="<?= e($month) ?>"><input type="hidden" name="half" value="<?= e($k) ?>">
          <button class="btn btn-primary">Mark as paid</button></form>
      <?php else: ?>
        <div class="small text-muted">Available on <?= e(date('M j', strtotime($per['to']))) ?>.</div>
      <?php endif; ?>
    </div></div></div>
  <?php endforeach; ?>
  </div>
  <?php $unassignedAll = $pp['first']['totals']['unassigned'] + $pp['second']['totals']['unassigned']; if ($unassignedAll > 0): ?>
    <div class="alert alert-warning">Some sales (<?= peso_c($unassignedAll) ?> staff share) have nobody marked present. <a href="<?= $tabUrl('attendance') ?>">Fix in Attendance</a> before paying.</div>
  <?php endif; ?>

  <h2 class="h5">Pay per employee · <?= e(date('F Y', strtotime($month . '-01'))) ?></h2>
  <div class="table-responsive card shadow-sm"><table class="table align-middle mb-0">
    <thead><tr><th>Employee</th><th class="text-end">15th pay</th><th class="text-end">Month-end pay</th><th class="text-end">Total</th></tr></thead><tbody>
    <?php $sum1 = $sum2 = 0;
    foreach ($ids as $id):
        $a = $pp['first']['people'][$id]['centavos'] ?? 0; $b = $pp['second']['people'][$id]['centavos'] ?? 0; $sum1 += $a; $sum2 += $b; ?>
      <tr>
        <td><?= e($nameOf($id)) ?></td>
        <?php foreach (['first' => $a, 'second' => $b] as $k => $amt): $rec = $paid[$k][$id] ?? null; ?>
          <td class="text-end"><?= peso_c($amt) ?><?php if ($rec): ?> <span class="pill completed">Paid</span><?php endif; ?></td>
        <?php endforeach; ?>
        <td class="text-end fw-bold"><?= peso_c($a + $b) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ids): ?><tr><td colspan="4" class="text-muted">Nothing to pay yet this month.</td></tr>
    <?php else: ?><tr class="table-light fw-bold"><td>Total</td><td class="text-end"><?= peso_c($sum1) ?></td><td class="text-end"><?= peso_c($sum2) ?></td><td class="text-end"><?= peso_c($sum1 + $sum2) ?></td></tr><?php endif; ?>
  </tbody></table></div>

<?php elseif ($tab === 'employees'): ?>
  <form method="post" class="card card-body shadow-sm mb-4" style="max-width:520px">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_employee">
    <label class="form-label w-100 mb-0">Add employee
      <div class="d-flex gap-2 mt-1"><input class="form-control" name="name" required maxlength="100" placeholder="Full name"><button class="btn btn-primary">Add</button></div></label>
  </form>
  <div class="table-responsive card shadow-sm"><table class="table align-middle mb-0">
    <thead><tr><th>Name</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($employees as $x): ?>
      <tr>
        <td><form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="rename_employee"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
          <input class="form-control form-control-sm" name="name" maxlength="100" value="<?= e($x['name']) ?>"><button class="btn btn-outline-primary btn-sm">Save</button></form></td>
        <td><span class="pill <?= $x['is_active'] ? 'completed' : 'cancelled' ?>"><?= $x['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_employee"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
          <button class="btn btn-outline-primary btn-sm"><?= $x['is_active'] ? 'Deactivate' : 'Reactivate' ?></button></form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$employees): ?><tr><td colspan="3" class="text-muted">No employees yet.</td></tr><?php endif; ?>
  </tbody></table></div>

<?php else: /* report */ ?>
  <form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-4">
    <input type="hidden" name="tab" value="report">
    <label class="form-label mb-0">From <input class="form-control" type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="form-label mb-0">To <input class="form-control" type="date" name="to" value="<?= e($to) ?>"></label>
    <button class="btn btn-primary">Show</button>
    <a class="btn btn-outline-primary" href="?tab=report&from=<?= e($from) ?>&to=<?= e($to) ?>&csv=1">Download CSV</a>
    <button type="button" class="btn btn-outline-primary" onclick="window.print()">Print</button>
  </form>
  <?php $t = $pr['totals']; ?>
  <div class="stats">
    <div class="card stat shadow-sm"><div class="card-body"><span><?= peso_c($t['sales']) ?></span><small>Total sales</small></div></div>
    <div class="card stat shadow-sm"><div class="card-body"><span><?= peso_c($t['paid']) ?></span><small>Paid to staff (<?= $staffPct ?>%)</small></div></div>
    <div class="card stat shadow-sm"><div class="card-body"><span><?= peso_c($t['shop']) ?></span><small>Shop keeps (<?= $shopPct ?>%)</small></div></div>
  </div>
  <?php if ($t['unassigned'] > 0): ?>
    <div class="alert alert-warning">Some shifts have sales but nobody marked present (<?= peso_c($t['unassigned']) ?>). They are highlighted below. <a href="<?= $tabUrl('attendance') ?>">Open Attendance</a></div>
  <?php endif; ?>

  <h2 class="h5">Pay per employee</h2>
  <div class="table-responsive card shadow-sm mb-4"><table class="table align-middle mb-0">
    <thead><tr><th>Employee</th><th class="text-end">Days worked</th><th class="text-end">Pay</th></tr></thead><tbody>
    <?php foreach ($pr['people'] as $p): ?>
      <tr><td><?= e($p['name']) ?></td><td class="text-end"><?= (int)$p['days'] ?></td><td class="text-end fw-bold"><?= peso_c($p['centavos']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$pr['people']): ?><tr><td colspan="3" class="text-muted">No attendance in this period.</td></tr><?php endif; ?>
  </tbody></table></div>

  <h2 class="h5">Day by day</h2>
  <div class="table-responsive card shadow-sm"><table class="table align-middle mb-0">
    <thead><tr><th>Date</th><th class="text-end">Sales</th><th>Morning</th><th>Afternoon</th></tr></thead><tbody>
    <?php foreach ($pr['days'] as $d): ?>
      <tr class="<?= $d['unassigned'] > 0 ? 'table-warning' : '' ?>">
        <td><a href="<?= url('admin/payroll.php?tab=attendance&date=' . $d['date']) ?>"><?= e(date('D, M j', strtotime($d['date']))) ?></a></td>
        <td class="text-end"><?= peso_c($d['sales']) ?></td>
        <?php foreach (SHIFTS as $key => $label): $sh = $d['shifts'][$key]; $n = count($sh['present']); ?>
          <td>
            <?php if (!$sh['sales'] && !$n): ?><span class="text-muted">—</span>
            <?php elseif ($n): ?><?= e(implode(', ', array_map(fn($id) => $pr['names'][$id] ?? '?', $sh['present']))) ?> <small class="text-muted">· <?= peso_c(intdiv($sh['pool'], $n)) ?> each</small>
            <?php else: ?><span class="text-danger small">nobody marked</span><?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$pr['days']): ?><tr><td colspan="4" class="text-muted">No sales or attendance in this period.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
