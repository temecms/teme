<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Greedy;

use Teme\Contracts\Settings\SettingsReader;

/**
 * A constructor in a trait.
 */
trait BuildsItself
{
    public function __construct(public readonly SettingsReader $settings) {}
}
