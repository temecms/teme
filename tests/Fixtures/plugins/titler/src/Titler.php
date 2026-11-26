<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Titler;

use Closure;
use Teme\Contracts\Head\HasPageTitle;
use Teme\Contracts\Head\HeadPage;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin that only answers page titles; the tests set the answer.
 */
final class Titler implements HasPageTitle, Plugin
{
    /** @var (Closure(HeadPage, string): mixed)|null */
    public static ?Closure $title = null;

    public static function reset(): void
    {
        self::$title = null;
    }

    public function register(Hooks $hooks): void {}

    public function pageTitle(HeadPage $page, string $coreTitle): ?string
    {
        /** @var string|null */
        return self::$title === null ? null : (self::$title)($page, $coreTitle);
    }
}
