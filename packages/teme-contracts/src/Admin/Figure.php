<?php

declare(strict_types=1);

namespace Teme\Contracts\Admin;

use InvalidArgumentException;

/**
 * One figure of a figures block: a number, a ratio shown as a percentage, or
 * a short text, with an optional change shown as an up or down arrow.
 *
 * @since 1.0.0
 */
final readonly class Figure
{
    /** @since 1.0.0 */
    public const string NUMBER = 'number';

    /** @since 1.0.0 */
    public const string PERCENT = 'percent';

    /** @since 1.0.0 */
    public const string TEXT = 'text';

    /**
     * @param  ?float  $change  a ratio: 0.12 is up 12%, -0.5 down 50%
     */
    private function __construct(
        public string $format,
        public int|float|string $value,
        public ?float $change,
    ) {}

    /**
     * A finite number.
     *
     * @throws InvalidArgumentException when the number or the change is not finite
     *
     * @since 1.0.0
     */
    public static function number(int|float $value, ?float $change = null): self
    {
        self::finite($value, $change);

        return new self(self::NUMBER, $value, $change);
    }

    /**
     * A ratio, 0.25 shown as 25%.
     *
     * @throws InvalidArgumentException when the ratio or the change is not finite
     *
     * @since 1.0.0
     */
    public static function percent(float $ratio, ?float $change = null): self
    {
        self::finite($ratio, $change);

        return new self(self::PERCENT, $ratio, $change);
    }

    /**
     * Plain text, never HTML; core cuts it to 40 characters.
     *
     * @since 1.0.0
     */
    public static function text(string $text): self
    {
        return new self(self::TEXT, $text, null);
    }

    private static function finite(int|float $value, ?float $change): void
    {
        if (! is_finite((float) $value) || ($change !== null && ! is_finite($change))) {
            throw new InvalidArgumentException('A figure must be finite.');
        }
    }
}
