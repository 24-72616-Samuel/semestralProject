<?php
// Helpers for customer feedback & ratings.

/** Star row (5 stars, $rating filled). $rating may be a decimal average. */
function stars_html($rating, string $size = ''): string {
    $r = max(0, min(5, (float)$rating));
    $out = '<span class="stars ' . e($size) . '" role="img" aria-label="' . e(number_format($r, 1)) . ' out of 5 stars">';
    for ($i = 1; $i <= 5; $i++) {
        $out .= '<span class="star' . ($r >= $i - 0.25 ? ' on' : '') . '">' . icon('star-fill') . '</span>';
    }
    return $out . '</span>';
}

/** Average, count and per-star counts for visible reviews (or all, when $onlyVisible is false). */
function review_stats(PDO $pdo, bool $onlyVisible = true): array {
    $where = $onlyVisible ? 'WHERE is_visible = 1' : '';
    $rows = $pdo->query("SELECT rating, COUNT(*) n FROM reviews $where GROUP BY rating")->fetchAll();
    $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]; $sum = 0; $count = 0;
    foreach ($rows as $r) { $dist[(int)$r['rating']] = (int)$r['n']; $sum += $r['rating'] * $r['n']; $count += $r['n']; }
    return ['avg' => $count ? $sum / $count : 0, 'count' => $count, 'dist' => $dist];
}

/** "Maria S." style name so the public page doesn't show full customer names. */
function public_name(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $first = $parts[0] ?? 'Customer';
    return count($parts) > 1 ? $first . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.' : $first;
}

/** Clamp a posted rating to 1..5, or 0 when invalid. */
function clean_rating($v): int { $n = (int)$v; return ($n >= 1 && $n <= 5) ? $n : 0; }
