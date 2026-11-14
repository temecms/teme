<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;

/**
 * Boots Teme again, as the next request or worker would: every provider
 * registered and booted afresh, so every registry is filled anew and the
 * plugins start from the plugins table, as at boot. Only the database
 * connection is carried over, with the test's open transaction, and the
 * configuration and instances the test names.
 */
final class NextProcess
{
    /**
     * The booted application. The caller makes it the test's own.
     *
     * @param  array<string, mixed>  $config  keys set as the configuration is loaded, before any provider runs
     * @param  array<string, object>  $instances  bound before the providers boot
     */
    public static function boot(Application $current, array $config, array $instances = []): Application
    {
        $carried = ['db' => $current->make('db'), ...($current->bound('db.transactions') ? ['db.transactions' => $current->make('db.transactions')] : [])];

        /** @var Application $next */
        $next = require $current->basePath('bootstrap/app.php');

        $next->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($config): void {
            $app->make('config')->set($config);
        });

        $next->booting(function (Application $app) use ($carried, $instances): void {
            foreach ([...$carried, ...$instances] as $abstract => $instance) {
                $app->instance($abstract, $instance);
            }
        });

        $next->make(Kernel::class)->bootstrap();

        return $next;
    }
}
