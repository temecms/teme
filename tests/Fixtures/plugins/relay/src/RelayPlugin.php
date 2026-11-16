<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Relay;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Hooks\HookSender;
use Teme\Contracts\Notices\Notices;
use Teme\Contracts\Plugins\Plugin;

/**
 * A plugin that declares hooks of its own and sends them, and sends notices
 * of the kinds it declares: the tests reach both doors through it.
 */
final class RelayPlugin implements Plugin
{
    public function __construct(public readonly HookSender $hooks, public readonly Notices $notices) {}

    public function register(Hooks $hooks): void {}
}
