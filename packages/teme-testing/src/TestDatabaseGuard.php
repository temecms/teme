<?php

/**
 * Refuses to let a test run refresh a database that isn't a test database.
 * With Laravel's configuration cached, a test run reads the site's own
 * connection from the cache instead of phpunit.xml, and RefreshDatabase then
 * drops every table of the site (issue 754). Both Teme's own test case and
 * the kit's call it before the first refresh, and it stops the whole run.
 *
 * @package Teme
 * @since   1.6.1
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Testing;

use Illuminate\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Testing\ParallelTesting;

final class TestDatabaseGuard
{
    /** The ending every test database's name has, as in phpunit.xml, CI and docker-compose.yml. */
    public const string SUFFIX = '_test';

    /**
     * Stops the run with the reason, printed once, when this application would
     * refresh a database that isn't a test database. Exiting rather than
     * throwing stops the whole run: a throw would fail each test in turn,
     * and the first would already have reached the refresh had it not.
     */
    public static function enforce(Application $app): void
    {
        $connection = (string) $app['config']->get('database.default');
        $token = $app->bound(ParallelTesting::class) ? $app->make(ParallelTesting::class)->token() : false;
        $refusal = self::refusal(
            $app->configurationIsCached() ? $app->getCachedConfigPath() : null,
            self::database((array) $app['config']->get("database.connections.{$connection}", [])),
            $token,
        );

        if ($refusal === null) {
            return;
        }

        if (self::speaks($token)) {
            fwrite(STDERR, PHP_EOL.$refusal.PHP_EOL.PHP_EOL);
        }

        exit(1);
    }

    /**
     * Whether this process prints the refusal: a run on its own does, and
     * under parallel testing only the first worker does, so the message
     * shows once while every worker still stops.
     */
    public static function speaks(string|int|false|null $parallelToken): bool
    {
        return $parallelToken === false || $parallelToken === null || (string) $parallelToken === '1';
    }

    /**
     * Returns why the run must stop, or null when the database is a test
     * database: a configuration cache at that path, or a database whose
     * name, less the suffix parallel testing adds for each process, doesn't
     * end in `_test`.
     */
    public static function refusal(?string $cachedConfig, string $database, string|int|false|null $parallelToken = false): ?string
    {
        if ($cachedConfig !== null && is_file($cachedConfig)) {
            return sprintf(
                'Teme stopped the tests before they touched a database: Laravel\'s configuration is cached in %s, so the tests would use the connection saved there, which is usually the site\'s own database, and refreshing it drops every table. Run `php artisan config:clear` and run the tests again.',
                $cachedConfig,
            );
        }

        if (! str_ends_with(self::named($database, $parallelToken), self::SUFFIX)) {
            return sprintf(
                'Teme stopped the tests before they touched a database: they would have refreshed the database "%s", which drops every table. Tests run on a test database, whose name ends in "%s", such as "teme_test" in phpunit.xml. Point DB_DATABASE at a test database, and if Laravel\'s configuration is cached, run `php artisan config:clear`.',
                $database,
                self::SUFFIX,
            );
        }

        return null;
    }

    /**
     * The name of the database the connection would really use: a `url`
     * (DB_URL or DATABASE_URL, which a host's environment may set and
     * phpunit.xml doesn't override) wins over `database`, as Laravel merges
     * them when it connects. Read without connecting.
     *
     * @param  array<string, mixed>  $connection
     */
    public static function database(array $connection): string
    {
        return (string) ((new ConfigurationUrlParser)->parseConfiguration($connection)['database'] ?? '');
    }

    /**
     * The database's name as phpunit.xml gave it: parallel testing gives
     * each process its own copy, named with `_test_` and the process's token.
     */
    private static function named(string $database, string|int|false|null $parallelToken): string
    {
        $copy = '_test_'.$parallelToken;

        return $parallelToken !== false && $parallelToken !== null && str_ends_with($database, $copy)
            ? substr($database, 0, -strlen($copy))
            : $database;
    }
}
