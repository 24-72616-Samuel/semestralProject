<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/scheduling.php';
require __DIR__ . '/../includes/catalog.php';
require __DIR__ . '/../includes/photos.php';
require __DIR__ . '/../includes/online_payments.php';
$user = require_role(['customer']);
$title = 'Book a wash';

$sv = $pdo->prepare('SELECT plate, label FROM customer_vehicles WHERE user_id = ? ORDER BY plate');
$sv->execute([$user['id']]);
$savedVehicles = $sv->fetchAll();

expire_unpaid_online($pdo);
function activeBookings(PDO $pdo, int $uid): int {
    $q = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status IN ('pending','confirmed')");
    $q->execute([$uid]);
    return (int)$q->fetchColumn();
}
$packages = load_packages($pdo, true);
$cats = category_labels();
[, $discountNow] = best_promo($pdo, date('Y-m-d')); // shown in the live summary only; the server recalculates on submit

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // PHP drops the whole form when the upload is bigger than post_max_size, so say what happened instead of "expired form".
    flash('error', 'Your photos are too large to upload. Try fewer or smaller photos.');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pkgId = (int)($_POST['package_id'] ?? 0);
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    $plate = strtoupper(trim($_POST['plate'] ?? ''));
    $cat = $_POST['vehicle'] ?? '';
    $payMode = ($_POST['payment_mode'] ?? '') === 'online' ? 'online' : 'shop';

    $pkg = $packages[$pkgId] ?? null;
    $startTs = strtotime("$date $time");

    if (!$pkg || !isset($cats[$cat]) || $pkg['category'] !== $cat || !$startTs || $plate === '') {
        flash('error', 'Please complete all fields.');
    } elseif (!$pkg['prices']) {
        flash('error', 'That service has no price yet. Please choose another.');
    } elseif ($startTs <= time()) {
        flash('error', 'Pick a time in the future.');
    } elseif ((int)date('i', $startTs) % SLOT_STEP_MIN !== 0 || (int)date('s', $startTs) !== 0) {
        flash('error', 'Please pick one of the listed time slots.');
    } elseif (activeBookings($pdo, (int)$user['id']) >= MAX_ACTIVE_BOOKINGS) {
        flash('error', 'You already have ' . MAX_ACTIVE_BOOKINGS . ' active bookings. Finish or cancel one before booking another.');
    } else {
        $start = date('Y-m-d H:i:s', $startTs);
        $end = date('Y-m-d H:i:s', $startTs + $pkg['duration_min'] * 60);
        $h = (int)date('G', $startTs);
        $endsAfterClose = $startTs + $pkg['duration_min'] * 60 > strtotime(date('Y-m-d', $startTs) . sprintf(' %02d:00', CLOSE_HOUR));

        if ($h < OPEN_HOUR || $endsAfterClose) {
            flash('error', 'That time is outside opening hours.');
        } else {
            [$files, $photoErr] = photos_save($_FILES['photos'] ?? []);
            if ($photoErr) {
                flash('error', $photoErr);
            } else {
                $pdo->beginTransaction();
                try {
                    // Lock bay rows so two customers cannot grab the same bay/slot at once.
                    $pdo->query('SELECT id FROM bays WHERE is_active = 1 FOR UPDATE')->fetchAll();
                    $bayId = find_free_bay($pdo, $start, $end);
                    if ($bayId === null) {
                        $pdo->rollBack();
                        photos_delete($files);
                        flash('error', 'Sorry, that slot was just taken. Please choose another.');
                    } else {
                        [$promoId, $pct] = best_promo($pdo, date('Y-m-d', $startTs));
                        // The size is not known yet: store the lowest price as an estimate. The shop sets the real size and price after checking the photos.
                        $estimate = round(min($pkg['prices']) * (1 - $pct / 100), 2);
                        $pdo->prepare('INSERT INTO reservations
                            (user_id, payment_mode, package_id, bay_id, promo_id, vehicle_plate, vehicle_type, scheduled_at, end_at, status, total_price)
                            VALUES (?,?,?,?,?,?,NULL,?,?, "pending", ?)')
                            ->execute([$user['id'], $payMode, $pkgId, $bayId, $promoId, $plate, $start, $end, $estimate]);
                        $rid = (int)$pdo->lastInsertId();
                        $ins = $pdo->prepare('INSERT INTO reservation_photos (reservation_id, file_name) VALUES (?,?)');
                        foreach ($files as $fn) $ins->execute([$rid, $fn]);
                        notify($pdo, $user['id'], 'Booking received for ' . date('M j, g:i A', $startTs) . ' (' . $pkg['name'] . '). The shop will check your photos, set your vehicle size and confirm the price.', 'email');
                        $pdo->commit();
                        flash('success', 'Booking sent! The shop will check your photos, set your vehicle size and confirm the final price.'
                            . ($payMode === 'online' ? ' Then open My bookings to pay your GCash deposit and secure your slot.' : ''));
                        redirect('customer/dashboard.php');
                    }
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    photos_delete($files);
                    flash('error', 'Could not save your booking. Please try again.');
                }
            }
        }
    }
}
require __DIR__ . '/../includes/header.php';
?>
<?php page_head('Book a wash', 'Pick a service, a time slot and tell us about your vehicle.'); ?>
<form method="post" enctype="multipart/form-data" class="card card-body shadow-sm vstack gap-3 form-md mb-4" id="book-form">
  <?= csrf_field() ?>
  <label class="form-label w-100">Vehicle type
    <select class="form-select" name="vehicle" id="vehicle" required>
      <?php foreach ($cats as $catKey => $catLabel): ?>
        <option value="<?= e($catKey) ?>" <?= ($_POST['vehicle'] ?? '') === $catKey ? 'selected' : '' ?>><?= e($catLabel) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div>
    <label class="form-label w-100 mb-1" for="photos">Photos of your vehicle <span class="text-danger">*</span></label>
    <input class="form-control" type="file" name="photos[]" id="photos" accept="image/*" multiple required>
    <div class="form-text">Take clear photos (front and side work best), up to <?= PHOTO_MAX ?>. The shop checks them to decide your vehicle size, which sets the final price.</div>
    <div id="photoPreview" class="photo-preview"></div>
  </div>
  <label class="form-label w-100">Service
    <select class="form-select" name="package_id" id="package" required></select>
  </label>
  <label class="form-label w-100">Date <input class="form-control" type="date" name="date" id="date" min="<?= date('Y-m-d') ?>" value="<?= e($_POST['date'] ?? date('Y-m-d')) ?>" required></label>
  <div>
    <div class="form-label">Available times <small class="text-muted fw-normal">(tap one)</small></div>
    <div id="slots" class="d-flex flex-wrap gap-2"></div>
    <input type="hidden" name="time" id="time">
  </div>
  <label class="form-label w-100">Plate number <input class="form-control" name="plate" required maxlength="20" list="savedPlates" autocomplete="off" value="<?= e($_POST['plate'] ?? '') ?>">
    <datalist id="savedPlates"><?php foreach ($savedVehicles as $sv): ?><option value="<?= e($sv['plate']) ?>"><?= e($sv['label'] ?? '') ?></option><?php endforeach; ?></datalist>
    <?php if (!$savedVehicles): ?><small class="text-muted">Tip: save your vehicles in <a href="<?= url('customer/profile.php') ?>">My Profile</a> to pick them quickly.</small><?php endif; ?></label>
  <div>
    <div class="form-label mb-1">How will you pay?</div>
    <?php $pm = ($_POST['payment_mode'] ?? 'shop') === 'online' ? 'online' : 'shop'; ?>
    <div class="form-check"><input class="form-check-input" type="radio" name="payment_mode" id="pm-shop" value="shop" <?= $pm === 'shop' ? 'checked' : '' ?>>
      <label class="form-check-label" for="pm-shop">Pay at the shop <small class="text-muted">(cash, GCash or card after the wash)</small></label></div>
    <div class="form-check"><input class="form-check-input" type="radio" name="payment_mode" id="pm-online" value="online" <?= $pm === 'online' ? 'checked' : '' ?>>
      <label class="form-check-label" for="pm-online">Pay online via GCash <small class="text-muted">(<?= online_deposit_allowed() ? 'a ' . (int)ONLINE_DEPOSIT_PERCENT . '% deposit or the full price' : 'full price' ?>, sent after the shop confirms your vehicle size; your slot is confirmed once the payment is verified)</small></label></div>
  </div>
  <div class="alert alert-info mb-0" id="summary" aria-live="polite"></div>
  <button class="btn btn-primary">Confirm booking</button>
