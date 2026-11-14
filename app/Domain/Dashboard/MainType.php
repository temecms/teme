<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use RuntimeException;

/**
 * The content type the Home screen writes and links to: the first listed
 * type with an archive, or else the first listed type — posts today. The
 * dashboard never names it; it asks here, and this asks the registry each
 * time.
 */
final class MainType
{
    public static function get(): ContentType
    {
        $listed = array_values(array_filter(app(ContentTypeRegistry::class)->all(), fn (ContentType $type): bool => $type->listed));

        foreach ($listed as $type) {
            if ($type->archive) {
                return $type;
            }
        }

        return $listed[0] ?? throw new RuntimeException('No listed content type is registered.');
    }
}
