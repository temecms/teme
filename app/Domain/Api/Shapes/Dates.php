<?php

declare(strict_types=1);

namespace App\Domain\Api\Shapes;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * How the API writes a moment: RFC 3339 in UTC with Z, to the second, so
 * a client never needs the site's zone to read one (I-3324).
 */
final class Dates
{
    public const string FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * The moment in UTC, as the API writes it.
     */
    public static function utc(string|DateTimeInterface $at): string
    {
        return CarbonImmutable::parse($at)->utc()->format(self::FORMAT);
    }
}
