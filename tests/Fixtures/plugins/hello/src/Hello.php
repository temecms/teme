<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Hello;

use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Settings\SettingsReader;

/**
 * A plugin that asks for the settings and listens for a published post.
 */
final class Hello implements Plugin
{
    public function __construct(public readonly SettingsReader $settings) {}

    public function register(Hooks $hooks): void
    {
        $hooks->on(ContentPublished::class, function (ContentPublished $published): void {});
    }
}
