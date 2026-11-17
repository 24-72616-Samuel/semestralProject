<?php
// Rate-card helpers: vehicle sizes, packages with per-size prices.

/** Vehicle size key => [label, category]. Category matches packages.category. */
function vehicle_sizes(): array {
    return [
        'small'      => ['Small',          'car'],
        'medium'     => ['Medium',         'car'],
        'large'      => ['Large',          'car'],
        'xlarge'     => ['X-Large',        'car'],
        'xxl'        => ['XXL',            'car'],
        'moto_small' => ['Motorcycle - Small', 'motorcycle'],
        'moto_large' => ['Motorcycle - Large', 'motorcycle'],
        'ebike'      => ['E-Bike',         'motorcycle'],
        'tricycle'   => ['Tricycle',       'motorcycle'],
        'jetski'     => ['Jet Ski',        'motorcycle'],
        'ufo'        => ['UFO / Helicopter', 'special'],
    ];
}

function category_labels(): array {
    return ['car' => 'Car', 'motorcycle' => 'Motorcycle & Others', 'special' => 'Special'];
}

function sizes_for(string $category): array {
    return array_filter(vehicle_sizes(), fn($s) => $s[1] === $category);
}

function vehicle_label(?string $key): string {
    if ($key === null || $key === '') return 'Size not set yet';
    return vehicle_sizes()[$key][0] ?? ucfirst($key);
}

/** Packages (optionally only active) each with ['prices' => [size => price]]. */
function load_packages(PDO $pdo, bool $activeOnly = true): array {
    $sql = 'SELECT * FROM packages' . ($activeOnly ? ' WHERE is_active = 1' : '') . " ORDER BY FIELD(category,'car','motorcycle','special'), id";
    $pkgs = [];
    foreach ($pdo->query($sql)->fetchAll() as $p) { $p['prices'] = []; $pkgs[(int)$p['id']] = $p; }
    foreach ($pdo->query('SELECT package_id, size, price FROM package_prices')->fetchAll() as $r) {
        if (isset($pkgs[(int)$r['package_id']])) $pkgs[(int)$r['package_id']]['prices'][$r['size']] = (float)$r['price'];
    }
    return $pkgs;
}

/** "₱180 – ₱300", or "Starts at ₱300" for starting-price services. */
function price_label(array $pkg): string {
    $v = array_values($pkg['prices'] ?? []);
    if (!$v) return '—';
    $min = min($v); $max = max($v);
    if (!empty($pkg['price_is_starting'])) return 'Starts at ' . peso($min);
    return $min === $max ? peso($min) : peso($min) . ' – ' . peso($max);
}
