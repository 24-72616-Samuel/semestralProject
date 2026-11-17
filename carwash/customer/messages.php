<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/messages.php';
$user = require_role(['customer']);
$title = 'Messages';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $err = chat_send($pdo, (int)$user['id'], $user, $_POST['body'] ?? '');
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {      // sent from the chat box without reloading
        header('Content-Type: application/json');
        http_response_code($err ? 422 : 200);
        exit(json_encode(['error' => $err]));
    }
    if ($err) flash('error', $err);
    redirect('customer/messages.php');
}

$rows = chat_fetch($pdo, (int)$user['id']);
chat_mark_read($pdo, (int)$user['id'], false);
require __DIR__ . '/../includes/header.php';
?>
<?php page_head('Messages', 'Ask the shop a question or follow up on a booking.'); ?>
<p class="text-muted">Ask the shop about a booking, a service or a price. The owner, admin and staff will reply here.</p>
<div class="card shadow-sm mb-4" style="max-width:760px"><?php chat_render_thread($rows, false, (int)$user['id']); ?></div>
<script src="<?= asset('assets/js/chat.js') ?>"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
