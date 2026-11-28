<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Cuisines;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Taxonomy\TermWriter;

/**
 * A fixture plugin that writes terms of the taxonomy its types file brings.
 */
final class Cuisines implements Plugin
{
    public function __construct(public readonly TermWriter $terms) {}

    public function register(Hooks $hooks): void {}
}
