<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Http\Middleware\HoldUntilSetUp;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;
use LogicException;
use Teme\Testing\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /** The traits that give a test a migrated database of its own. */
    private const array DATABASE_TRAITS = [
        RefreshDatabase::class,
        LazilyRefreshDatabase::class,
        DatabaseTransactions::class,
        DatabaseMigrations::class,
        DatabaseTruncation::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // PHP tests never depend on a built front end.
        $this->withoutVite();

        // A new site holds its public pages until the first account exists
        // (issue 246); most tests read the public site with none, so the hold
        // is off unless a test is about it and calls withMiddleware().
        $this->withoutMiddleware(HoldUntilSetUp::class);

        // The breached-password check calls Have I Been Pwned; tests that
        // need it fake it explicitly.
        Http::preventStrayRequests();

        $this->refuseTheDatabaseWithoutATrait();
    }

    /**
     * Stops the run before the first database refresh when the database
     * isn't a test database (issue 754).
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        TestDatabaseGuard::enforce($this->app);

        return parent::setUpTraits();
    }

    /**
     * Stops a test that queries the default connection with no database
     * trait (issue #14). Parallel testing moves only a test with one onto
     * its worker's migrated database; any other runs against the base test
     * database, which nothing migrates, so it passed or failed on whatever
     * an earlier serial run left there. The hook sits on the default
     * connection alone, so a test that deliberately uses another, such as
     * the benchmark's `bench`, keeps working.
     */
    private function refuseTheDatabaseWithoutATrait(): void
    {
        if (array_intersect(self::DATABASE_TRAITS, class_uses_recursive(static::class)) !== []) {
            return;
        }

        DB::connection()->beforeExecuting(function (string $query): never {
            throw new LogicException(sprintf(
                'This test touches the database: add RefreshDatabase (in a Pest file, uses(RefreshDatabase::class)). %s uses no database trait, so it would run against the base test database, which nothing migrates. The query: %s',
                static::class,
                $query,
            ));
        });
    }

    /**
     * Run Operations as the trusted operator, for tests that call a sensitive
     * Operation directly and are not about elevation.
     */
    protected function withElevation(): static
    {
        $this->app->instance(Elevation::class, new OperatorElevation);

        return $this;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
