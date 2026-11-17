<?php
require __DIR__ . '/../includes/bootstrap.php';
require_role(ADMIN_ROLES);
$title = 'Manage promos';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'save') {
        $t = trim($_POST['title'] ?? '');
        $d = trim($_POST['description'] ?? '');
        $pct = (float)($_POST['discount_percent'] ?? 0);
        $s = $_POST['start_date'] ?? '';
        $en = $_POST['end_date'] ?? '';
        $okDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
        if ($t === '' || $pct <= 0 || $pct > 100 || !$okDate($s) || !$okDate($en) || $en < $s) {
            flash('error', 'Check the title, discount (1-100) and date range.');
        } elseif ($id) {
            $pdo->prepare('UPDATE promos SET title=?, description=?, discount_percent=?, start_date=?, end_date=? WHERE id=?')
                ->execute([$t, $d, $pct, $s, $en, $id]);
            flash('success', 'Promo updated.');
        } else {
            $pdo->prepare('INSERT INTO promos (title, description, discount_percent, start_date, end_date) VALUES (?,?,?,?,?)')
                ->execute([$t, $d, $pct, $s, $en]);
            flash('success', 'Promo added.');
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE promos SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM promos WHERE id = ?')->execute([$id]);
        flash('success', 'Promo deleted.');
    }
    redirect('admin/promos.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM promos WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$rows = $pdo->query('SELECT * FROM promos ORDER BY end_date DESC')->fetchAll();
require __DIR__ . '/../includes/header.php';
?>
<?php page_head('Promos', 'Create limited-time discounts and switch them on or off.'); ?>
<form method="post" class="card card-body shadow-sm vstack gap-3 form-md mb-4">
  <h3><?= $edit ? 'Edit promo' : 'Add promo' ?></h3>
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <label class="form-label w-100">Title <input class="form-control" name="title" required value="<?= e($edit['title'] ?? '') ?>"></label>
  <label class="form-label w-100">Description <textarea class="form-control" name="description" rows="2"><?= e($edit['description'] ?? '') ?></textarea></label>
  <label class="form-label w-100">Discount (%) <input class="form-control" type="number" step="0.01" min="1" max="100" name="discount_percent" required value="<?= e($edit['discount_percent'] ?? '') ?>"></label>
  <label class="form-label w-100">Start <input class="form-control" type="date" name="start_date" required value="<?= e($edit['start_date'] ?? date('Y-m-d')) ?>"></label>
  <label class="form-label w-100">End <input class="form-control" type="date" name="end_date" required value="<?= e($edit['end_date'] ?? '') ?>"></label>
  <button class="btn btn-primary">Save</button>
  <?php if ($edit): ?><a class="btn btn-outline-primary" href="<?= url('admin/promos.php') ?>">Cancel</a><?php endif; ?>
</form>

<div class="table-responsive card shadow-sm mb-4"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Title</th><th>Discount</th><th>Dates</th><th>Active</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($r['title']) ?></td><td><?= e($r['discount_percent']) ?>%</td>
      <td><?= e($r['start_date']) ?> → <?= e($r['end_date']) ?></td>
      <td><?= $r['is_active'] ? 'Yes' : 'No' ?></td>
      <td class="actions">
        <a class="btn btn-outline-primary btn-sm" href="?edit=<?= $r['id'] ?>">Edit</a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button name="action" value="toggle" class="btn btn-outline-primary btn-sm">On/Off</button>
          <button name="action" value="delete" class="btn btn-danger btn-sm" data-confirm="Delete?">Delete</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
