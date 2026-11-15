<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Writer;

use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A plugin that asks for the content-writing door.
 */
final class Writer implements Plugin
{
    public function __construct(public readonly ContentWriter $writer) {}

    public function register(Hooks $hooks): void {}
}
