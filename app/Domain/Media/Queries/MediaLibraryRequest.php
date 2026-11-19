<?php

/**
 * Which page of the media library to show: the tab, the direction, the
 * cursor, and the filters, among them a kind and a month on the site's
 * clock.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use Carbon\CarbonImmutable;

final readonly class MediaLibraryRequest
{
    /** The kinds the Type filter may name. */
    public const array TYPES = ['image', 'video', 'audio', 'document'];

    /** A month as the Date filter names it: 2026-10. */
    public const string MONTH = '/^(\d{4})-(0[1-9]|1[0-2])$/';

    /** A content item's ID, as the Uploaded for filter names it: 26 lower-case letters and digits (I-418). */
    public const string ITEM = '/^[0-9a-hjkmnp-tv-z]{26}$/';

    /**
     * @param  string  $zone  the site's time zone, whose months the Date filter means
     * @param  string|null  $uploadedFor  the content item whose files to show (I-553)
     */
    public function __construct(
        public string $tab = 'all',
        public string $direction = 'desc',
        public ?string $after = null,
        public ?string $before = null,
        public ?string $search = null,
        public bool $mine = false,
        public bool $unused = false,
        public bool $missingAlt = false,
        public ?string $type = null,
        public ?string $month = null,
        public string $zone = 'UTC',
        public int $viewerId = 0,
        public ?string $uploadedFor = null,
    ) {}

    /**
     * Returns the kind the Type filter names, or null when it names none
     * Teme has.
     */
    public function kind(): ?string
    {
        return in_array($this->type, self::TYPES, true) ? $this->type : null;
    }

    /**
     * Returns the content item the Uploaded for filter names, or null when
     * it names none: an item's ID is 26 lower-case letters and digits.
     */
    public function item(): ?string
    {
        return $this->uploadedFor !== null && preg_match(self::ITEM, $this->uploadedFor) === 1 ? $this->uploadedFor : null;
    }

    /**
     * Returns the first instant of the chosen month on the site's clock and
     * of the month after it, both as UTC instants written with their offset,
     * so PostgreSQL reads them the same in any session; or null when no real
     * month is chosen. The range check matters: Carbon would read month 13
     * as next January.
     *
     * @return array{0: string, 1: string}|null
     */
    public function monthBounds(): ?array
    {
        if ($this->month === null || preg_match(self::MONTH, $this->month, $matches) !== 1) {
            return null;
        }

        // As the content lists bound theirs: app/Http/Admin/Contents/ContentListController.php
        $start = CarbonImmutable::create((int) $matches[1], (int) $matches[2], 1, 0, 0, 0, $this->zone);

        return [$start->utc()->toIso8601String(), $start->addMonthNoOverflow()->utc()->toIso8601String()];
    }
}
