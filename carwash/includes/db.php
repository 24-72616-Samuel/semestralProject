<?php
/**
 * DATABASE CONNECTION
 * -------------------
 * Method: PDO (PHP Data Objects) with the MySQL driver (pdo_mysql).
 * The credentials (host, database name, user, password) come from config/config.php.
 *
 * Every page loads this file through includes/bootstrap.php, so $pdo is available everywhere.
 * All queries use PDO prepared statements ($pdo->prepare(...)->execute([...])),
 * which protects the system against SQL injection.
 */
require_once __DIR__ . '/../config/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',   // DSN: driver, server, database, charset
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // throw exceptions on SQL errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // rows come back as associative arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                    // use real prepared statements
        ]
    );
    // Keep MySQL's clock on shop time (PHP already uses Asia/Manila) so CURDATE(), NOW() and paid_at match on any host.
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Check config/config.php and that MySQL is running.');
}
