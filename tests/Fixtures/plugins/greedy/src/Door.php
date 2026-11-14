<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Greedy;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Plugins\Plugin;

/**
 * A plugin that asks for the outbound door, for a manifest that may not ask for http.
 */
final class Door implements Plugin
{
    public function __construct(public readonly OutboundHttp $http) {}

    public function register(Hooks $hooks): void {}
}
