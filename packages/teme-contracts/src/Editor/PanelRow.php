<?php

/**
 * One labelled line of a panel's details: a label, its value and an optional
 * tone.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Editor;

use DateTimeInterface;
use InvalidArgumentException;
use Teme\Contracts\Lists\Cell;

/**
 * One labelled line of a panel's details: a label, its value and an optional
 * tone. A value is plain text, or a date and time that core formats for the
 * person.
 *
 * @since next
 */
final readonly class PanelRow
{
    /** The longest label, in characters. @since next */
    public const int MOST_LABEL = 60;

    /** The longest text value, in characters. @since next */
    public const int MOST_VALUE = 120;

    /**
     * @param  string  $label  up to 60 characters of plain text
     * @param  string|DateTimeInterface  $value  up to 120 characters of plain text, or a date and time
     * @param  string|null  $tone  ok, neutral, info, warn or danger: the value is drawn as a pill; null for plain text
     *
     * @throws InvalidArgumentException for a label, value or tone outside those bounds
     *
     * @since next
     */
    public function __construct(
        public string $label,
        public string|DateTimeInterface $value,
        public ?string $tone = null,
    ) {
        if ($label === '' || mb_strlen($label) > self::MOST_LABEL) {
            throw new InvalidArgumentException('A row’s label must be 1 to 60 characters.');
        }

        if (is_string($value) && mb_strlen($value) > self::MOST_VALUE) {
            throw new InvalidArgumentException('A row’s value may be at most 120 characters.');
        }

        if ($tone !== null && ! in_array($tone, Cell::TONES, true)) {
            throw new InvalidArgumentException('A row’s tone must be ok, neutral, info, warn or danger.');
        }
    }
}
