<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Exporter;

use Teme\Contracts\Export\PluginExportKind;

/**
 * The fixture's one export: two bookings, a page of one each, unless a test sets its own pages.
 */
final class BookingsKind implements PluginExportKind
{
    public function version(): int
    {
        return 1;
    }

    public function fields(): array
    {
        return ['id' => 'The booking\'s number.', 'guest' => 'Who booked.'];
    }

    public function lines(?string $after): array
    {
        if (Exporter::$lines !== null) {
            return (Exporter::$lines)($after);
        }

        return $after === null
            ? ['lines' => [['id' => 1, 'guest' => 'Ada']], 'next' => '1']
            : ['lines' => [['id' => 2, 'guest' => 'Grace']], 'next' => null];
    }
}
