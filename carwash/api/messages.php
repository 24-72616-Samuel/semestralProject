<?php
// Live updates for the chat box: returns messages newer than ?after=ID and marks them read for the viewer.
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/messages.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$u = current_user();
if (!$u) { http_response_code(401); echo '[]'; exit; }
$isStaff = is_staff();

if ($isStaff) {
    $customerId = (int)($_GET['customer'] ?? 0);
    $st = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'customer'");
    $st->execute([$customerId]);
    if (!$st->fetchColumn()) { http_response_code(404); echo '[]'; exit; }
} else {
    $customerId = (int)$u['id'];      // customers can only ever read their own conversation
}

$rows = chat_fetch($pdo, $customerId, (int)($_GET['after'] ?? 0));
if ($rows) chat_mark_read($pdo, $customerId, $isStaff);
echo json_encode(array_map(fn($m) => [
    'id' => (int)$m['id'],
    'mine' => ((int)$m['from_staff'] === 1) === $isStaff,
    'meta' => ($m['from_staff'] ? 'Eayila Carwash · ' . $m['sender'] : $m['sender']) . ' · ' . date('M j, g:i A', strtotime($m['created_at'])),
    'body' => $m['body'],
], $rows), JSON_UNESCAPED_UNICODE);
