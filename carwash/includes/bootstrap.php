<?php
require_once __DIR__ . '/../config/config.php';

// Session: the logged-in user is kept in $_SESSION['user'] (id, name, role). The sidebar menu is built from that role.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();

// Database connection lives in its own file: includes/db.php (creates $pdo).
require_once __DIR__ . '/db.php';

// Schema upgrades run once (a marker file records that they finished) instead of on every request.
$__schemaFlag = __DIR__ . '/../config/.schema_ok';
if (!is_file($__schemaFlag)) {
// The shop has MAX_BAYS bays. If an older database has fewer, add the missing ones automatically.
try {
    $have = (int)$pdo->query('SELECT COUNT(*) FROM bays')->fetchColumn();
    for ($i = $have + 1; $i <= MAX_BAYS; $i++) {
        $pdo->prepare('INSERT INTO bays (name) VALUES (?)')->execute(['Bay ' . $i]);
    }
} catch (PDOException $e) { /* bays table missing: schema not imported yet */ }

// Older databases: add the walk-in columns automatically (same idea as the bays check above).
try {
    if (!$pdo->query("SHOW COLUMNS FROM reservations LIKE 'source'")->fetch()) {
        $pdo->exec("ALTER TABLE reservations MODIFY user_id INT NULL,
            ADD COLUMN customer_name VARCHAR(100) DEFAULT NULL AFTER user_id,
            ADD COLUMN customer_phone VARCHAR(30) DEFAULT NULL AFTER customer_name,
            ADD COLUMN source ENUM('online','walkin') NOT NULL DEFAULT 'online' AFTER customer_phone");
    }
} catch (PDOException $e) { /* reservations table missing: schema not imported yet */ }

// Vehicle photos: the shop picks the size from the customer's photos, so vehicle_type may be empty until then.
try {
    if (!$pdo->query("SHOW TABLES LIKE 'reservation_photos'")->fetch()) {
        $pdo->exec("ALTER TABLE reservations MODIFY vehicle_type VARCHAR(20) DEFAULT NULL");
        $pdo->exec("CREATE TABLE reservation_photos ( id INT AUTO_INCREMENT PRIMARY KEY, reservation_id INT NOT NULL, file_name VARCHAR(100) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_res (reservation_id), FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE ) ENGINE=InnoDB");
    }
} catch (PDOException $e) { /* reservations table missing: schema not imported yet */ }

// Customer <-> shop messages: create the table on first use for databases imported before this feature.
try {
    if (!$pdo->query("SHOW TABLES LIKE 'messages'")->fetch()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS messages ( id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, sender_id INT NOT NULL, from_staff TINYINT(1) NOT NULL DEFAULT 0, body VARCHAR(1000) NOT NULL, read_at DATETIME DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_thread (customer_id, id), FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE ) ENGINE=InnoDB");
    }
} catch (PDOException $e) { /* users table missing: schema not imported yet */ }

// Customer reviews: create the table on first use for databases imported before this feature.
try {
    if (!$pdo->query("SHOW TABLES LIKE 'reviews'")->fetch()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS reviews ( id INT AUTO_INCREMENT PRIMARY KEY, reservation_id INT NOT NULL UNIQUE, user_id INT NOT NULL, package_id INT NOT NULL, rating TINYINT NOT NULL, comment VARCHAR(1000) DEFAULT NULL, staff_reply VARCHAR(1000) DEFAULT NULL, is_visible TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_visible (is_visible, id), FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE ) ENGINE=InnoDB");
    }
} catch (PDOException $e) { /* reservations table missing: schema not imported yet */ }

// Saved vehicles (customer profile): create the table on first use for older databases.
try {
    if (!$pdo->query("SHOW TABLES LIKE 'customer_vehicles'")->fetch()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_vehicles ( id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, plate VARCHAR(20) NOT NULL, label VARCHAR(60) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_user_plate (user_id, plate), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ) ENGINE=InnoDB");
    }
} catch (PDOException $e) { /* users table missing: schema not imported yet */ }

// Payroll tables (employees, per-shift attendance, payouts): create / upgrade on first use for databases imported before this feature.
try {
    if (!$pdo->query("SHOW TABLES LIKE 'employees'")->fetch()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS employees ( id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ) ENGINE=InnoDB");
    }
    if (!$pdo->query("SHOW TABLES LIKE 'attendance'")->fetch()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS attendance ( employee_id INT NOT NULL, work_date DATE NOT NULL, shift ENUM('morning','afternoon') NOT NULL DEFAULT 'morning', marked_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (employee_id, work_date, shift), INDEX idx_date (work_date), FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE ) ENGINE=InnoDB");
    } elseif (!$pdo->query("SHOW COLUMNS FROM attendance LIKE 'shift'")->fetch()) {
        // Older attendance (one row per day): add the shift column; a whole-day record becomes morning + afternoon.
        $pdo->exec("ALTER TABLE attendance ADD COLUMN shift ENUM('morning','afternoon') NOT NULL DEFAULT 'morning' AFTER work_date, DROP PRIMARY KEY, ADD PRIMARY KEY (employee_id, work_date, shift)");
        $pdo->exec("INSERT INTO attendance (employee_id, work_date, shift, marked_by) SELECT employee_id, work_date, 'afternoon', marked_by FROM attendance WHERE shift = 'morning'");
    }
    if (!$pdo->query("SHOW TABLES LIKE 'payouts'")->fetch()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS payouts ( id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, amount DECIMAL(10,2) NOT NULL, paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, paid_by INT DEFAULT NULL, UNIQUE KEY uq_period (employee_id, period_start, period_end), INDEX idx_period (period_start, period_end), FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE ) ENGINE=InnoDB");
    }
} catch (PDOException $e) { /* schema not imported yet */ }

// Online payment / cash handling: add the new columns and the online_payments table on first use for older databases.
try {
    if (!$pdo->query("SHOW COLUMNS FROM reservations LIKE 'payment_mode'")->fetch()) {
        $pdo->exec("ALTER TABLE reservations ADD COLUMN payment_mode ENUM('shop','online') NOT NULL DEFAULT 'shop' AFTER source");
    }
    if (!$pdo->query("SHOW COLUMNS FROM payments LIKE 'prepaid'")->fetch()) {
        $pdo->exec("ALTER TABLE payments ADD COLUMN prepaid DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER amount, ADD COLUMN cash_received DECIMAL(10,2) DEFAULT NULL AFTER method, ADD COLUMN change_given DECIMAL(10,2) DEFAULT NULL AFTER cash_received, ADD COLUMN reference_no VARCHAR(40) DEFAULT NULL AFTER change_given");
    }
    if (!$pdo->query("SHOW TABLES LIKE 'online_payments'")->fetch()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS online_payments ( id INT AUTO_INCREMENT PRIMARY KEY, reservation_id INT NOT NULL, kind ENUM('deposit','full') NOT NULL, amount DECIMAL(10,2) NOT NULL, reference_no VARCHAR(40) NOT NULL, proof_file VARCHAR(100) DEFAULT NULL, status ENUM('pending','verified','rejected','void','refund_due','refunded','forfeited') NOT NULL DEFAULT 'pending', note VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, verified_at DATETIME DEFAULT NULL, verified_by INT DEFAULT NULL, resolved_at DATETIME DEFAULT NULL, INDEX idx_res (reservation_id), INDEX idx_status (status), FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE ) ENGINE=InnoDB");
    }
} catch (PDOException $e) { /* schema not imported yet */ }


// Older databases: notification text was VARCHAR(255) and long messages failed.
try {
    $len = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'message'")->fetchColumn();
    if ($len !== false && (int)$len < 500) $pdo->exec("ALTER TABLE notifications MODIFY message VARCHAR(500) NOT NULL");
} catch (PDOException $e) { /* schema not imported yet */ }

    try {
        if ($pdo->query("SHOW TABLES LIKE 'online_payments'")->fetch() && $pdo->query("SHOW TABLES LIKE 'users'")->fetch()) @file_put_contents($__schemaFlag, date('c'));
    } catch (PDOException $e) { /* try again next request */ }
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function url(string $path = ''): string { return BASE_URL . '/' . ltrim($path, '/'); }
/** Asset URL with a version stamp so browsers never reuse an outdated cached file. */
function asset(string $path): string {
    $f = __DIR__ . '/../' . ltrim($path, '/');
    return url($path) . (is_file($f) ? '?v=' . filemtime($f) : '');
}
/** Small inline line icons (feather-style) for the info bar. */
function icon(string $name): string {
    $paths = [
        'pin'   => '<path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
        'mail'  => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'bay'       => '<path d="M3 21V8l9-5 9 5v13"/><path d="M8 21v-8h8v8"/>',
        'box'       => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'tag'       => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
        'chart'     => '<path d="M3 3v18h18"/><path d="M8 17v-5M13 17V8M18 17v-9"/>',
        'queue'     => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
        'car'       => '<path d="M5 17H3v-5l2-5h14l2 5v5h-2"/><circle cx="7.5" cy="17" r="2"/><circle cx="16.5" cy="17" r="2"/><path d="M3 12h18"/>',
        'plus'      => '<circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/>',
        'list'      => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="m3.5 6 1 1 2-2M3.5 12l1 1 2-2M3.5 18l1 1 2-2"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'menu'      => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'lock'      => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'eye'       => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'arrow-right' => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'receipt'   => '<path d="M4 2v20l3-2 2.5 2L12 20l2.5 2L17 20l3 2V2l-3 2-2.5-2L12 4 9.5 2 7 4z"/><path d="M8 9h8M8 13h8"/>',
        'user'      => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'drop'      => '<path d="M12 2.7s7 7.2 7 12a7 7 0 0 1-14 0c0-4.8 7-12 7-12z"/>',
        'star-fill' => '<path fill="currentColor" d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
        'star'      => '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
        'bike'      => '<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6h-3l-3 6 3.5 5.5M5.5 17.5 9 12h8l1.5 5.5M15 6l1 3"/>',
        'nav'       => '<path d="m3 11 19-9-9 19-2-8z"/>',
        'chat'      => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'send'      => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
        'arrow-left' => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'bell'      => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'refresh'   => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'printer'   => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}
/** Standard page header used on every sidebar page: serif title, one-line subtitle, optional action buttons (HTML). */
function page_head(string $title, string $sub = '', string $actions = '', string $id = ''): void {
    echo '<div class="page-head"><div class="page-head-text"><h1' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . e($title) . '</h1>'
        . ($sub !== '' ? '<p class="page-sub">' . $sub . '</p>' : '') . '</div>'
        . ($actions !== '' ? '<div class="page-actions">' . $actions . '</div>' : '') . '</div>';
}
function hours_label(): string {
    return date('g:i A', strtotime(sprintf('%02d:00', OPEN_HOUR))) . ' – ' . date('g:i A', strtotime(sprintf('%02d:00', CLOSE_HOUR)));
}
function redirect(string $path): never { header('Location: ' . url($path)); exit; }
/** Make a text value safe for CSV/Excel: a leading = + - @ would otherwise run as a formula. */
function csv_safe($v) { return is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? chr(39) . $v : $v; }
function peso($n): string { return '₱' . number_format((float)$n, 2); }

// ---- Flash messages
function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

// ---- CSRF
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Invalid or expired form token. Go back and try again.');
    }
}

// ---- Auth / roles
// Roles that get the admin panel. owner, admin and staff all have the same privileges.
const STAFF_ROLES = ['owner', 'admin', 'staff'];
const ADMIN_ROLES = ['owner', 'admin'];   // staff role has everything except Packages and Promos
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): array {
    $u = current_user();
    if (!$u) { flash('error', 'Please log in first.'); redirect('login.php'); }
    return $u;
}
function require_role(array $roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) { http_response_code(403); exit('403 Forbidden'); }
    return $u;
}
/** Sidebar menu for a role (taken from $_SESSION['user']['role']): [label, path, icon]. */
function sidebar_menu(string $role): array {
    if (in_array($role, STAFF_ROLES, true)) {
        $manage = [
            ['Dashboard',       'admin/dashboard.php',    'dashboard'],
            ['Reservations',    'admin/reservations.php', 'calendar'],
            ['Walk-in',         'admin/walkin.php',       'plus'],
            ['Messages',        'admin/messages.php',     'chat'],
            ['Bays',            'admin/bays.php',         'bay'],
            ['Reviews',         'admin/reviews.php',      'star'],
        ];
        // Packages and Promos are for owner/admin only (hidden from the staff role)
        if (in_array($role, ADMIN_ROLES, true)) {
            $manage[] = ['Manage Packages', 'admin/packages.php', 'box'];
            $manage[] = ['Promos',          'admin/promos.php',   'tag'];
            $manage[] = ['Payroll',         'admin/payroll.php',  'receipt'];
        }
        $manage[] = ['Reports', 'admin/reports.php', 'chart'];
        return [
            'Manage' => $manage,
            'Shop' => [
                ['Live Queue',      'queue.php',              'queue'],
            ],
        ];
    }
    return [
        'My account' => [
            ['Book a wash',     'customer/book.php',      'plus'],
            ['My Bookings',     'customer/dashboard.php', 'list'],
            ['Messages',        'customer/messages.php',  'chat'],
            ['My Reviews',      'customer/reviews.php',   'star'],
            ['My Profile',      'customer/profile.php',   'user'],
        ],
        'Shop' => [
            ['Services & Rates', 'index.php',             'car'],
            ['Live Queue',       'queue.php',             'queue'],
        ],
    ];
}
function is_staff(): bool { return in_array(current_user()['role'] ?? '', STAFF_ROLES, true); }

