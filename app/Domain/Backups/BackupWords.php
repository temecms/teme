<?php

/**
 * Dates, durations, names and what's kept as plain words (I-364), made on
 * the server so Tools › Backups, the banner and the email say the same thing.
 * It holds no state.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Backups\Queries\DueBackupWork;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Teme\Contracts\Settings\SettingsReader;

final readonly class BackupWords
{
    public function __construct(private SettingsReader $settings, private DueBackupWork $due) {}

    /**
     * A moment in the site's time zone in words: "today at 02:14",
     * "yesterday at 02:14", "on 14 October at 02:14", with the year when it
     * isn't this year's. With $since the leading "on " is left off.
     */
    public function when(CarbonInterface $at, CarbonImmutable $now, bool $since = false): string
    {
        $zone = $this->settings->string('general', 'timezone');
        $moment = CarbonImmutable::instance($at)->setTimezone($zone);
        $today = $now->setTimezone($zone);
        $time = $moment->format('H:i');

        if ($moment->isSameDay($today)) {
            return "today at {$time}";
        }

        if ($moment->isSameDay($today->subDay())) {
            return "yesterday at {$time}";
        }

        $date = $moment->year === $today->year ? $moment->format('j F') : $moment->format('j F Y');

        return ($since ? '' : 'on ')."{$date} at {$time}";
    }

    /**
     * When the next backup starts: "within a minute" while one is due, else
     * the hour's next coming, "today at 02:00" or "tomorrow at 02:00".
     */
    public function next(CarbonImmutable $now): string
    {
        if ($this->due->backupDue($now)) {
            return 'within a minute';
        }

        $zone = $this->settings->string('general', 'timezone');
        $next = $this->due->slot($now)->addDay()->setTimezone($zone);
        $day = $next->isSameDay($now->setTimezone($zone)) ? 'today' : 'tomorrow';

        return "{$day} at {$next->format('H:i')}";
    }

    /**
     * A length of time in words: "under a minute", "14 minutes", "1 hour 1 minute".
     */
    public static function duration(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);

        if ($minutes === 0) {
            return 'under a minute';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return self::count($minutes, 'minute');
        }

        return $rest === 0 ? self::count($hours, 'hour') : self::count($hours, 'hour').' '.self::count($rest, 'minute');
    }

    /**
     * What's kept in words, from the three settings.
     */
    public static function kept(int $daily, int $weekly, int $monthly): string
    {
        $words = $daily === 1 ? 'Keeps a backup from the last day' : "Keeps a backup from each of the last {$daily} days";

        if ($weekly > 0) {
            $words .= $weekly === 1 ? ', then one from the week before them' : ", then one from each of {$weekly} weeks before them";
        }

        if ($monthly > 0) {
            $before = $weekly > 0 ? 'those' : 'them';
            $words .= $monthly === 1 ? ", and one from the month before {$before}" : ", and one from each of {$monthly} months before {$before}";
        }

        return $words.'.';
    }

    /**
     * Names in a sentence: "A", "A and B", "A, B and C".
     *
     * @param  list<string>  $names
     */
    public static function names(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }

    private static function count(int $number, string $noun): string
    {
        return $number === 1 ? "1 {$noun}" : "{$number} {$noun}s";
    }
}
