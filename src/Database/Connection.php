<?php

declare(strict_types=1);

namespace App\Database;

use App\Exception\DatabaseException;

final class Connection
{
    private static ?\PDO $connection = null;

    private function __construct()
    {
    }

    public static function getInstance(): \PDO
    {
        if (self::$connection instanceof \PDO) {
            return self::$connection;
        }

        $host = trim((string) ($_ENV['DB_HOST'] ?? ''));
        $database = trim((string) ($_ENV['DB_NAME'] ?? ''));
        $user = (string) ($_ENV['DB_USER'] ?? '');
        $password = (string) ($_ENV['DB_PASS'] ?? '');

        if ($host === '' || $database === '') {
            throw new DatabaseException(
                'MySQL configuration is incomplete. Configure DB_HOST and DB_NAME.'
            );
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $host,
            $database
        );

        try {
            self::$connection = new \PDO(
                $dsn,
                $user,
                $password,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (\PDOException $exception) {
            throw new DatabaseException(
                'Unable to connect to MySQL.',
                0,
                $exception
            );
        }

        return self::$connection;
    }
}
