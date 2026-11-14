<?php

declare(strict_types=1);

namespace App\Domain\Support;

use Carbon\CarbonImmutable;

/**
 * A past moment in words, as the dashboard shows it: "just now",
 * "15 minutes ago", "2 hours ago", "3 days ago", or the date when it was
 * longer ago than a month.
 */
final class RelativeTime
{
    public static function from(CarbonImmutable $at, CarbonImmutable $now): string
    {
        $seconds = max(0, $now->getTimestamp() - $at->getTimestamp());

        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => self::plural(intdiv($seconds, 60), 'minute').' ago',
            $seconds < 86400 => self::plural(intdiv($seconds, 3600), 'hour').' ago',
            $seconds < 86400 * 30 => self::plural(intdiv($seconds, 86400), 'day').' ago',
            default => 'on '.$at->format('j M Y'),
        };
    }

    private static function plural(int $count, string $unit): string
    {
        return $count.' '.$unit.($count === 1 ? '' : 's');
    }
}
