<?php

declare(strict_types=1);

namespace Tests\Fixtures\Deployment;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The default connection pointed, for one run, at a port nothing listens
 * on, as a database that has gone away; then put back, so the test's own
 * transaction ends as it began.
 */
final class UnreachableDatabase
{
    private const string NAME = 'teme_unreachable';

    /**
     * @template T
     *
     * @param  Closure(): T  $run
     *
     * @return T
     */
    public static function during(Closure $run): mixed
    {
        $previous = DB::getDefaultConnection();
        /** @var array<string, mixed> $base */
        $base = DB::connection()->getConfig();
        config(['database.connections.'.self::NAME => [...$base, 'port' => 1, 'name' => self::NAME], 'database.default' => self::NAME]);
        DB::setDefaultConnection(self::NAME);

        try {
            return $run();
        } finally {
            config(['database.default' => $previous]);
            DB::setDefaultConnection($previous);
            DB::purge(self::NAME);
        }
    }
}
