<?php
// Scheduling helpers: bay availability, slot generation, queue estimates.

/** Return id of an active bay free for [$start, $end), or null. $start/$end: 'Y-m-d H:i:s'. */
function find_free_bay(PDO $pdo, string $start, string $end): ?int {
    $sql = "SELECT b.id FROM bays b
            WHERE b.is_active = 1
              AND NOT EXISTS (
                SELECT 1 FROM reservations r
                WHERE r.bay_id = b.id
                  AND r.status IN ('pending','confirmed','in_progress')
                  AND r.scheduled_at < :end AND r.end_at > :start)
            ORDER BY b.id LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':start' => $start, ':end' => $end]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/** True if an active bay has no other live reservation overlapping [$start, $end). $excludeId = reservation being moved. */
function bay_is_free(PDO $pdo, int $bayId, string $start, string $end, int $excludeId = 0): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM bays b WHERE b.id = :bay AND b.is_active = 1
        AND NOT EXISTS (SELECT 1 FROM reservations r
            WHERE r.bay_id = b.id AND r.id <> :ex
              AND r.status IN ('pending','confirmed','in_progress')
              AND r.scheduled_at < :end AND r.end_at > :start)");
    $st->execute([':bay' => $bayId, ':ex' => $excludeId, ':start' => $start, ':end' => $end]);
    return (int)$st->fetchColumn() > 0;
}

/** Slots for a date and package duration, with availability flag (one query for the whole day). $excludeId = a reservation being rescheduled (its own old time must not block it). */
function slots_for_date(PDO $pdo, string $date, int $durationMin, int $excludeId = 0): array {
    $bays = array_map('intval', $pdo->query('SELECT id FROM bays WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN));
    $st = $pdo->prepare("SELECT bay_id, UNIX_TIMESTAMP(scheduled_at) s, UNIX_TIMESTAMP(end_at) e FROM reservations
        WHERE status IN ('pending','confirmed','in_progress') AND id <> :ex AND scheduled_at < DATE_ADD(:d2, INTERVAL 2 DAY) AND end_at > :d1");
    $st->execute([':d1' => $date, ':d2' => $date, ':ex' => $excludeId]);
    $busy = [];
    foreach ($st as $r) $busy[(int)$r['bay_id']][] = [(int)$r['s'], (int)$r['e']];

    $slots = [];
    $now = time();
    $t = strtotime($date . ' ' . sprintf('%02d:00', OPEN_HOUR));
    $close = strtotime($date . ' ' . sprintf('%02d:00', CLOSE_HOUR));
    while ($t + $durationMin * 60 <= $close) {
        $end = $t + $durationMin * 60;
        $free = false;
        foreach ($bays as $bid) {
            $clash = false;
            foreach ($busy[$bid] ?? [] as [$bs, $be]) if ($bs < $end && $be > $t) { $clash = true; break; }
            if (!$clash) { $free = true; break; }
        }
        $slots[] = ['time' => date('H:i', $t), 'label' => date('g:i A', $t), 'available' => $t > $now && $free];
        $t += SLOT_STEP_MIN * 60;
    }
    return $slots;
}

/** Cancel online-payment bookings that nobody paid for within ONLINE_HOLD_HOURS, so they stop blocking a bay. */
function expire_unpaid_online(PDO $pdo): void {
    $hours = defined('ONLINE_HOLD_HOURS') ? (int)ONLINE_HOLD_HOURS : 12;
    if ($hours <= 0) return;
    $pdo->prepare("UPDATE reservations r SET r.status = 'cancelled'
        WHERE r.status = 'pending' AND r.payment_mode = 'online' AND r.source = 'online'
          AND r.created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
          AND NOT EXISTS (SELECT 1 FROM online_payments op WHERE op.reservation_id = r.id AND op.status IN ('pending','verified'))")
        ->execute([$hours]);
}

/** Live queue snapshot used by the monitoring dashboard. */
function queue_snapshot(PDO $pdo): array {
    $bays = $pdo->query("SELECT b.id, b.name,
            (SELECT CONCAT(r.vehicle_plate, ' · ', p.name) FROM reservations r
               JOIN packages p ON p.id = r.package_id
              WHERE r.bay_id = b.id AND r.status = 'in_progress' LIMIT 1) AS occupied_by
        FROM bays b WHERE b.is_active = 1 ORDER BY b.id")->fetchAll();

    $waiting = $pdo->query("SELECT r.id, r.vehicle_plate, r.scheduled_at, p.name AS package, p.duration_min
        FROM reservations r JOIN packages p ON p.id = r.package_id
        WHERE DATE(r.scheduled_at) = CURDATE() AND r.status = 'confirmed' AND r.end_at > NOW()
        ORDER BY r.scheduled_at")->fetchAll();

    $inService = count(array_filter($bays, fn($b) => $b['occupied_by'] !== null));
    $activeBays = max(1, count($bays));
    // Wait estimate: only bookings due within the next hour count (a 5 PM booking does not make the line longer now).
    $soon = array_filter($waiting, fn($w) => strtotime($w['scheduled_at']) <= time() + 3600);
    $totalMin = array_sum(array_column($soon, 'duration_min'));
    return [
        'bays'         => $bays,
        'waiting'      => $waiting,
        'queue_length' => count($waiting),
        'busy_bays'    => $inService,
        'total_bays'   => count($bays),
        'est_wait_min' => (int)ceil($totalMin / $activeBays),
    ];
}

/** Best currently-valid promo discount percent (0 if none). Returns [promo_id|null, percent]. */
function best_promo(PDO $pdo, string $date): array {
    $st = $pdo->prepare("SELECT id, discount_percent FROM promos
        WHERE is_active = 1 AND :d BETWEEN start_date AND end_date
        ORDER BY discount_percent DESC LIMIT 1");
    $st->execute([':d' => $date]);
    $p = $st->fetch();
    return $p ? [(int)$p['id'], (float)$p['discount_percent']] : [null, 0.0];
}


/** Can the customer still move this booking? Only pending/confirmed ones that start at least RESCHEDULE_CUTOFF_HOURS from now. */
function can_reschedule(array $r): bool {
    if (!in_array($r['status'], ['pending', 'confirmed'], true)) return false;
    $cutoff = defined('RESCHEDULE_CUTOFF_HOURS') ? (int)RESCHEDULE_CUTOFF_HOURS : 0;
    return strtotime($r['scheduled_at']) - time() >= $cutoff * 3600;
}
