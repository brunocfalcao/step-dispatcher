<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Collision;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Connection and runtime settings shared by the collision test case and the
 * worker processes. The database name is fixed. Only the server type, its
 * address and the credentials may come from the environment, so no run can
 * reach another database through configuration.
 *
 * MySQL is the default. STEP_DISPATCHER_COLLISION_DRIVER=pgsql runs the same
 * tests on Postgres, where a failed statement aborts its whole transaction.
 */
final class CollisionEnvironment
{
    public const DATABASE = 'step_dispatcher_collision_tests';

    public const CONNECTION = 'collision';

    public static function driver(): string
    {
        return getenv('STEP_DISPATCHER_COLLISION_DRIVER') === 'pgsql' ? 'pgsql' : 'mysql';
    }

    public static function usesPostgres(): bool
    {
        return self::driver() === 'pgsql';
    }

    public static function host(): string
    {
        $value = getenv('STEP_DISPATCHER_COLLISION_HOST');

        return $value === false || $value === '' ? '127.0.0.1' : $value;
    }

    public static function port(): int
    {
        $value = getenv('STEP_DISPATCHER_COLLISION_PORT');

        if ($value !== false && $value !== '') {
            return (int) $value;
        }

        return self::usesPostgres() ? 5432 : 3306;
    }

    public static function username(): string
    {
        $value = getenv('STEP_DISPATCHER_COLLISION_USER');

        if ($value !== false && $value !== '') {
            return $value;
        }

        return self::usesPostgres() ? get_current_user() : 'root';
    }

    public static function password(): string
    {
        $value = getenv('STEP_DISPATCHER_COLLISION_PASSWORD');

        return $value === false ? '' : $value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function connectionConfig(): array
    {
        if (self::usesPostgres()) {
            return [
                'driver' => 'pgsql',
                'host' => self::host(),
                'port' => self::port(),
                'database' => self::DATABASE,
                'username' => self::username(),
                'password' => self::password(),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => self::host(),
            'port' => self::port(),
            'database' => self::DATABASE,
            'username' => self::username(),
            'password' => self::password(),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ];
    }

    /**
     * Point the application at the collision database. File-based cache and
     * flag paths are shared by every worker process, so they must live in a
     * directory all of them can see.
     */
    public static function configure(Application $app, string $workDirectory): void
    {
        $config = $app['config'];

        $config->set('database.default', self::CONNECTION);
        $config->set('database.connections.'.self::CONNECTION, self::connectionConfig());

        $config->set('queue.default', 'sync');

        $config->set('cache.default', 'file');
        $config->set('cache.stores.file', [
            'driver' => 'file',
            'path' => $workDirectory.'/cache',
            'lock_path' => $workDirectory.'/cache',
        ]);

        $config->set('step-dispatcher.flag_path', $workDirectory.'/flag');
    }

    public static function serverDsn(): string
    {
        if (self::usesPostgres()) {
            return sprintf('pgsql:host=%s;port=%d;dbname=postgres', self::host(), self::port());
        }

        return sprintf('mysql:host=%s;port=%d;charset=utf8mb4', self::host(), self::port());
    }

    /**
     * Null when the server answers, otherwise the reason it does not.
     */
    public static function unreachableReason(): ?string
    {
        try {
            $pdo = new PDO(self::serverDsn(), self::username(), self::password(), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ]);
            $pdo->query('SELECT 1');

            return null;
        } catch (PDOException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Creates the collision database when it is missing. Nothing else on the
     * server is touched.
     */
    public static function ensureDatabaseExists(): void
    {
        $pdo = new PDO(self::serverDsn(), self::username(), self::password(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        if (self::usesPostgres()) {
            $exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = '".self::DATABASE."'")->fetchColumn();

            if ($exists === false) {
                $pdo->exec('CREATE DATABASE "'.self::DATABASE.'"');
            }

            return;
        }

        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.self::DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /**
     * Refuses to rebuild tables unless both the configured connection and the
     * server session name the collision database.
     */
    public static function assertConnectedToCollisionDatabase(Connection $connection): void
    {
        $configured = $connection->getDatabaseName();
        $session = (string) $connection->selectOne(
            self::usesPostgres() ? 'SELECT current_database() AS name' : 'SELECT DATABASE() AS name'
        )->name;

        if ($configured !== self::DATABASE || $session !== self::DATABASE) {
            throw new RuntimeException(sprintf(
                'Refusing to rebuild tables: connection targets "%s" and the server session reports "%s", expected "%s" for both.',
                $configured,
                $session,
                self::DATABASE,
            ));
        }
    }
}