// ---- Notifications (logged in DB; plug in real email/SMS where marked)
function notify(PDO $pdo, ?int $userId, string $message, string $channel = 'app'): void {
    if ($userId === null) return;
    $message = mb_substr($message, 0, 480);   // column is VARCHAR(500)   // walk-in customer without an account: nobody to notify
    $pdo->prepare('INSERT INTO notifications (user_id, channel, message) VALUES (?,?,?)')
        ->execute([$userId, $channel, $message]);
    // TODO: real email  -> mail($email, 'CarWash', $message);
    // TODO: real SMS    -> call your SMS gateway API here (e.g. Semaphore, Twilio).
}


// ---- "Remember me" (persistent login)
// A random selector + validator is stored in a cookie; the DB keeps only a hash of the validator, so a leaked database cannot be used to log in.
function remember_cookie_path(): string { return (BASE_URL !== '' ? BASE_URL : '') . '/'; }
function remember_set_cookie(string $value, int $expires): void {
    setcookie('remember_me', $value, [
        'expires' => $expires, 'path' => remember_cookie_path(), 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}
/** Create the table on first use (older databases were imported before this feature). */
function remember_ensure_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_tokens ( id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, selector CHAR(24) NOT NULL UNIQUE, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id), INDEX idx_exp (expires_at), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ) ENGINE=InnoDB");
    $done = true;
}
/** Give this browser a remember-me cookie for the user. */
function remember_issue(PDO $pdo, int $userId): void {
    try {
        remember_ensure_table($pdo);
        $pdo->exec('DELETE FROM auth_tokens WHERE expires_at < NOW()');
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + REMEMBER_DAYS * 86400;
        $pdo->prepare('INSERT INTO auth_tokens (user_id, selector, token_hash, expires_at) VALUES (?,?,?,?)')
            ->execute([$userId, $selector, hash('sha256', $validator), date('Y-m-d H:i:s', $expires)]);
        remember_set_cookie($selector . ':' . $validator, $expires);
    } catch (PDOException $e) { /* could not save: the user simply stays logged in for this session only */ }
}
/** Remove this browser's remember-me token (logout). */
function remember_forget(PDO $pdo): void {
    $c = $_COOKIE['remember_me'] ?? '';
    if ($c !== '') {
        [$selector] = explode(':', $c, 2);
        try { remember_ensure_table($pdo); $pdo->prepare('DELETE FROM auth_tokens WHERE selector = ?')->execute([$selector]); } catch (PDOException $e) { }
        remember_set_cookie('', time() - 3600);
        unset($_COOKIE['remember_me']);
    }
}
/** Remove every remember-me token of a user (used when the password changes, so other devices are logged out). */
function remember_forget_all(PDO $pdo, int $userId): void {
    try { remember_ensure_table($pdo); $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = ?')->execute([$userId]); } catch (PDOException $e) { }
}
/** No session yet but a valid remember-me cookie: log the user back in. */
function remember_autologin(PDO $pdo): void {
    $c = $_COOKIE['remember_me'] ?? '';
    if (isset($_SESSION['user']) || $c === '' || substr_count($c, ':') !== 1) return;
    [$selector, $validator] = explode(':', $c, 2);
    if (!preg_match('/^[0-9a-f]{24}$/', $selector) || !preg_match('/^[0-9a-f]{64}$/', $validator)) { remember_set_cookie('', time() - 3600); return; }
    try {
        remember_ensure_table($pdo);
        $st = $pdo->prepare('SELECT t.token_hash, u.id, u.name, u.role FROM auth_tokens t JOIN users u ON u.id = t.user_id WHERE t.selector = ? AND t.expires_at > NOW()');
        $st->execute([$selector]);
        $row = $st->fetch();
        if ($row && hash_equals($row['token_hash'], hash('sha256', $validator))) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$row['id'], 'name' => $row['name'], 'role' => $row['role']];
        } else {
            $pdo->prepare('DELETE FROM auth_tokens WHERE selector = ?')->execute([$selector]);   // wrong/expired token: drop it
            remember_set_cookie('', time() - 3600);
        }
    } catch (PDOException $e) { /* ignore: behave as logged out */ }
}
remember_autologin($pdo);
