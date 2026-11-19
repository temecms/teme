<?php

/**
 * The test case a plugin's or theme's tests run on: the Teme checkout this
 * kit sits in, booted afresh for each test with a fresh database, nothing
 * sent out of the machine unless a test fakes it, and every method of
 * InteractsWithTeme. A test file starts with
 * `uses(Teme\Testing\TemeTestCase::class);`.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Testing;

use App\Http\Middleware\HoldUntilSetUp;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;

abstract class TemeTestCase extends TestCase
{
    use InteractsWithTeme;
    use RefreshDatabase;

    /**
     * Boots the Teme checkout the kit sits in, whichever folder the tests run from.
     */
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // As Teme's own tests: no built front end, the new site's hold off, and nothing sent out unless faked.
        $this->withoutVite();
        $this->withoutMiddleware(HoldUntilSetUp::class);
        Http::preventStrayRequests();
    }
}
