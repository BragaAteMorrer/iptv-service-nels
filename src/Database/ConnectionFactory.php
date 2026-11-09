<?php
declare(strict_types=1);
namespace IptvService\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/** Parse a private DATABASE_URL without allowing its contents into diagnostic output. */
final class ConnectionFactory
{
    public static function create(): Connection
    {
        $url = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? '');
        if (!is_string($url) || $url === '') {
            throw new \RuntimeException('DATABASE_URL is not configured');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'], $parts['user'])) {
            throw new \RuntimeException('DATABASE_URL is malformed');
        }
        $driver = match ($parts['scheme']) {
            'mysql', 'mariadb', 'pdo_mysql' => 'pdo_mysql',
            'postgres', 'postgresql', 'pdo_pgsql' => 'pdo_pgsql',
            default => throw new \RuntimeException('Unsupported database scheme'),
        };
        $dbname = rawurldecode(ltrim($parts['path'], '/'));
        if ($dbname === '') {
            throw new \RuntimeException('DATABASE_URL has no database name');
        }
        $options = [
            'driver' => $driver,
            'host' => $parts['host'],
            'port' => $parts['port'] ?? ($driver === 'pdo_mysql' ? 3306 : 5432),
            'dbname' => $dbname,
            'user' => rawurldecode($parts['user']),
            'password' => rawurldecode($parts['pass'] ?? ''),
        ];
        if ($driver === 'pdo_mysql') {
            $options['charset'] = 'utf8mb4';
        }
        return DriverManager::getConnection($options);
    }
}
