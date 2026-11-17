<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/messages.php';
$user = require_role(STAFF_ROLES);
$title = 'Messages';

$cid = (int)($_GET['c'] ?? $_POST['c'] ?? 0);
$customer = null;
if ($cid) {
    $st = $pdo->prepare("SELECT id, name, email, phone FROM users WHERE id = ? AND role = 'customer'");
    $st->execute([$cid]);
    $customer = $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $customer) {
    csrf_check();
    $err = chat_send($pdo, (int)$customer['id'], $user, $_POST['body'] ?? '');
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json');
        http_response_code($err ? 422 : 200);
        exit(json_encode(['error' => $err]));
    }
    if ($err) flash('error', $err);
    redirect('admin/messages.php?c=' . (int)$customer['id']);
}

// Inbox: every customer who has a conversation, newest activity first, with unread counts.
$inbox = $pdo->query("SELECT u.id, u.name, MAX(m.id) AS last_id,
        SUM(m.from_staff = 0 AND m.read_at IS NULL) AS unread
    FROM messages m JOIN users u ON u.id = m.customer_id
    GROUP BY u.id, u.name ORDER BY last_id DESC LIMIT 100")->fetchAll();
$lastBody = $pdo->prepare('SELECT body FROM messages WHERE id = ?');
$everyone = $pdo->query("SELECT id, name, email FROM users WHERE role = 'customer' ORDER BY name LIMIT 300")->fetchAll();

$rows = [];
if ($customer) {
    $rows = chat_fetch($pdo, (int)$customer['id']);
    chat_mark_read($pdo, (int)$customer['id'], true);
    foreach ($inbox as &$i) if ((int)$i['id'] === (int)$customer['id']) $i['unread'] = 0;
    unset($i);
}
require __DIR__ . '/../includes/header.php';
?>
<?php page_head('Messages', 'Chat with customers about their bookings.'); ?>
<div class="chat-layout">
  <aside class="card shadow-sm chat-inbox">
    <form method="get" class="chat-new">
      <label class="visually-hidden" for="newc">Start a conversation</label>
      <select class="form-select form-select-sm" name="c" id="newc" onchange="if (this.value) this.form.submit()">
        <option value="">+ Message a customer…</option>
        <?php foreach ($everyone as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> (<?= e($c['email']) ?>)</option><?php endforeach; ?>
      </select>
    </form>
    <?php foreach ($inbox as $i):
        $lastBody->execute([$i['last_id']]); $snippet = mb_strimwidth((string)$lastBody->fetchColumn(), 0, 60, '…'); ?>
      <a class="chat-item<?= $customer && (int)$customer['id'] === (int)$i['id'] ? ' active' : '' ?>" href="<?= url('admin/messages.php?c=' . (int)$i['id']) ?>">
        <span class="chat-item-top"><strong><?= e($i['name']) ?></strong><?php if ((int)$i['unread'] > 0): ?><span class="side-badge"><?= (int)$i['unread'] ?></span><?php endif; ?></span>
        <small class="text-muted"><?= e($snippet) ?></small>
      </a>
    <?php endforeach; ?>
    <?php if (!$inbox): ?><p class="text-muted small p-3 mb-0">No conversations yet. Customers can message you from their account, or you can start one above.</p><?php endif; ?>
  </aside>
  <section class="card shadow-sm chat-main">
    <?php if ($customer): ?>
      <div class="chat-head"><strong><?= e($customer['name']) ?></strong> <small class="text-muted"><?= e($customer['email']) ?><?= $customer['phone'] ? ' · ' . e($customer['phone']) : '' ?></small></div>
      <?php chat_render_thread($rows, true, (int)$customer['id']); ?>
    <?php else: ?>
      <p class="text-muted p-4 mb-0">Pick a conversation on the left.</p>
    <?php endif; ?>
  </section>
</div>
<?php if ($customer): ?><script src="<?= asset('assets/js/chat.js') ?>"></script><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
