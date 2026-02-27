<?php
/**
 * =============================================================================
 * VerdictTrace - MySQL Database Helper
 * =============================================================================
 * Provides a singleton PDO connection and common query helper functions.
 * All MySQL interactions in the application go through this file.
 * =============================================================================
 */

require_once __DIR__ . '/config.php';

/**
 * Get or create the singleton PDO database connection.
 *
 * @return PDO
 */
function db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // Throw exceptions on errors
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Return associative arrays
                PDO::ATTR_EMULATE_PREPARES   => false,                   // Use real prepared statements
            ]);
        } catch (PDOException $e) {
            die('<h2>Database Connection Failed</h2><p>' . htmlspecialchars($e->getMessage()) . '</p>');
        }
    }

    return $pdo;
}

/**
 * Execute a SELECT query and return all rows.
 *
 * @param string $sql    SQL query with named placeholders
 * @param array  $params Associative array of parameters
 * @return array
 */
function db_select(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Execute a SELECT query and return a single row.
 *
 * @param string $sql    SQL query with named placeholders
 * @param array  $params Associative array of parameters
 * @return array|null
 */
function db_select_one(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Execute an INSERT, UPDATE, or DELETE query.
 *
 * @param string $sql    SQL query with named placeholders
 * @param array  $params Associative array of parameters
 * @return int           Number of affected rows
 */
function db_execute(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Insert a row and return the last insert ID.
 *
 * @param string $sql    SQL INSERT query with named placeholders
 * @param array  $params Associative array of parameters
 * @return string        Last inserted ID
 */
function db_insert(string $sql, array $params = []): string {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return db()->lastInsertId();
}
