<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/scheduling.php';
require __DIR__ . '/../includes/catalog.php';
$user = require_role(['customer']);
$title = 'Reschedule booking';

$rid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT r.*, p.name AS package, p.duration_min, b.name AS bay
    FROM reservations r
    JOIN packages p ON p.id = r.package_id
    LEFT JOIN bays b ON b.id = r.bay_id
    WHERE r.id = ? AND r.user_id = ?');
$st->execute([$rid, $user['id']]);
$r = $st->fetch();

if (!$r) { flash('error', 'Booking not found.'); redirect('customer/dashboard.php'); }
if (!can_reschedule($r)) {
    $cut = (int)RESCHEDULE_CUTOFF_HOURS;
    flash('error', in_array($r['status'], ['pending', 'confirmed'], true)
        ? 'This booking starts too soon to be moved' . ($cut > 0 ? ' (changes close ' . $cut . ' hour(s) before the start)' : '') . '. Please contact the shop.'
        : 'Only pending or confirmed bookings can be rescheduled.');
    redirect('customer/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    $startTs = strtotime("$date $time");
    $dur = (int)$r['duration_min'];

    if (!$startTs) {
        flash('error', 'Please pick a date and a time slot.');
    } elseif ($startTs <= time()) {
        flash('error', 'Pick a time in the future.');
    } elseif ($startTs - time() < (int)RESCHEDULE_CUTOFF_HOURS * 3600) {
        flash('error', 'Please pick a time at least ' . (int)RESCHEDULE_CUTOFF_HOURS . ' hour(s) from now.');
    } elseif ((int)date('i', $startTs) % SLOT_STEP_MIN !== 0 || (int)date('s', $startTs) !== 0) {
        flash('error', 'Please pick one of the listed time slots.');
    } elseif (date('Y-m-d H:i:s', $startTs) === $r['scheduled_at']) {
        flash('error', 'That is your current time. Pick a different slot.');
    } elseif ((int)date('G', $startTs) < OPEN_HOUR
        || $startTs + $dur * 60 > strtotime(date('Y-m-d', $startTs) . sprintf(' %02d:00', CLOSE_HOUR))) {
        flash('error', 'That time is outside opening hours.');
    } else {
        $start = date('Y-m-d H:i:s', $startTs);
        $end = date('Y-m-d H:i:s', $startTs + $dur * 60);
        $pdo->beginTransaction();
        try {
            // Lock the bays so nobody else can take the same slot while we move this booking.
            $pdo->query('SELECT id FROM bays WHERE is_active = 1 FOR UPDATE')->fetchAll();
            // Re-read the booking inside the lock: it may have been started or cancelled meanwhile.
            $cur = $pdo->prepare("SELECT bay_id, scheduled_at, status FROM reservations WHERE id = ? AND user_id = ? AND status IN ('pending','confirmed') FOR UPDATE");
            $cur->execute([$rid, $user['id']]);
            $now = $cur->fetch();
            if (!$now) {
                $pdo->rollBack();
                flash('error', 'This booking can no longer be rescheduled.');
                redirect('customer/dashboard.php');
            }
            // Keep the same bay if it is free at the new time, otherwise take any free bay.
            $bayId = ($now['bay_id'] && bay_is_free($pdo, (int)$now['bay_id'], $start, $end, $rid))
                ? (int)$now['bay_id']
                : find_free_bay($pdo, $start, $end);
            if ($bayId === null) {
                $pdo->rollBack();
                flash('error', 'Sorry, that slot was just taken. Please choose another.');
            } else {
                $pdo->prepare('UPDATE reservations SET scheduled_at = ?, end_at = ?, bay_id = ? WHERE id = ?')->execute([$start, $end, $bayId, $rid]);
                notify($pdo, $user['id'], 'Your booking (' . $r['package'] . ', ' . $r['vehicle_plate'] . ') was moved from '
                    . date('M j, g:i A', strtotime($r['scheduled_at'])) . ' to ' . date('M j, g:i A', $startTs) . '.', 'email');
                $pdo->commit();
                flash('success', 'Booking rescheduled to ' . date('M j, Y g:i A', $startTs) . '.');
                redirect('customer/dashboard.php');
            }
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Could not reschedule. Please try again.');
        }
    }
}

$minDate = date('Y-m-d');
$defaultDate = $_POST['date'] ?? date('Y-m-d', strtotime($r['scheduled_at']));
if ($defaultDate < $minDate) $defaultDate = $minDate;

require __DIR__ . '/../includes/header.php';
?>
<?php page_head('Reschedule booking', 'Pick a new date and time. Your service, vehicle and price stay the same.', '<a class="btn btn-outline-primary btn-sm" href="' . url('customer/dashboard.php') . '">&larr; Back to bookings</a>'); ?>

<div class="card card-body shadow-sm form-md mb-3">
  <dl class="resched-current">
    <div><dt>Service</dt><dd><?= e($r['package']) ?></dd></div>
    <div><dt>Plate</dt><dd><?= e($r['vehicle_plate']) ?></dd></div>
    <div><dt>Current time</dt><dd><?= e(date('M j, Y g:i A', strtotime($r['scheduled_at']))) ?></dd></div>
    <div><dt>Duration</dt><dd><?= (int)$r['duration_min'] ?> min</dd></div>
  </dl>
</div>

<form method="post" class="card card-body shadow-sm vstack gap-3 form-md mb-4" id="resched-form">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$rid ?>">
  <label class="form-label w-100">New date <input class="form-control" type="date" name="date" id="date" min="<?= e($minDate) ?>" value="<?= e($defaultDate) ?>" required></label>
  <div>
    <div class="form-label">Available times <small class="text-muted fw-normal">(tap one)</small></div>
    <div id="slots" class="d-flex flex-wrap gap-2"></div>
    <input type="hidden" name="time" id="time">
  </div>
  <div class="alert alert-info mb-0" id="summary" aria-live="polite">Choose a new time.</div>
  <button class="btn btn-primary">Confirm new time</button>
</form>

<script>
(function () {
  const API = "<?= url('api/availability.php') ?>", PKG = <?= (int)$r['package_id'] ?>, RID = <?= (int)$rid ?>;
  const CURRENT = <?= json_encode(date('Y-m-d H:i', strtotime($r['scheduled_at']))) ?>;
  const WANT = <?= json_encode((string)($_POST['time'] ?? '')) ?>;
  const date = document.getElementById('date'), slots = document.getElementById('slots'), time = document.getElementById('time');
  const summary = document.getElementById('summary'), form = document.getElementById('resched-form');
  let want = WANT;

  async function loadSlots() {
    time.value = ''; time.dataset.label = '';
    summary.textContent = 'Choose a new time.';
    slots.classList.remove('border', 'border-danger', 'rounded', 'p-2');
    slots.innerHTML = '<div class="spinner-border spinner-border-sm text-primary" role="status"></div> <span class="text-muted small">Checking availability…</span>';
    try {
      const res = await fetch(`${API}?package_id=${PKG}&date=${date.value}&exclude=${RID}`);
      if (!res.ok) throw new Error('bad response');
      const list = await res.json();
      if (!list.length) { slots.innerHTML = '<span class="text-muted">No slots for this date.</span>'; return; }
      slots.innerHTML = '';
      list.forEach(s => {
        const isCurrent = `${date.value} ${s.time}` === CURRENT;
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-outline-primary btn-sm slot-btn';
        b.textContent = s.label + (isCurrent ? ' (current)' : '');
        b.disabled = !s.available || isCurrent;
        if (b.disabled) b.title = isCurrent ? 'Your current time' : 'Not available';
        b.addEventListener('click', () => {
          slots.querySelectorAll('.slot-btn').forEach(x => x.classList.remove('active'));
          b.classList.add('active');
          time.value = s.time; time.dataset.label = s.label;
          summary.innerHTML = 'New time: <strong>' + date.value + ' · ' + s.label + '</strong>';
          slots.classList.remove('border', 'border-danger', 'rounded', 'p-2');
        });
        slots.appendChild(b);
      });
      if (want) {   // the form came back with an error: re-select the chosen time
        const t = want; want = '';
        const btn = [...slots.querySelectorAll('.slot-btn')].find((b, i) => list[i].time === t && !b.disabled);
        if (btn) btn.click();
      }
      if (!list.some(s => s.available && `${date.value} ${s.time}` !== CURRENT)) slots.insertAdjacentHTML('beforeend', '<div class="w-100 text-danger small mt-1">Fully booked — try another date.</div>');
    } catch (e) {
      slots.innerHTML = '<span class="text-danger">Could not load slots. Refresh and try again.</span>';
    }
  }
  form.addEventListener('submit', e => {
    if (!time.value) {
      e.preventDefault();
      slots.classList.add('border', 'border-danger', 'rounded', 'p-2');
      slots.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
  date.addEventListener('change', loadSlots);
  loadSlots();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
