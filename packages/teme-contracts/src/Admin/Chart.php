<?php

/**
 * A chart block's numbers: its labels, `Y-m-d` dates or category words, and
 * each declared series' values, one per label.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Admin;

use InvalidArgumentException;

/**
 * A chart block's numbers: its labels, `Y-m-d` dates or category words, and
 * each declared series' values, one per label. Core drops a series it
 * doesn't declare.
 *
 * @since 1.0.0
 */
final readonly class Chart
{
    /**
     * The most labels a chart may have: a year of days.
     *
     * @since 1.0.0
     */
    public const int MOST_LABELS = 366;

    /**
     * @param  list<string>  $labels
     * @param  array<string, list<int|float>>  $series  by declared series id
     *
     * @throws InvalidArgumentException for more than 366 labels, a series not as long as the labels, or a number that isn't finite
     *
     * @since 1.0.0
     */
    public function __construct(
        public array $labels,
        public array $series,
    ) {
        if (count($labels) > self::MOST_LABELS) {
            throw new InvalidArgumentException('A chart has at most 366 labels.');
        }

        foreach ($series as $id => $values) {
            if (count($values) !== count($labels)) {
                throw new InvalidArgumentException("The chart's series [{$id}] must have one value a label.");
            }

            foreach ($values as $value) {
                if (! is_finite((float) $value)) {
                    throw new InvalidArgumentException("The chart's series [{$id}] must be finite.");
                }
            }
        }
    }
}
