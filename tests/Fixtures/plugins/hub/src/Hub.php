<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Hub;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Plugins\Plugin;

/**
 * A plugin built only against teme/contracts that pings the hubs its
 * settings name: its outbound door is kept where a test can reach it.
 */
final class Hub implements Plugin
{
    public function __construct(public readonly OutboundHttp $http) {}

    public function register(Hooks $hooks): void
    {
        //
    }
}
