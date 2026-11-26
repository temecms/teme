<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Exporter;

use Closure;
use RuntimeException;
use Teme\Contracts\Export\HasExportKinds;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin with one export, whose pages the tests set.
 */
final class Exporter implements HasExportKinds, Plugin
{
    public static ?Closure $lines = null;

    public static bool $throws = false;

    public static function reset(): void
    {
        self::$lines = null;
        self::$throws = false;
    }

    public function register(Hooks $hooks): void {}

    public function exportKinds(): array
    {
        if (self::$throws) {
            throw new RuntimeException('The bookings are out of reach.');
        }

        return ['bookings' => new BookingsKind];
    }
}
