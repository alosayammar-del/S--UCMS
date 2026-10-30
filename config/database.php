<?php

$host = getenv('TIDB_HOST');
$port = getenv('TIDB_PORT');
$dbname = getenv('TIDB_DATABASE');
$username = getenv('TIDB_USER');
$password = getenv('TIDB_PASSWORD');

$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    exit('Database connection failed.');
}