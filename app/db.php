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


/**
 * Best-effort column existence check for MySQL/InnoDB.
 * Used to keep upgrades non-breaking when optional migrations haven't been applied yet.
 */
function db_has_column(string $table, string $column): bool {
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t AND COLUMN_NAME = :c');
        $stmt->execute([':s'=>cfg('DB_NAME'), ':t'=>$table, ':c'=>$column]);
        $row = $stmt->fetch();
        return (int)($row['c'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}
