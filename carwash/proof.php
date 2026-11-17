<?php
// Shows one payment screenshot, but only to the staff or to the customer who sent it.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/online_payments.php';
$u = require_login();

$st = $pdo->prepare('SELECT op.proof_file, r.user_id FROM online_payments op JOIN reservations r ON r.id = op.reservation_id WHERE op.id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$row = $st->fetch();
$path = ($row && $row['proof_file']) ? proof_path($row['proof_file']) : '';
if (!$row || (!is_staff() && (int)$row['user_id'] !== (int)$u['id']) || !is_file($path)) { http_response_code(404); exit('Screenshot not found.'); }

$mime = getimagesize($path)['mime'] ?? 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
