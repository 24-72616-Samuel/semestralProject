<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/scheduling.php';
require_role(STAFF_ROLES);
$title = 'Bays';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($action === 'add') {
        // The shop can have at most MAX_BAYS bays in total (active or not).
        $total = (int)$pdo->query('SELECT COUNT(*) FROM bays')->fetchColumn();
        if ($total >= MAX_BAYS) {
            flash('error', 'You already have the maximum of ' . MAX_BAYS . ' bays.');
        } else {
            if ($name === '') $name = 'Bay ' . ($total + 1);
            $pdo->prepare('INSERT INTO bays (name) VALUES (?)')->execute([mb_substr($name, 0, 50)]);
            flash('success', 'Bay added.');
        }
    } elseif ($action === 'rename' && $id && $name !== '') {
        $pdo->prepare('UPDATE bays SET name = ? WHERE id = ?')->execute([mb_substr($name, 0, 50), $id]);
        flash('success', 'Bay renamed.');
    } elseif ($action === 'deactivate' && $id) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE bay_id = ? AND status IN ('pending','confirmed','in_progress') AND end_at > NOW()");
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) {
            flash('error', 'That bay still has bookings or a car in service. Move them to another bay first.');
        } else {
            $pdo->prepare('UPDATE bays SET is_active = 0 WHERE id = ?')->execute([$id]);
            flash('success', 'Bay turned off.');
        }
    } elseif ($action === 'activate' && $id) {
        $pdo->prepare('UPDATE bays SET is_active = 1 WHERE id = ?')->execute([$id]);
        flash('success', 'Bay turned on.');
    }
    redirect('admin/bays.php');
}

$bays = $pdo->query("SELECT b.*,
        (SELECT CONCAT(r.vehicle_plate, ' · ', p.name) FROM reservations r JOIN packages p ON p.id = r.package_id
          WHERE r.bay_id = b.id AND r.status = 'in_progress' LIMIT 1) AS occupied_by
    FROM bays b ORDER BY b.id")->fetchAll();
$count = count($bays);

require __DIR__ . '/../includes/header.php';
?>
<?php page_head('Bays', 'Your shop can have ' . MAX_BAYS . ' wash bays. Customers are only booked into bays that are turned on. To move a car, use <a href="' . url('admin/reservations.php') . '">Reservations</a> → Change bay.'); ?>
<div class="stats">
  <div class="card stat"><div class="card-body"><span><?= (int)$count ?> / <?= MAX_BAYS ?></span><small>Bays in use</small></div></div>
</div>

<div class="grid">
  <?php foreach ($bays as $b): ?>
    <div class="card bay shadow-sm <?= !$b['is_active'] ? '' : ($b['occupied_by'] ? 'busy' : 'free') ?>" style="<?= $b['is_active'] ? '' : 'opacity:.6' ?>"><div class="card-body">
      <form method="post" class="d-flex gap-2 mb-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>">
        <input class="form-control form-control-sm" name="name" value="<?= e($b['name']) ?>" maxlength="50" required>
        <button name="action" value="rename" class="btn btn-outline-primary btn-sm">Rename</button>
      </form>
      <p class="mb-2"><?= !$b['is_active'] ? '<span class="text-muted">Turned off</span>' : ($b['occupied_by'] ? e($b['occupied_by']) : 'Available') ?></p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>">
        <?php if ($b['is_active']): ?>
          <button name="action" value="deactivate" class="btn btn-outline-danger btn-sm" data-confirm="Turn off this bay?">Turn off</button>
        <?php else: ?>
          <button name="action" value="activate" class="btn btn-primary btn-sm">Turn on</button>
        <?php endif; ?>
      </form>
    </div></div>
  <?php endforeach; ?>
</div>

<?php if ($count < MAX_BAYS): ?>
  <form method="post" class="inline-form d-flex gap-2 mb-4"><?= csrf_field() ?>
    <input class="form-control" name="name" placeholder="Bay <?= $count + 1 ?>" maxlength="50">
    <button name="action" value="add" class="btn btn-primary">Add bay</button>
  </form>
<?php else: ?>
  <div class="alert alert-info">Maximum of <?= MAX_BAYS ?> bays reached.</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
