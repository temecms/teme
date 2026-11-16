<?php

declare(strict_types=1);

namespace Teme\Contracts\Lists;

use InvalidArgumentException;

/**
 * One cell a list column returns for a row: plain text, a number, or a
 * badge with a Status-pill tone. Core draws it; markup is never interpreted.
 *
 * @since 0.9.0
 */
final readonly class Cell
{
    /** @since 0.9.0 */
    public const string TEXT = 'text';

    /** @since 0.9.0 */
    public const string NUMBER = 'number';

    /** @since 0.9.0 */
    public const string BADGE = 'badge';

    /**
     * The tones a badge may take: the Status pill's.
     *
     * @since 0.9.0
     */
    public const array TONES = ['ok', 'neutral', 'info', 'warn', 'danger'];

    /**
     * @since 0.9.0
     */
    private function __construct(
        public string $kind,
        public string|int|float $value,
        public ?string $tone,
    ) {}

    /**
     * Plain text, never HTML.
     *
     * @since 0.9.0
     */
    public static function text(string $text): self
    {
        return new self(self::TEXT, $text, null);
    }

    /**
     * A finite number for a numeric column.
     *
     * @throws InvalidArgumentException when the number is not finite
     *
     * @since 0.9.0
     */
    public static function number(int|float $number): self
    {
        if (! is_finite((float) $number)) {
            throw new InvalidArgumentException('A list cell number must be finite.');
        }

        return new self(self::NUMBER, $number, null);
    }

    /**
     * A badge label with one of the Status pill's tones.
     *
     * @throws InvalidArgumentException for an empty label or a tone not in TONES
     *
     * @since 0.9.0
     */
    public static function badge(string $label, string $tone = 'neutral'): self
    {
        if ($label === '') {
            throw new InvalidArgumentException('A list cell badge needs a label.');
        }

        if (! in_array($tone, self::TONES, true)) {
            throw new InvalidArgumentException("A list cell badge doesn’t know the tone [{$tone}].");
        }

        return new self(self::BADGE, $label, $tone);
    }
}
