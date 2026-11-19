<?php

/**
 * A date and time, stored in UTC as an ISO 8601 string, or a date alone,
 * stored as Y-m-d and never moved by a zone (I-3321). An empty value is
 * stored as null.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Types;

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldType;
use App\Domain\Site\SiteDates;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Teme\Contracts\Settings\SettingsReader;

final class DatetimeField implements FieldType
{
    /**
     * @param  bool  $dateOnly  whether it holds a date without a time (I-3321)
     */
    public function __construct(public readonly bool $dateOnly = false) {}

    public function name(): string
    {
        return 'datetime';
    }

    public function rules(Field $field): array
    {
        return $this->dateOnly ? ['nullable', 'date_format:Y-m-d'] : ['nullable', 'date'];
    }

    public function default(Field $field): mixed
    {
        return null;
    }

    public function normalise(Field $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->dateOnly ? (string) $value : Carbon::parse($value)->utc()->toIso8601String();
    }

    /** ISO 8601 with the site's offset, or the date alone. */
    public function structuredData(Field $field, mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $this->dateOnly ? $value : SiteDates::inSite($value)->format(DATE_ATOM);
    }

    /** The control, told whether it asks for a date alone. */
    public function edit(Field $field): array
    {
        return ['component' => 'datetime', 'settings' => ['dateOnly' => $this->dateOnly]];
    }

    /**
     * The moment on the site's clock, or a date alone as midnight on it,
     * never moved by a zone, as PHP's own DateTimeImmutable, which a
     * template is given (I-3346); null when nothing is stored.
     */
    public function render(Field $field, mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if ($this->dateOnly) {
            return DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(app(SettingsReader::class)->string('general', 'timezone'))) ?: null;
        }

        return SiteDates::forView($value);
    }

    /** Text: values are stored in UTC with one offset, or as ISO dates, so text order is time order. */
    public function sqlType(Field $field): string
    {
        return 'text';
    }

    /** Nothing: this value points at nothing. */
    public function references(Field $field, mixed $value): array
    {
        return [];
    }

    /** Nothing: this value adds no words to search. */
    public function text(Field $field, mixed $value): string
    {
        return '';
    }
}
