<?php
/**
 * Single shared PDO connection. Every query in the application goes
 * through prepared statements, which is the main defence against SQL
 * injection.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>PrintBoss</title></head><body style="font-family:sans-serif;padding:40px;background:#1b1917;color:#eee">';
        echo '<h1>Database connection failed</h1>';
        echo '<p>PrintBoss could not connect to MySQL. Make sure WAMP/MySQL is running and that <code>database/printboss.sql</code> has been imported.</p>';
        echo '<p>Check the credentials in <code>includes/config.php</code>.</p>';
        echo '</body></html>';
        exit;
    }
    return $pdo;
}

/** Run a prepared statement and return it. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Fetch all rows. */
function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Fetch a single row or null. */
function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

/** Fetch a single scalar value. */
function scalar(string $sql, array $params = [])
{
    return q($sql, $params)->fetchColumn();
}
