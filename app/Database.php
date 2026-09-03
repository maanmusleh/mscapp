<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $database = config('database');

        try {
            self::$connection = new PDO(
                (string) $database['dsn'],
                (string) $database['username'],
                (string) $database['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'CAT could not connect to its database. Check the CAT_DB_* settings.',
                0,
                $exception
            );
        }

        return self::$connection;
    }
}

