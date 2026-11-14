<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Loud;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

// Run the moment this file is loaded: the marker says it was.
file_put_contents(sys_get_temp_dir().'/teme-loud-plugin-'.getmypid(), 'loaded');

/**
 * A plugin whose file writes a marker when it is loaded.
 */
final class Loud implements Plugin
{
    public static function marker(): string
    {
        return sys_get_temp_dir().'/teme-loud-plugin-'.getmypid();
    }

    public function register(Hooks $hooks): void {}
}
