<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

/*
|--------------------------------------------------------------------------
| RES-01 / CFG-06: DB connect timeout + web-only per-statement cap
|--------------------------------------------------------------------------
|
| PDO::ATTR_TIMEOUT (seconds) is a CONNECT-time-only cap — PDO/libmysqlclient stop honouring it once
| the TCP handshake finishes, so it bounds "the DB host is down or unreachable", not a slow query on
| an already-open connection. It never needs to differ between a CLI process and a web request, so
| it is safe to bake into the static config below even if `php artisan config:cache` is ever added
| to the deploy (see the caveat further down).
|
| The per-statement cap is different: it sets MySQL's session `MAX_EXECUTION_TIME` variable
| (milliseconds), which the SERVER enforces only on read-only SELECT statements — INSERT / UPDATE /
| DELETE / DDL silently ignore it, so it can never truncate a write mid-transaction (MySQL 8 manual,
| "MAX_EXECUTION_TIME optimizer hint / session variable"). It is applied via
| PDO::MYSQL_ATTR_INIT_COMMAND. Laravel's own MySqlConnector::configureConnection() issues its SET
| NAMES / time_zone / sql_mode via a separate ->exec() call made AFTER the connection opens (see
| vendor/laravel/framework/src/Illuminate/Database/Connectors/MySqlConnector.php) — it never reads or
| sets PDO::MYSQL_ATTR_INIT_COMMAND itself, so this does not clobber or get clobbered by it; PDO runs
| the init command once, immediately after connecting, before that later ->exec() call.
|
| It is WEB-ONLY and hard-off in every CLI process: artisan, the host-cron scheduler, legacy:import
| and the whole PHPUnit suite all run under PHP_SAPI === 'cli', so a long operator job is never cut
| off by a budget sized for an interactive page load. DB_WEB_MAX_EXECUTION_MS=0 disables it outright
| even for web requests. It is added only to the live 'mysql' connection — never to 'mariadb' (which
| this app's DB_CONNECTION never actually selects; MariaDB has no MAX_EXECUTION_TIME session variable
| — it uses the differently-scaled `max_statement_time` instead) and never to the read-only 'legacy'
| connection used by `legacy:import`, which must stay unaffected.
|
| CAVEAT: `php artisan config:cache` evaluates this file once, under the CLI SAPI that ran the
| command, and bakes the resulting array to disk — so if config:cache is ever added to the normal
| deploy path (it is NOT today; docs/DEPLOY-LARAVEL.md's deploy has no config:cache step — only the
| manual DR restore steps in docs/compliance/INCIDENT-RESPONSE.md run it), the PHP_SAPI check below
| would be frozen at its CLI (disabled) value for web requests too. If config:cache is introduced,
| this needs to move to a boot-time override (the RuntimeConfigServiceProvider pattern) instead of a
| static config value.
|
*/
$dbConnectTimeoutSeconds = (int) env('DB_CONNECT_TIMEOUT', 5);
$dbWebMaxExecutionMs = (int) env('DB_WEB_MAX_EXECUTION_MS', 60000);
$dbWebMaxExecutionInitCommand = $dbWebMaxExecutionMs > 0
    ? sprintf('SET SESSION MAX_EXECUTION_TIME=%d', $dbWebMaxExecutionMs)
    : null;
// Never applied under the CLI (artisan / scheduler / legacy:import / the PHPUnit suite) — see above.
$dbApplyWebStatementCap = PHP_SAPI !== 'cli' && $dbWebMaxExecutionInitCommand !== null;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                PDO::ATTR_TIMEOUT => $dbConnectTimeoutSeconds,
                PDO::MYSQL_ATTR_INIT_COMMAND => $dbApplyWebStatementCap ? $dbWebMaxExecutionInitCommand : null,
            ]) : [],
        ],

        // Read-only connection to the ORIGINAL renovated app database (dmc/dmc_prod).
        // Used by `php artisan legacy:import` to transform legacy rows into the new clean schema.
        'legacy' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('LEGACY_DB_DATABASE', 'dmc_prod'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
            'engine' => null,
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                // Connect timeout only — no MAX_EXECUTION_TIME statement cap here; see the file-top
                // comment (MariaDB doesn't have that session variable, and this app never selects
                // this connection anyway).
                PDO::ATTR_TIMEOUT => $dbConnectTimeoutSeconds,
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | RES-01 / CFG-06 diagnostics (not read by any DB connector)
    |--------------------------------------------------------------------------
    |
    | Laravel's connectors only ever look inside `connections.*` — this key is never consumed by
    | anything. It exposes the raw, pre-CLI-gate web-statement-cap values (see the comment at the top
    | of this file) purely so tests — and anyone debugging why queries do or don't appear capped —
    | can see what DB_WEB_MAX_EXECUTION_MS resolved to without needing a non-CLI PHP process. The
    | actual CLI-vs-web gate is exercised for real by asserting on
    | config('database.connections.mysql.options') during the (CLI-run) test suite, where the init
    | command must always be absent.
    |
    */
    'web_statement_cap' => [
        'ms' => $dbWebMaxExecutionMs,
        'init_command' => $dbWebMaxExecutionInitCommand,
    ],

];
