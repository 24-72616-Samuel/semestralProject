<?php
// Shows one vehicle photo, but only to the staff or to the customer who booked it.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/photos.php';
$u = require_login();

$st = $pdo->prepare('SELECT ph.file_name, r.user_id FROM reservation_photos ph JOIN reservations r ON r.id = ph.reservation_id WHERE ph.id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$row = $st->fetch();
$path = $row ? photo_dir() . '/' . basename($row['file_name']) : '';
if (!$row || (!is_staff() && (int)$row['user_id'] !== (int)$u['id']) || !is_file($path)) { http_response_code(404); exit('Photo not found.'); }

$mime = getimagesize($path)['mime'] ?? 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
