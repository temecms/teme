<?php

/**
 * Dates as the public site shows them: in the site's time zone, and written
 * the way the item's language writes them, with Carbon's own localised
 * formats. There is no date-format setting (I-436).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Teme\Contracts\Settings\SettingsReader;

final class SiteDates
{
    /**
     * The moment in the site's time zone.
     */
    public static function inSite(DateTimeInterface|string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment)->setTimezone(app(SettingsReader::class)->string('general', 'timezone'));
    }

    /**
     * The moment in the site's time zone as PHP's own DateTimeImmutable,
     * which is how every date reaches a template (I-3346).
     */
    public static function forView(DateTimeInterface|string $moment): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(self::inSite($moment));
    }

    /**
     * The date as this language writes it: `LL` is the long date, such as
     * "24 septembre 2026"; `MMMM YYYY` a month.
     */
    public static function show(DateTimeInterface $date, string $language, string $format = 'LL'): string
    {
        return CarbonImmutable::instance($date)->locale(str_replace('-', '_', $language))->isoFormat($format);
    }
}
