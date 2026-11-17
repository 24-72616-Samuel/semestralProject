<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/catalog.php';
require_role(ADMIN_ROLES);
$title = 'Manage packages';

$cats = category_labels();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $cat = $_POST['category'] ?? 'car';
        $dur = max(15, (int)($_POST['duration_min'] ?? 30));
        $starting = isset($_POST['price_is_starting']) ? 1 : 0;

        // Keep only prices for sizes that belong to the chosen category and are > 0.
        $prices = [];
        if (isset($cats[$cat])) {
            foreach (sizes_for($cat) as $key => $_) {
                $v = trim((string)($_POST['prices'][$key] ?? ''));
                if ($v !== '' && is_numeric($v) && (float)$v > 0) $prices[$key] = round((float)$v, 2);
            }
        }

        if ($name === '' || !isset($cats[$cat])) {
            flash('error', 'Name and category are required.');
        } elseif (!$prices) {
            flash('error', 'Enter a price for at least one vehicle size.');
        } else {
            $pdo->beginTransaction();
            try {
                if ($id) {
                    $pdo->prepare('UPDATE packages SET name=?, description=?, category=?, duration_min=?, price_is_starting=? WHERE id=?')
                        ->execute([$name, $desc, $cat, $dur, $starting, $id]);
                    $pdo->prepare('DELETE FROM package_prices WHERE package_id = ?')->execute([$id]);
                } else {
                    $pdo->prepare('INSERT INTO packages (name, description, category, duration_min, price_is_starting) VALUES (?,?,?,?,?)')
                        ->execute([$name, $desc, $cat, $dur, $starting]);
                    $id = (int)$pdo->lastInsertId();
                }
                $ins = $pdo->prepare('INSERT INTO package_prices (package_id, size, price) VALUES (?,?,?)');
                foreach ($prices as $size => $price) $ins->execute([$id, $size, $price]);
                $pdo->commit();
                flash('success', 'Package saved.');
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', 'Could not save the package.');
            }
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE packages SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash('success', 'Visibility changed.');
    } elseif ($action === 'delete') {
        try {
            $pdo->prepare('DELETE FROM packages WHERE id = ?')->execute([$id]);
            flash('success', 'Package deleted.');
        } catch (PDOException $e) {
            flash('error', 'This package has reservations. Hide it instead of deleting.');
        }
    }
    redirect('admin/packages.php');
}

$all = load_packages($pdo, false);
$edit = isset($_GET['edit']) ? ($all[(int)$_GET['edit']] ?? null) : null;
$curCat = $edit['category'] ?? 'car';
require __DIR__ . '/../includes/header.php';
?>
<?php page_head('Service packages', 'Manage the washes and rates customers can book.'); ?>
<form method="post" class="card card-body shadow-sm vstack gap-3 form-md mb-4">
  <h3><?= $edit ? 'Edit package' : 'Add package' ?></h3>
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <label class="form-label w-100">Name <input class="form-control" name="name" required value="<?= e($edit['name'] ?? '') ?>"></label>
  <label class="form-label w-100">Description <textarea class="form-control" name="description" rows="2"><?= e($edit['description'] ?? '') ?></textarea></label>
  <label class="form-label w-100">Category
    <select class="form-select" name="category" id="pkg-cat">
      <?php foreach ($cats as $k => $label): ?><option value="<?= e($k) ?>" <?= $k === $curCat ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label class="form-label w-100">Duration (minutes) <input class="form-control" type="number" min="15" step="15" name="duration_min" required value="<?= e($edit['duration_min'] ?? 30) ?>"></label>

  <div>
    <div class="form-label">Price per vehicle size (₱) <small class="text-muted fw-normal">— leave blank if the service isn't offered for that size</small></div>
    <?php foreach ($cats as $catKey => $catLabel): ?>
      <div class="row g-2 price-group" data-cat="<?= e($catKey) ?>" <?= $catKey === $curCat ? '' : 'hidden' ?>>
        <?php foreach (sizes_for($catKey) as $key => [$label]): ?>
          <div class="col-6">
            <label class="form-label small mb-0"><?= e($label) ?>
              <input class="form-control" type="number" step="0.01" min="0" name="prices[<?= e($key) ?>]" value="<?= e($edit['prices'][$key] ?? '') ?>" <?= $catKey === $curCat ? '' : 'disabled' ?>>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="form-check">
    <input class="form-check-input" type="checkbox" name="price_is_starting" id="pkg-start" value="1" <?= !empty($edit['price_is_starting']) ? 'checked' : '' ?>>
    <label class="form-check-label" for="pkg-start">Prices are "starts at" (final amount may vary)</label>
  </div>
  <button class="btn btn-primary">Save</button>
  <?php if ($edit): ?><a class="btn btn-outline-primary" href="<?= url('admin/packages.php') ?>">Cancel</a><?php endif; ?>
</form>

<div class="table-responsive card shadow-sm mb-4"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Name</th><th>Category</th><th>Price</th><th>Duration</th><th>Visible</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($all as $r): ?>
    <tr>
      <td><?= e($r['name']) ?></td><td><?= e($cats[$r['category']] ?? $r['category']) ?></td>
      <td><?= e(price_label($r)) ?></td><td><?= (int)$r['duration_min'] ?> min</td>
      <td><?= $r['is_active'] ? 'Yes' : 'Hidden' ?></td>
      <td class="actions">
        <a class="btn btn-outline-primary btn-sm" href="?edit=<?= $r['id'] ?>">Edit</a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button name="action" value="toggle" class="btn btn-outline-primary btn-sm">Show/Hide</button>
          <button name="action" value="delete" class="btn btn-danger btn-sm" data-confirm="Delete?">Delete</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<script>
// Show only the size price inputs that belong to the selected category.
(function () {
  const sel = document.getElementById('pkg-cat');
  function sync() {
    document.querySelectorAll('.price-group').forEach(g => {
      const on = g.dataset.cat === sel.value;
      g.hidden = !on;
      g.querySelectorAll('input').forEach(i => i.disabled = !on);
    });
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
