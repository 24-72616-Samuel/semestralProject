<?php
// Payroll rules
//  - The day is split into two shifts: MORNING (payments before SHIFT_CUTOFF_HOUR) and AFTERNOON (from that hour on).
//  - For each shift, STAFF_SHARE_PERCENT (40%) of the money collected in that shift is the staff pool; the shop keeps the rest (60%).
//  - The pool is split equally among the employees marked present for THAT shift. Only people who came in get paid.
//    (Someone who worked both shifts is marked in both and earns from both.)
//  - Pay is released twice a month: on the 15th (1st-15th) and at month end (16th-last day). The two together are the month's pay.
// All maths is done in centavos so the shares always add up exactly to the pool.

const SHIFTS = ['morning' => 'Morning', 'afternoon' => 'Afternoon'];

/**
 * @return array{days: array, people: array, totals: array, names: array}
 *   days:   one row per date that has sales or attendance:
 *           date, sales, pool, unassigned (centavos), shifts[morning|afternoon] => [sales, pool, present[] (ids), shares[id => centavos], unassigned]
 *   people: employee id => [name, days, morning, afternoon, centavos]
 *   totals: sales, pool, paid, shop, unassigned (all in centavos)
 */
function compute_payroll(PDO $pdo, string $from, string $to): array {
    $cut = (int)SHIFT_CUTOFF_HOUR;

    $sales = [];   // [date][shift] => centavos
    $st = $pdo->prepare("SELECT DATE(paid_at) d, IF(HOUR(paid_at) < ?, 'morning', 'afternoon') s, ROUND(SUM(amount) * 100) c
                         FROM payments WHERE DATE(paid_at) BETWEEN ? AND ? GROUP BY DATE(paid_at), IF(HOUR(paid_at) < ?, 'morning', 'afternoon')");
    $st->execute([$cut, $from, $to, $cut]);
    foreach ($st as $r) $sales[$r['d']][$r['s']] = (int)$r['c'];

    $present = []; // [date][shift][] => employee id
    $st = $pdo->prepare('SELECT work_date d, shift s, employee_id FROM attendance WHERE work_date BETWEEN ? AND ? ORDER BY employee_id');
    $st->execute([$from, $to]);
    foreach ($st as $r) $present[$r['d']][$r['s']][] = (int)$r['employee_id'];

    $names = [];
    foreach ($pdo->query('SELECT id, name FROM employees') as $r) $names[(int)$r['id']] = $r['name'];

    $dates = array_unique(array_merge(array_keys($sales), array_keys($present)));
    sort($dates);

    $days = []; $people = [];
    $tot = ['sales' => 0, 'pool' => 0, 'paid' => 0, 'shop' => 0, 'unassigned' => 0];
    foreach ($dates as $d) {
        $row = ['date' => $d, 'sales' => 0, 'pool' => 0, 'unassigned' => 0, 'shifts' => []];
        foreach (SHIFTS as $key => $label) {
            $s = $sales[$d][$key] ?? 0;
            $pool = (int)round($s * STAFF_SHARE_PERCENT / 100);
            $ids = $present[$d][$key] ?? [];
            $n = count($ids);
            $shares = [];
            if ($n) {
                $each = intdiv($pool, $n); $extra = $pool - $each * $n;     // leftover centavos go to the first few people
                foreach ($ids as $i => $id) $shares[$id] = $each + ($i < $extra ? 1 : 0);
            }
            $paid = array_sum($shares);
            $row['shifts'][$key] = ['sales' => $s, 'pool' => $pool, 'present' => $ids, 'shares' => $shares, 'unassigned' => $pool - $paid];
            foreach ($shares as $id => $c) {
                $people[$id] ??= ['name' => $names[$id] ?? '(removed)', 'dates' => [], 'morning' => 0, 'afternoon' => 0, 'centavos' => 0];
                $people[$id]['dates'][$d] = true; $people[$id][$key]++; $people[$id]['centavos'] += $c;
            }
            $row['sales'] += $s; $row['pool'] += $pool; $row['unassigned'] += $pool - $paid;
            $tot['sales'] += $s; $tot['pool'] += $pool; $tot['paid'] += $paid; $tot['unassigned'] += $pool - $paid; $tot['shop'] += $s - $pool;
        }
        $days[] = $row;
    }
    foreach ($people as &$p) { $p['days'] = count($p['dates']); unset($p['dates']); }
    unset($p);
    uasort($people, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return ['days' => $days, 'people' => $people, 'totals' => $tot, 'names' => $names];
}

/** The two pay periods of a month ('YYYY-MM'): the 15th (1st-15th) and month end (16th-last day). */
function pay_periods(string $month): array {
    $first = $month . '-01';
    $last = date('Y-m-t', strtotime($first));
    return [
        'first'  => ['from' => $first,            'to' => $month . '-15', 'label' => '15th pay (1st–15th)'],
        'second' => ['from' => $month . '-16',    'to' => $last,          'label' => 'Month-end pay (16th–' . date('jS', strtotime($last)) . ')'],
    ];
}

/** Payouts already recorded for a period: employee id => ['amount' => centavos, 'paid_at' => string]. */
function period_payouts(PDO $pdo, string $from, string $to): array {
    $st = $pdo->prepare('SELECT employee_id, ROUND(amount * 100) c, paid_at FROM payouts WHERE period_start = ? AND period_end = ?');
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st as $r) $out[(int)$r['employee_id']] = ['amount' => (int)$r['c'], 'paid_at' => $r['paid_at']];
    return $out;
}

/** True when this date falls inside a pay period that was already marked as paid (attendance is then locked). */
function date_is_paid(PDO $pdo, string $date): bool {
    $st = $pdo->prepare('SELECT 1 FROM payouts WHERE ? BETWEEN period_start AND period_end LIMIT 1');
    $st->execute([$date]);
    return (bool)$st->fetchColumn();
}

function peso_c(int $centavos): string { return peso($centavos / 100); }
