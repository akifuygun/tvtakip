<?php
require_once __DIR__ . '/../config.php';

function db(bool $reconnect = false): PDO
{
    static $pdo = null;
    if ($pdo === null || $reconnect) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

/**
 * Return a known-live connection, reconnecting if the server dropped an idle
 * one (MySQL error 2006 "server has gone away"). Call this before writing
 * after a long non-DB pause, e.g. a slow provider fetch during an import.
 */
function db_live(): PDO
{
    try {
        db()->query('SELECT 1');
        return db();
    } catch (PDOException $e) {
        return db(true);
    }
}
