<?php

declare(strict_types=1);

namespace Ramon\MailReply\Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule; /* harness de teste standalone, sem boot do Flarum; nosemgrep: flarum-v2-capsule-manager */
use Illuminate\Database\Connection;
use Illuminate\Events\Dispatcher;
use PDO;

/**
 * Conexões Eloquent (Capsule) para cada engine contra a qual as migrations
 * rodam. Engines de servidor vêm de env vars no formato `chave=valor;...`:
 *
 *     MAIL_REPLY_TEST_PGSQL=host=127.0.0.1;port=5432;username=postgres;password=;database=mail_reply_test
 *
 * Engine sem env var (ou servidor inacessível) é reportada indisponível e o
 * teste é pulado; SQLite em memória roda sempre. Os servidores usam um banco
 * DEDICADO criado sob demanda — nunca uma instalação real.
 */
final class Database
{
    public const ALL = ['sqlite', 'mysql', 'mariadb', 'pgsql'];

    private const DB_NAME = 'mail_reply_test';

    /** @var array<string, Connection|false> */
    private static array $cache = [];

    private static ?Capsule $capsule = null;

    /**
     * Devolve a conexão da engine e a torna default do Eloquent, ou null
     * quando a engine não está disponível.
     */
    public static function use(string $engine): ?Connection
    {
        $conn = self::connection($engine);
        if ($conn !== null) {
            self::capsule()->getDatabaseManager()->setDefaultConnection($engine);
        }

        return $conn;
    }

    public static function connection(string $engine): ?Connection
    {
        if (array_key_exists($engine, self::$cache)) {
            return self::$cache[$engine] ?: null;
        }

        try {
            $conn = self::make($engine);
            $conn->select('SELECT 1');
            self::$cache[$engine] = $conn;

            return $conn;
        } catch (\Throwable) {
            self::$cache[$engine] = false;

            return null;
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function engines(): array
    {
        $out = [];
        foreach (self::ALL as $engine) {
            $out[$engine] = [$engine];
        }

        return $out;
    }

    private static function capsule(): Capsule
    {
        if (self::$capsule === null) {
            $c = new Capsule();
            $c->setEventDispatcher(new Dispatcher(new Container()));
            $c->setAsGlobal();
            $c->bootEloquent();
            self::$capsule = $c;
        }

        return self::$capsule;
    }

    private static function make(string $engine): Connection
    {
        $config = match ($engine) {
            'sqlite'  => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'mysql'   => self::serverConfig('mysql', 'mysql', 3306),
            'mariadb' => self::serverConfig('mariadb', 'mariadb', 3306),
            'pgsql'   => self::serverConfig('pgsql', 'pgsql', 5432),
            default   => throw new \InvalidArgumentException("Unknown engine: $engine"),
        };

        $capsule = self::capsule();
        $capsule->addConnection($config, $engine);
        $conn = $capsule->getConnection($engine);

        if ($engine === 'sqlite') {
            $conn->statement('PRAGMA foreign_keys = ON');
        }

        return $conn;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serverConfig(string $engine, string $driver, int $defaultPort): array
    {
        $raw = getenv('MAIL_REPLY_TEST_'.strtoupper($engine));
        if ($raw === false || trim($raw) === '') {
            throw new \RuntimeException("Engine $engine not configured");
        }

        $parts = [];
        foreach (explode(';', $raw) as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            $parts[trim($k)] = trim($v);
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int) ($parts['port'] ?? $defaultPort);
        $user = $parts['username'] ?? ($parts['user'] ?? 'root');
        $pass = $parts['password'] ?? ($parts['pass'] ?? '');
        $db   = $parts['database'] ?? ($parts['db'] ?? self::DB_NAME);

        self::ensureDatabase($driver, $host, $port, $user, $pass, $db);

        $config = [
            'driver'   => $driver,
            'host'     => $host,
            'port'     => $port,
            'database' => $db,
            'username' => $user,
            'password' => $pass,
            'prefix'   => '',
        ];

        if ($driver === 'pgsql') {
            $config['charset'] = 'utf8';
            $config['schema']  = 'public';
        } else {
            $config['charset']   = 'utf8mb4';
            $config['collation'] = 'utf8mb4_unicode_ci';
        }

        return $config;
    }

    /**
     * Cria o banco de teste se faltar. O nome passa por allowlist estrita
     * porque `CREATE DATABASE` não aceita bind de parâmetro.
     */
    private static function ensureDatabase(string $driver, string $host, int $port, string $user, string $pass, string $db): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $db)) {
            throw new \RuntimeException("Unsafe test database name: $db");
        }

        if ($driver === 'pgsql') {
            $pdo = new PDO("pgsql:host=$host;port=$port;dbname=postgres", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $exists = $pdo->query('SELECT 1 FROM pg_database WHERE datname = '.$pdo->quote($db))->fetchColumn();
            if (! $exists) {
                $pdo->exec("CREATE DATABASE \"$db\" ENCODING 'UTF8'");
            }

            return;
        }

        $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
}