</form>
<script>
window.CATALOG = <?= json_encode(array_map(fn($p) => [
    'id' => (int)$p['id'], 'name' => $p['name'], 'category' => $p['category'], 'duration' => (int)$p['duration_min'],
    'starting' => (bool)$p['price_is_starting'],
    'min' => $p['prices'] ? min($p['prices']) : null, 'max' => $p['prices'] ? max($p['prices']) : null,
], array_values($packages)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.PROMO_PCT = <?= json_encode((float)$discountNow) ?>;
window.WANT_TIME = <?= json_encode((string)($_POST['time'] ?? '')) ?>;
window.WANT_PACKAGE = <?= json_encode((string)($_POST['package_id'] ?? '')) ?>;
window.AVAILABILITY_API = "<?= url('api/availability.php') ?>";

// Photos: shrink big phone pictures in the browser before upload (keeps them under the server's size limit) and show previews.
(function () {
  const input = document.getElementById('photos'), prev = document.getElementById('photoPreview'), MAX = <?= PHOTO_MAX ?>;
  function shrink(file) {
    return new Promise(resolve => {
      if (!file.type.startsWith('image/')) return resolve(file);
      const url = URL.createObjectURL(file), img = new Image();
      img.onload = () => {
        const s = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
        const c = document.createElement('canvas'); c.width = Math.round(img.naturalWidth * s); c.height = Math.round(img.naturalHeight * s);
        const ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(b => resolve(b ? new File([b], (file.name.replace(/\.[^.]+$/, '') || 'photo') + '.jpg', { type: 'image/jpeg' }) : file), 'image/jpeg', 0.82);
      };
      img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }
  input.addEventListener('change', async () => {
    let files = [...input.files];
    if (files.length > MAX) { alert('You can add up to ' + MAX + ' photos. The first ' + MAX + ' were kept.'); files = files.slice(0, MAX); }
    const out = await Promise.all(files.map(shrink));
    try { const dt = new DataTransfer(); out.forEach(f => dt.items.add(f)); input.files = dt.files; } catch (e) { /* keep originals */ }
    prev.innerHTML = '';
    out.forEach(f => { const i = document.createElement('img'); i.alt = 'Vehicle photo'; i.src = URL.createObjectURL(f); prev.appendChild(i); });
  });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
