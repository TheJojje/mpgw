<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db;

use PDO;
use PDOException;
use RuntimeException;

final class PdoConnectionFactory
{
    /**
     * @param array{host:string,port:int,database:string,username:string,password:string} $config
     */
    public static function create(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        try {
            return new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Kunde inte ansluta till databasen: ' . $e->getMessage(), 0, $e);
        }
    }
}
