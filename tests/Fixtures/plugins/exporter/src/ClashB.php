<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Exporter;

use Teme\Contracts\Export\HasExportKinds;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * One of two fixture plugins whose export file names meet: the code clash-bookings and the id list.
 */
final class ClashB implements HasExportKinds, Plugin
{
    public function register(Hooks $hooks): void {}

    public function exportKinds(): array
    {
        return ['list' => new BookingsKind];
    }
}
