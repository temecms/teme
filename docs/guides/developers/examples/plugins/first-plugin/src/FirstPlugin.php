<?php

declare(strict_types=1);

namespace TemeExamples\FirstPlugin;

use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The handbook's first plugin: listens for a published item and records one
 * line through the settings reader it is granted.
 */
final class FirstPlugin implements Plugin
{
    /** @var list<string> lines recorded when ContentPublished fires */
    public static array $heard = [];

    public function __construct(public readonly SettingsReader $settings) {}

    public function register(Hooks $hooks): void
    {
        $hooks->on(ContentPublished::class, function (ContentPublished $published): void {
            $site = $this->settings->string('general', 'site_title');
            self::$heard[] = $site.' published '.$published->item->id;
        });
    }
}
