<?php

namespace Tests\Feature;

use PDO;
use Pdo\Mysql;
use Tests\TestCase;

/**
 * RES-01 / CFG-06: DB connect timeout + the web-only MAX_EXECUTION_TIME statement cap
 * (config/database.php). Two things matter most and are proven directly:
 *
 *  1. Under the CLI (which is exactly what THIS test itself, PHPUnit, artisan, the scheduler and
 *     legacy:import all run as) the statement cap must be COMPLETELY ABSENT from the resolved
 *     'mysql' connection options — an operator job (import, backup, report render) must never be
 *     cut off. This is asserted against the app's real, booted config() — the same array
 *     Illuminate\Database\Connectors\MySqlConnector actually receives.
 *  2. The env-driven VALUES (connect timeout seconds, and the MAX_EXECUTION_TIME milliseconds /
 *     the exact SQL text it formats to / the "0 disables it" rule) are correct. PHP_SAPI can't be
 *     flipped to 'cli-vs-web' mid-process, so these are proven by re-`require`-ing
 *     config/database.php directly (not through Laravel's cached config) with controlled env vars —
 *     the same technique `php artisan config:cache` itself uses to evaluate config files. Every
 *     `require` (not `require_once`) re-executes the file's env() calls fresh against whatever
 *     putenv() set immediately before it.
 */
class DatabaseTimeoutsConfigTest extends TestCase
{
    private function requireConfigWithEnv(array $env): array
    {
        $previous = [];
        foreach ($env as $key => $value) {
            $previous[$key] = getenv($key);
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            return require base_path('config/database.php');
        } finally {
            foreach ($env as $key => $ignored) {
                if ($previous[$key] === false) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                } else {
                    putenv("{$key}={$previous[$key]}");
                    $_ENV[$key] = $previous[$key];
                    $_SERVER[$key] = $previous[$key];
                }
            }
        }
    }

    public function test_the_web_statement_cap_is_never_present_while_running_under_the_cli(): void
    {
        // The real, booted app config — exactly what MySqlConnector::connect() receives. PHPUnit
        // (like artisan, the scheduler and legacy:import) always runs as PHP_SAPI === 'cli'.
        $this->assertSame('cli', PHP_SAPI);

        $options = config('database.connections.mysql.options');
        $this->assertIsArray($options);
        $this->assertArrayNotHasKey(
            PDO::MYSQL_ATTR_INIT_COMMAND,
            $options,
            'the MAX_EXECUTION_TIME statement cap must never be installed while running under the CLI'
        );

        // The connect timeout, by contrast, is NOT CLI-gated — it applies everywhere.
        $this->assertArrayHasKey(PDO::ATTR_TIMEOUT, $options);
        $this->assertSame(5, $options[PDO::ATTR_TIMEOUT], 'default DB_CONNECT_TIMEOUT is 5 seconds');
    }

    public function test_the_legacy_readonly_connection_is_unaffected(): void
    {
        $legacy = config('database.connections.legacy');
        $this->assertIsArray($legacy);
        $this->assertArrayNotHasKey(
            'options',
            $legacy,
            'legacy:import must never inherit a connect timeout or statement cap from the mysql connection'
        );
    }

    public function test_the_mariadb_connection_gets_the_connect_timeout_but_never_the_statement_cap(): void
    {
        $options = config('database.connections.mariadb.options');
        $this->assertIsArray($options);
        $this->assertArrayHasKey(PDO::ATTR_TIMEOUT, $options);
        $this->assertSame(5, $options[PDO::ATTR_TIMEOUT]);
        // MariaDB has no MAX_EXECUTION_TIME session variable — never add the MySQL-specific cap here.
        $this->assertArrayNotHasKey(PDO::MYSQL_ATTR_INIT_COMMAND, $options);
    }

    public function test_connect_timeout_defaults_to_five_seconds_and_reads_the_env_override(): void
    {
        $default = $this->requireConfigWithEnv([]);
        $this->assertSame(5, $default['connections']['mysql']['options'][PDO::ATTR_TIMEOUT]);
        $this->assertSame(5, $default['connections']['mariadb']['options'][PDO::ATTR_TIMEOUT]);

        $overridden = $this->requireConfigWithEnv(['DB_CONNECT_TIMEOUT' => '12']);
        $this->assertSame(12, $overridden['connections']['mysql']['options'][PDO::ATTR_TIMEOUT]);
        $this->assertSame(12, $overridden['connections']['mariadb']['options'][PDO::ATTR_TIMEOUT]);
    }

    public function test_web_max_execution_ms_defaults_to_sixty_seconds_and_formats_the_init_command(): void
    {
        $default = $this->requireConfigWithEnv([]);
        $this->assertSame(60000, $default['web_statement_cap']['ms']);
        $this->assertSame('SET SESSION MAX_EXECUTION_TIME=60000', $default['web_statement_cap']['init_command']);

        $overridden = $this->requireConfigWithEnv(['DB_WEB_MAX_EXECUTION_MS' => '15000']);
        $this->assertSame(15000, $overridden['web_statement_cap']['ms']);
        $this->assertSame('SET SESSION MAX_EXECUTION_TIME=15000', $overridden['web_statement_cap']['init_command']);
    }

    public function test_web_max_execution_ms_zero_disables_the_cap_outright(): void
    {
        $disabled = $this->requireConfigWithEnv(['DB_WEB_MAX_EXECUTION_MS' => '0']);
        $this->assertSame(0, $disabled['web_statement_cap']['ms']);
        $this->assertNull(
            $disabled['web_statement_cap']['init_command'],
            'DB_WEB_MAX_EXECUTION_MS=0 must fully disable the cap, independent of the CLI gate'
        );
        // And, since this process is still the CLI either way, it stays absent from the real options.
        $this->assertArrayNotHasKey(
            PDO::MYSQL_ATTR_INIT_COMMAND,
            $disabled['connections']['mysql']['options']
        );
    }

    public function test_mysql_attr_ssl_ca_option_is_unaffected_by_the_new_keys(): void
    {
        // Regression guard: adding PDO::ATTR_TIMEOUT / PDO::MYSQL_ATTR_INIT_COMMAND to the same
        // array_filter() call must not disturb the pre-existing SSL CA option handling.
        $withCa = $this->requireConfigWithEnv(['MYSQL_ATTR_SSL_CA' => '/tmp/ca.pem']);
        $this->assertSame(
            '/tmp/ca.pem',
            $withCa['connections']['mysql']['options'][Mysql::ATTR_SSL_CA]
        );

        $withoutCa = $this->requireConfigWithEnv([]);
        $this->assertArrayNotHasKey(Mysql::ATTR_SSL_CA, $withoutCa['connections']['mysql']['options']);
    }
}
