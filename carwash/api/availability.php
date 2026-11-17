<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/scheduling.php';
header('Content-Type: application/json');

expire_unpaid_online($pdo);
$date = $_GET['date'] ?? '';
$pkgId = (int)($_GET['package_id'] ?? 0);
$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) { http_response_code(400); echo '[]'; exit; }

$st = $pdo->prepare('SELECT duration_min FROM packages WHERE id = ? AND is_active = 1');
$st->execute([$pkgId]);
$dur = $st->fetchColumn();
if (!$dur) { http_response_code(400); echo '[]'; exit; }

// When a customer reschedules, their own booking must not block its own slots (only honoured for the owner or staff).
$exclude = 0;
$ex = (int)($_GET['exclude'] ?? 0);
if ($ex > 0 && ($me = current_user())) {
    $q = $pdo->prepare('SELECT user_id FROM reservations WHERE id = ?');
    $q->execute([$ex]);
    $owner = $q->fetchColumn();
    if ($owner !== false && ((int)$owner === (int)$me['id'] || is_staff())) $exclude = $ex;
}
echo json_encode(slots_for_date($pdo, $date, (int)$dur, $exclude));
