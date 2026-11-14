<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Autoloads;

use Illuminate\Support\ServiceProvider;

/**
 * The provider the plugin asks Laravel to discover: it leaves a marker the
 * moment Laravel registers it, which Teme must never let happen.
 */
final class AutoloadsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        file_put_contents(sys_get_temp_dir().'/teme-discovered-plugin-'.getmypid(), 'registered');
    }
}
