<?php
// Handles Set size / Accept / Change bay / Start / Complete (cash change, GCash ref) / Cancel / verify or reject online payment / refund. Include BEFORE any output.
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/scheduling.php';
require_once __DIR__ . '/online_payments.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $me = current_user();

    $st = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();

    if ($r && in_array($action, ['accept', 'start', 'complete'], true) && $r['vehicle_type'] === null) {
        // The price depends on the vehicle size, so the size must be picked from the customer's photos first.
        flash('error', 'Set the vehicle size first (check the photos), then continue.');
    } elseif ($r) {
        if ($action === 'set_size' && in_array($r['status'], ['pending', 'confirmed', 'in_progress'], true)) {
            $size = $_POST['size'] ?? '';
            $pkg = load_packages($pdo, false)[(int)$r['package_id']] ?? null;
            $price = $pkg['prices'][$size] ?? null;
            if ($price === null || (vehicle_sizes()[$size][1] ?? '') !== ($pkg['category'] ?? '')) {
                flash('error', 'Pick a valid size for this service.');
            } else {
                $pct = 0.0;
                if ($r['promo_id']) {   // keep the promo the customer booked with
                    $q = $pdo->prepare('SELECT discount_percent FROM promos WHERE id = ?'); $q->execute([$r['promo_id']]);
                    $pct = (float)$q->fetchColumn();
                }
                $total = round($price * (1 - $pct / 100), 2);
                $paidOnline = online_verified_total($pdo, $id);
                if ($paidOnline > $total + 0.001) {
                    flash('error', 'The customer already paid ' . peso($paidOnline) . ' online, which is more than ' . peso($total) . '. Pick a size that costs at least that much, or cancel and refund first.');
                } else {
                    $pdo->prepare('UPDATE reservations SET vehicle_type = ?, total_price = ? WHERE id = ?')->execute([$size, $total, $id]);
                    notify($pdo, $r['user_id'], 'The shop set your vehicle size to ' . vehicle_label($size) . '. Your total is ' . peso($total) . '.'
                        . ($r['payment_mode'] === 'online' && !$paidOnline ? ' Open My bookings to pay online and confirm your slot.' : ''), 'sms');
                    flash('success', 'Size set to ' . vehicle_label($size) . ' (' . peso($total) . ').');
                }
            }
        } elseif ($action === 'accept' && $r['status'] === 'pending' && $r['payment_mode'] === 'online' && online_verified_total($pdo, $id) <= 0) {
            flash('error', 'This customer chose to pay online. Verify their GCash payment first (or wait for it), then accept.');
        } elseif ($action === 'accept' && $r['status'] === 'pending') {
            $pdo->prepare("UPDATE reservations SET status='confirmed' WHERE id=?")->execute([$id]);
            notify($pdo, $r['user_id'], 'Your booking for ' . date('M j, g:i A', strtotime($r['scheduled_at'])) . ' was accepted. See you then!', 'sms');
            flash('success', 'Booking accepted.');
        } elseif ($action === 'change_bay' && in_array($r['status'], ['pending', 'confirmed', 'in_progress'], true)) {
            $newBay = (int)($_POST['bay_id'] ?? 0);
            $pdo->beginTransaction();
            try {
                $pdo->query('SELECT id FROM bays WHERE is_active = 1 FOR UPDATE')->fetchAll();   // avoid double-assigning a bay
                if ($newBay === (int)$r['bay_id']) {
                    $pdo->rollBack(); flash('error', 'Pick a different bay.');
                } elseif (!$pdo->query('SELECT COUNT(*) FROM bays WHERE is_active = 1 AND id = ' . $newBay)->fetchColumn()) {
                    $pdo->rollBack(); flash('error', 'That bay is not available.');
                } else {
                    $wasBusy = !bay_is_free($pdo, $newBay, $r['scheduled_at'], $r['end_at'], $id);   // admin override: allowed, just warn
                    $pdo->prepare('UPDATE reservations SET bay_id=? WHERE id=?')->execute([$newBay, $id]);
                    $name = $pdo->prepare('SELECT name FROM bays WHERE id=?'); $name->execute([$newBay]);
                    $bayName = (string)$name->fetchColumn();
                    notify($pdo, $r['user_id'], 'Your vehicle was moved to ' . $bayName . '.', 'sms');
                    $pdo->commit();
                    flash($wasBusy ? 'warning' : 'success', 'Moved to ' . $bayName . ($wasBusy ? ' (it was already in use at that time)' : '') . '.');
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', 'Could not change the bay. Please try again.');
            }
        } elseif ($action === 'start' && $r['status'] === 'confirmed') {
            $pdo->prepare("UPDATE reservations SET status='in_progress' WHERE id=?")->execute([$id]);
            notify($pdo, $r['user_id'], 'Your vehicle is now being serviced.', 'sms');
            flash('success', 'Service started.');
        } elseif ($action === 'complete' && $r['status'] === 'in_progress') {
            // The sale is recorded NOW (completion time). Verified online money is credited; the staff collect only the balance.
            $total = round((float)$r['total_price'], 2);
            $prepaid = min(online_verified_total($pdo, $id), $total);
            $balance = round($total - $prepaid, 2);
            $method = in_array($_POST['method'] ?? '', ['cash', 'gcash', 'card'], true) ? $_POST['method'] : 'cash';
            $ref = strtoupper(trim($_POST['reference_no'] ?? ''));
            $received = null; $change = null; $error = null;
            if (online_has_pending($pdo, $id)) {
                $error = 'The customer sent an online payment that is not verified yet. Confirm or reject it first.';
            } elseif ($balance > 0 && $method === 'cash') {
                $received = round((float)($_POST['cash_received'] ?? 0), 2);
                if ($received + 0.001 < $balance) $error = 'Cash received (' . peso($received) . ') is less than the balance (' . peso($balance) . ').';
                else $change = round($received - $balance, 2);
            } elseif ($balance > 0 && $method === 'gcash' && !valid_reference($ref)) {
                $error = 'Enter the GCash reference number (6-30 letters/numbers) for the counter payment.';
            } elseif ($balance > 0 && $method === 'card' && $ref !== '' && !valid_reference($ref)) {
                $error = 'The card reference should be 6-30 letters/numbers, or leave it blank.';
            }
            if ($balance <= 0) { $method = 'gcash'; $ref = ''; }          // fully prepaid online
            elseif ($method === 'cash') $ref = '';
            if ($error) {
                flash('error', $error);
            } else {
                $pdo->beginTransaction();
                try {
                    // Only the first click wins: a double-click or second tab finds the booking already completed.
                    $upd = $pdo->prepare("UPDATE reservations SET status='completed' WHERE id=? AND status='in_progress'");
                    $upd->execute([$id]);
                    if (!$upd->rowCount()) {
                        $pdo->rollBack();
                        flash('error', 'This booking was already completed.');
                    } else {
                        $receipt = 'RCP-' . date('Ymd') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
                        $pdo->prepare('INSERT INTO payments (reservation_id, amount, prepaid, method, cash_received, change_given, reference_no, receipt_no) VALUES (?,?,?,?,?,?,?,?)')
                            ->execute([$id, $total, $prepaid, $method, $received, $change, $ref !== '' ? $ref : null, $receipt]);
                        notify($pdo, $r['user_id'], 'Service complete. Receipt ' . $receipt . ' is ready.', 'sms');
                        $pdo->commit();
                        flash('success', "Completed. Receipt $receipt created." . ($change !== null && $change > 0 ? ' Give change: ' . peso($change) . '.' : ''));
                    }
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    flash('error', 'Could not complete this booking. Please check it and try again.');
                }
            }
        } elseif ($action === 'verify_payment' || $action === 'reject_payment') {
            $pid = (int)($_POST['pid'] ?? 0);
            if ($action === 'verify_payment') {
                $st = $pdo->prepare("UPDATE online_payments SET status='verified', verified_at=NOW(), verified_by=? WHERE id=? AND reservation_id=? AND status='pending'");
                $st->execute([$me['id'] ?? null, $pid, $id]);
                if ($st->rowCount()) {
                    notify($pdo, $r['user_id'], 'Your online payment was verified. Thank you!' . ($r['status'] === 'pending' ? ' The shop will confirm your booking.' : ''), 'sms');
                    flash('success', 'Payment verified.' . ($r['status'] === 'pending' ? ' You can now accept the booking.' : ''));
                }
            } else {
                $note = mb_substr(trim($_POST['note'] ?? ''), 0, 200);
                $st = $pdo->prepare("UPDATE online_payments SET status='rejected', note=?, verified_at=NOW(), verified_by=? WHERE id=? AND reservation_id=? AND status='pending'");
                $st->execute([$note !== '' ? $note : 'Could not find this payment', $me['id'] ?? null, $pid, $id]);
                if ($st->rowCount()) {
                    notify($pdo, $r['user_id'], 'Your online payment could not be verified' . ($note !== '' ? ': ' . $note : '') . '. Please check the reference number and send it again from My bookings.', 'sms');
                    flash('success', 'Payment rejected. The customer was told to send it again.');
                }
            }
        } elseif ($action === 'refund_done' || $action === 'refund_keep') {
            $pid = (int)($_POST['pid'] ?? 0);
            $note = mb_substr(trim($_POST['note'] ?? ''), 0, 200);
            $new = $action === 'refund_done' ? 'refunded' : 'forfeited';
            $st = $pdo->prepare("UPDATE online_payments SET status=?, note=?, resolved_at=NOW() WHERE id=? AND reservation_id=? AND status='refund_due'");
            $st->execute([$new, $note !== '' ? $note : null, $pid, $id]);
            if ($st->rowCount()) {
                notify($pdo, $r['user_id'], $new === 'refunded' ? 'Your online payment was refunded to your GCash.' : 'The shop kept your deposit for the cancelled booking.', 'sms');
                flash('success', $new === 'refunded' ? 'Marked as refunded.' : 'Marked as kept by the shop.');
            }
        } elseif ($action === 'cancel' && in_array($r['status'], ['pending', 'confirmed'], true)) {
            $pdo->prepare("UPDATE reservations SET status='cancelled' WHERE id=?")->execute([$id]);
            $refund = online_release_on_cancel($pdo, $id);
            notify($pdo, $r['user_id'], ($r['status'] === 'pending' ? 'Sorry, your booking was declined by the shop.' : 'Your booking was cancelled by the shop.')
                . ($refund > 0 ? ' Your online payment of ' . peso($refund) . ' will be refunded.' : ''), 'sms');
            flash('success', ($r['status'] === 'pending' ? 'Booking declined.' : 'Reservation cancelled.') . ($refund > 0 ? ' Refund due to the customer: ' . peso($refund) . ' (see the Refunds filter).' : ''));
        }
    }
    redirect('admin/reservations.php' . (isset($_GET['filter']) && is_string($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : '') . '');
}
