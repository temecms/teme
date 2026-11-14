<?php

declare(strict_types=1);

namespace App\Domain\StructuredData;

use Teme\Contracts\Settings\Setting;

/**
 * A week of opening hours as schema.org describes it (I-877): one
 * specification for each distinct opening, naming every day it applies to.
 */
final class OpeningHours
{
    /**
     * The stored week as `openingHoursSpecification`, in the order met: the
     * week's days in order, a day's first opening before its second. A day
     * closed all day is 00:00 to 00:00, as Google reads it; an opening past
     * midnight is written as it is; a day the week says nothing about, and
     * anything that isn't a day, gives nothing.
     *
     * @return list<array{'@type': string, dayOfWeek: list<string>, opens: string, closes: string}>
     */
    public static function specification(mixed $week): array
    {
        $byTimes = [];

        foreach (is_array($week) ? $week : [] as $day) {
            if (! is_array($day) || ! in_array($day['day'] ?? null, Setting::DAYS, true)) {
                continue;
            }

            $openings = ($day['closed'] ?? false) === true
                ? [['opens' => '00:00', 'closes' => '00:00']]
                : (is_array($day['hours'] ?? null) ? $day['hours'] : []);

            foreach ($openings as $opening) {
                if (! is_array($opening) || ! is_string($opening['opens'] ?? null) || ! is_string($opening['closes'] ?? null)) {
                    continue;
                }

                $key = $opening['opens'].'-'.$opening['closes'];
                $byTimes[$key] ??= ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => [], 'opens' => $opening['opens'], 'closes' => $opening['closes']];
                $byTimes[$key]['dayOfWeek'][] = ucfirst((string) $day['day']);
            }
        }

        return array_values($byTimes);
    }
}
