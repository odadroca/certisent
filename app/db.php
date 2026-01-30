<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', cfg('DB_HOST'), cfg('DB_NAME'));
    $pdo = new PDO($dsn, cfg('DB_USER'), cfg('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function db_now_utc(): string {
    return gmdate('Y-m-d H:i:s');
}
