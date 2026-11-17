<?php
/**
 * ONLINE (GCash) PAYMENTS + CASH AT THE SHOP
 * ------------------------------------------
 * - A customer may choose "Pay online" when booking. After the shop has set the vehicle size (price is final) they send a
 *   GCash deposit (ONLINE_DEPOSIT_PERCENT) or the full price from "My bookings", with the GCash reference number and an
 *   optional screenshot. Staff compare it with the shop's GCash and press Confirm (or Reject).
 * - The SALE is recorded in `payments` only when the service is completed (so reports and payroll use the completion time).
 *   At that moment the verified online money is credited (payments.prepaid) and only the balance is collected at the counter.
 * - If a booking is cancelled after a verified online payment, it becomes "refund due"; staff then mark it Refunded
 *   (sent back through GCash) or Kept (deposit forfeited).
 */
require_once __DIR__ . '/photos.php';

const ONLINE_STATUS_LABELS = [
    'pending' => 'For verification', 'verified' => 'Paid online', 'rejected' => 'Rejected', 'void' => 'Cancelled',
    'refund_due' => 'Refund due', 'refunded' => 'Refunded', 'forfeited' => 'Kept by shop',
];

/** All online payments of the given reservations, newest first: [reservation_id => [row, ...]]. */
function online_for(PDO $pdo, array $reservationIds): array {
    if (!$reservationIds) return [];
    $in = implode(',', array_map('intval', $reservationIds));
    $out = [];
    foreach ($pdo->query("SELECT * FROM online_payments WHERE reservation_id IN ($in) ORDER BY id DESC")->fetchAll() as $r) {
        $out[(int)$r['reservation_id']][] = $r;
    }
    return $out;
}

/** Verified online money for a reservation (what the customer already paid). */
function online_verified_total(PDO $pdo, int $rid): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM online_payments WHERE reservation_id = ? AND status = 'verified'");
    $st->execute([$rid]);
    return (float)$st->fetchColumn();
}

function online_has_pending(PDO $pdo, int $rid): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM online_payments WHERE reservation_id = ? AND status = 'pending'");
    $st->execute([$rid]);
    return (int)$st->fetchColumn() > 0;
}

/** Deposit amount for a price (never more than the price). */
function online_deposit_amount(float $total): float {
    return round(min($total, $total * ONLINE_DEPOSIT_PERCENT / 100), 2);
}

function online_deposit_allowed(): bool { return ONLINE_DEPOSIT_PERCENT > 0 && ONLINE_DEPOSIT_PERCENT < 100; }

/** A booking was cancelled: pending proofs are voided, verified money becomes "refund due". Returns the amount now due back. */
function online_release_on_cancel(PDO $pdo, int $rid): float {
    $due = online_verified_total($pdo, $rid);
    $pdo->prepare("UPDATE online_payments SET status = 'void', resolved_at = NOW() WHERE reservation_id = ? AND status = 'pending'")->execute([$rid]);
    $pdo->prepare("UPDATE online_payments SET status = 'refund_due' WHERE reservation_id = ? AND status = 'verified'")->execute([$rid]);
    return $due;
}

/** GCash / card reference numbers: letters, digits and dashes, 6-30 characters. */
function valid_reference(string $ref): bool { return (bool)preg_match('/^[A-Za-z0-9\-]{6,30}$/', $ref); }

/** True when this reference number is already used by a live (pending / verified) online payment. */
function reference_in_use(PDO $pdo, string $ref): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM online_payments WHERE reference_no = ? AND status IN ('pending','verified','refund_due','refunded','forfeited')");
    $st->execute([$ref]);
    return (int)$st->fetchColumn() > 0;
}

/** Small coloured badge for a status (uses the existing .pill styles). */
function online_pill(array $op): string {
    $cls = ['pending' => 'pending', 'verified' => 'completed', 'rejected' => 'cancelled', 'void' => 'cancelled', 'refund_due' => 'in_progress', 'refunded' => 'confirmed', 'forfeited' => 'cancelled'][$op['status']] ?? 'pending';
    return '<span class="pill ' . $cls . '">' . e(ONLINE_STATUS_LABELS[$op['status']] ?? $op['status']) . '</span>';
}

/** Absolute path of a saved payment screenshot (same private folder as the vehicle photos). */
function proof_path(string $file): string { return photo_dir() . '/' . basename($file); }
