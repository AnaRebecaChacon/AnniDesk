<?php
declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: '';
    $user = getenv('DB_USER') ?: '';
    $password = getenv('DB_PASSWORD');

    if ($name === '' || $user === '' || $password === false || $password === '') {
        throw new RuntimeException('La configuración de la base de datos está incompleta.');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
