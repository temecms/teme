<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\Seams\HookSubscriptions;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\RecordingSubscriptions;
use Tests\Fixtures\Plugins\ReportedFailures;

/*
 * A database that can't be reached at boot never locks anyone out: the
 * loader reports it once and starts no plugin (I-3353). No RefreshDatabase
 * here, since the test takes the connection away.
 */

beforeEach(function (): void {
    $this->subscriptions = new RecordingSubscriptions;
    app()->instance(HookSubscriptions::class, $this->subscriptions);
    $this->failures = new ReportedFailures(app());
    app()->instance(ExceptionHandler::class, $this->failures);
    $this->port = config('database.connections.pgsql.port');
});

afterEach(function (): void {
    config(['database.connections.pgsql.port' => $this->port]);
    DB::purge('pgsql');
    PluginFixtures::forget();
});

it('serves an ordinary request with the database down, starting no plugin and reporting it once', function (): void {
    PluginFixtures::use('hello');
    config(['database.connections.pgsql.port' => 1]);
    DB::purge('pgsql');

    PluginFixtures::serving('admin', fn () => app(PluginLoader::class)->load());

    expect(app(LoadedPlugins::class)->running())->toBe([])
        ->and($this->subscriptions->calls)->toBe([])
        ->and($this->failures->reported)->toHaveCount(1)
        ->and($this->failures->reported[0][0])->toBeInstanceOf(QueryException::class);
});
