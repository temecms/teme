<?php

/**
 * One cell a list column returns for a row: plain text, a number, a badge
 * with a Status-pill tone, or (in a plugin area table only) a link to an
 * outside address.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Lists;

use InvalidArgumentException;

/**
 * One cell a list column returns for a row: plain text, a number, a badge
 * with a Status-pill tone, or (in a plugin area table only) a link to an
 * outside address. Core draws it; markup is never interpreted.
 *
 * @since 1.0.0
 */
final readonly class Cell
{
    /** @since 1.0.0 */
    public const string TEXT = 'text';

    /** @since 1.0.0 */
    public const string NUMBER = 'number';

    /** @since 1.0.0 */
    public const string BADGE = 'badge';

    /**
     * An outside address drawn only in a plugin area's table: https, new tab.
     *
     * @since next
     */
    public const string LINK = 'link';

    /**
     * The tones a badge may take: the Status pill's.
     *
     * @since 1.0.0
     */
    public const array TONES = ['ok', 'neutral', 'info', 'warn', 'danger'];

    /**
     * @param  string|null  $href  the link's address when kind is link; null otherwise
     *
     * @since 1.0.0
     */
    private function __construct(
        public string $kind,
        public string|int|float $value,
        public ?string $tone,
        public ?string $href = null,
    ) {}

    /**
     * Plain text, never HTML.
     *
     * @since 1.0.0
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
     * @since 1.0.0
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
     * @since 1.0.0
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

    /**
     * Link text and an outside address for an area table column. Core draws
     * only a safe https address with no user or password; anything else
     * shows as no link.
     *
     * @throws InvalidArgumentException for empty text
     *
     * @since next
     */
    public static function link(string $text, string $href): self
    {
        if ($text === '') {
            throw new InvalidArgumentException('A list cell link needs text.');
        }

        return new self(self::LINK, $text, null, $href);
    }
}
