<?php

/**
 * Points the application, and its cache, at the benchmark's own database,
 * and refuses to proceed unless that database's name ends in _bench, so the
 * benchmark's migrate:fresh and bulk writes can never reach a real site.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Benchmark;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

final class BenchmarkDatabase
{
    /**
     * Switches the default connection to `bench` and returns the database's
     * name, or throws if it is not a _bench database.
     */
    public static function select(): string
    {
        DB::setDefaultConnection('bench');
        DB::purge('bench');

        $name = DB::connection()->getDatabaseName();

        if (! str_ends_with($name, '_bench')) {
            throw new RuntimeException("Refusing to run: the benchmark only touches a database whose name ends in _bench, and this one is [{$name}].");
        }

        // Its migrate:fresh empties only this _bench database, so the guard on the site's own lets it through.
        DB::prohibitDestructiveCommands(false);

        // The cache was built at boot on the site's own connection, so the
        // settings and permissions read, cached or forgotten here would come
        // from, and land in, the site's cache. It follows the benchmark's.
        config(['cache.stores.database.connection' => 'bench', 'cache.stores.database.lock_connection' => 'bench']);
        app('cache')->forgetDriver('database');
        app()->forgetInstance('cache.store');
        app()->forgetInstance('cache.__memoized:database');
        app(PermissionRegistrar::class)->initializeCache();

        return $name;
    }
}
