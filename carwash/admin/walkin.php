<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/scheduling.php';
require __DIR__ . '/../includes/catalog.php';
require_role(STAFF_ROLES);
$title = 'Walk-in';

$packages = load_packages($pdo, true);
$sizes = vehicle_sizes();
$bays = $pdo->query('SELECT id, name FROM bays WHERE is_active = 1 ORDER BY id')->fetchAll();
[, $promoPct] = best_promo($pdo, date('Y-m-d'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name  = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
    $phone = mb_substr(trim($_POST['phone'] ?? ''), 0, 30);
    $plate = strtoupper(trim($_POST['plate'] ?? ''));
    $vtype = $_POST['vehicle_type'] ?? '';
    $pkgId = (int)($_POST['package_id'] ?? 0);
    $bayChoice = (int)($_POST['bay_id'] ?? 0);          // 0 = pick any free bay
    $when  = ($_POST['when'] ?? 'now') === 'later' ? 'later' : 'now';

    $pkg = $packages[$pkgId] ?? null;
    $basePrice = $pkg['prices'][$vtype] ?? null;
    $startTs = $when === 'now' ? strtotime(date('Y-m-d H:i:00')) : strtotime(date('Y-m-d') . ' ' . ($_POST['time'] ?? ''));

    if (!$pkg || $plate === '' || !isset($sizes[$vtype])) {
        flash('error', 'Please enter the plate number, vehicle size and service.');
    } elseif ($basePrice === null) {
        flash('error', 'That service is not available for the selected vehicle size.');
    } elseif (!$startTs) {
        flash('error', 'Pick a start time.');
    } elseif ($when === 'later' && $startTs <= time()) {
        flash('error', 'Pick a time later today, or choose "Start now".');
    } elseif ((int)date('G', $startTs) < OPEN_HOUR || $startTs >= strtotime(date('Y-m-d', $startTs) . sprintf(' %02d:00', CLOSE_HOUR))) {
        flash('error', 'That time is outside opening hours (' . hours_label() . ').');
    } elseif ($startTs + $pkg['duration_min'] * 60 > strtotime(date('Y-m-d', $startTs) . sprintf(' %02d:00', CLOSE_HOUR))) {
        flash('error', 'This service (' . (int)$pkg['duration_min'] . ' min) would run past closing time (' . hours_label() . '). Pick an earlier time or a shorter service.');
    } else {
        $start = date('Y-m-d H:i:s', $startTs);
        $end = date('Y-m-d H:i:s', $startTs + $pkg['duration_min'] * 60);
        $pdo->beginTransaction();
        try {
            $pdo->query('SELECT id FROM bays WHERE is_active = 1 FOR UPDATE')->fetchAll();   // no double-booking a bay
            if ($bayChoice) {
                $bayId = bay_is_free($pdo, $bayChoice, $start, $end) ? $bayChoice : null;
            } else {
                $bayId = find_free_bay($pdo, $start, $end);
            }
            if ($bayId === null) {
                $pdo->rollBack();
                flash('error', $bayChoice ? 'That bay is busy at that time. Pick another bay or time.' : 'No bay is free at that time. Try a later time.');
            } else {
                [$promoId, $pct] = best_promo($pdo, date('Y-m-d', $startTs));
                $total = round($basePrice * (1 - $pct / 100), 2);
                $status = $when === 'now' ? 'in_progress' : 'confirmed';
                $pdo->prepare('INSERT INTO reservations
                    (user_id, customer_name, customer_phone, source, package_id, bay_id, promo_id, vehicle_plate, vehicle_type, scheduled_at, end_at, status, total_price)
                    VALUES (NULL,?,?,"walkin",?,?,?,?,?,?,?,?,?)')
                    ->execute([$name !== '' ? $name : null, $phone !== '' ? $phone : null, $pkgId, $bayId, $promoId, $plate, $vtype, $start, $end, $status, $total]);
                $pdo->commit();
                flash('success', 'Walk-in added (' . $plate . ', ' . $pkg['name'] . ', ' . peso($total) . ')' . ($when === 'now' ? ' and the wash has started.' : ' for ' . date('g:i A', $startTs) . '.'));
                redirect('admin/reservations.php');
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Could not save the walk-in. Please try again.');
        }
    }
}
require __DIR__ . '/../includes/header.php';
?>
<?php page_head('Walk-in customer', 'Register a customer who arrived without a booking and assign a bay.'); ?>
<p class="text-muted">For a customer who is at the shop without an account or booking. Payment is taken later with <b>Complete &amp; pay</b> in Reservations.</p>
<form method="post" class="card card-body shadow-sm vstack gap-3 form-md mb-4" id="walkin-form">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label w-100">Customer name <small class="text-muted fw-normal">(optional)</small>
      <input class="form-control" name="name" maxlength="100" value="<?= e($_POST['name'] ?? '') ?>"></label></div>
    <div class="col-md-6"><label class="form-label w-100">Mobile number <small class="text-muted fw-normal">(optional)</small>
      <input class="form-control" name="phone" maxlength="30" value="<?= e($_POST['phone'] ?? '') ?>"></label></div>
  </div>
  <label class="form-label w-100">Plate number <input class="form-control" name="plate" required maxlength="20" value="<?= e($_POST['plate'] ?? '') ?>"></label>
  <label class="form-label w-100">Vehicle type / size
    <select class="form-select" name="vehicle_type" id="vehicle" required>
      <?php foreach (category_labels() as $catKey => $catLabel): ?>
        <optgroup label="<?= e($catLabel) ?>">
          <?php foreach (sizes_for($catKey) as $key => [$label]): ?>
            <option value="<?= e($key) ?>" <?= ($_POST['vehicle_type'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="form-label w-100">Service <select class="form-select" name="package_id" id="package" required></select></label>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label w-100">Bay
      <select class="form-select" name="bay_id">
        <option value="0">Any free bay</option>
        <?php foreach ($bays as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)($_POST['bay_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
      </select></label></div>
    <div class="col-md-6"><div class="form-label">When</div>
      <div class="form-check"><input class="form-check-input" type="radio" name="when" id="wnow" value="now" <?= ($_POST['when'] ?? 'now') === 'now' ? 'checked' : '' ?>><label class="form-check-label" for="wnow">Start now</label></div>
      <div class="form-check d-flex align-items-center gap-2"><input class="form-check-input" type="radio" name="when" id="wlater" value="later" <?= ($_POST['when'] ?? '') === 'later' ? 'checked' : '' ?>>
        <label class="form-check-label" for="wlater">Later today at</label>
        <input class="form-control form-control-sm w-auto" type="time" name="time" id="wtime" value="<?= e($_POST['time'] ?? '') ?>"></div></div>
  </div>
  <div class="alert alert-info mb-0" id="summary" aria-live="polite"></div>
  <button class="btn btn-primary">Add walk-in</button>
</form>
<script>
(function () {
  const CATALOG = <?= json_encode(array_map(fn($p) => [
      'id' => (int)$p['id'], 'name' => $p['name'], 'duration' => (int)$p['duration_min'],
      'starting' => (bool)$p['price_is_starting'], 'prices' => (object)$p['prices'],
  ], array_values($packages)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const pct = <?= json_encode((float)$promoPct) ?>, wantPkg = <?= json_encode((string)($_POST['package_id'] ?? '')) ?>;
  const vehicle = document.getElementById('vehicle'), pkg = document.getElementById('package'), summary = document.getElementById('summary');
  const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2 });
  function fill(prev) {
    pkg.innerHTML = '';
    CATALOG.filter(p => p.prices[vehicle.value] !== undefined).forEach(p => {
      const o = document.createElement('option');
      o.value = p.id; o.textContent = `${p.name} — ${peso(p.prices[vehicle.value])}${p.starting ? ' (starts at)' : ''} · ${p.duration} min`;
      pkg.appendChild(o);
    });
    if ([...pkg.options].some(o => o.value === prev)) pkg.value = prev;
    show();
  }
  function show() {
    const p = CATALOG.find(x => String(x.id) === pkg.value);
    if (!p) { summary.textContent = 'No services available for this vehicle size.'; return; }
    const price = p.prices[vehicle.value], total = price * (1 - pct / 100);
    summary.innerHTML = '';
    const b = document.createElement('strong'); b.textContent = p.name;
    summary.append(b, ` · ${vehicle.options[vehicle.selectedIndex].text} · ${p.duration} min — ${p.starting ? 'from ' : ''}${peso(total)}${pct ? ` (${pct}% promo applied)` : ''}`);
  }
  vehicle.addEventListener('change', () => fill(pkg.value));
  pkg.addEventListener('change', show);
  document.getElementById('wtime').addEventListener('input', () => { document.getElementById('wlater').checked = true; });
  fill(wantPkg);
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
