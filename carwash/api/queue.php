<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/scheduling.php';
header('Content-Type: application/json');
$snap = queue_snapshot($pdo);
// Mask plates for public viewers (keep last 3 characters).
if (!is_staff()) {
    foreach ($snap['waiting'] as &$w) $w['vehicle_plate'] = '***' . substr($w['vehicle_plate'], -3);
    unset($w);
    foreach ($snap['bays'] as &$b) if ($b['occupied_by']) $b['occupied_by'] = 'In service';
    unset($b);
}
echo json_encode($snap);
