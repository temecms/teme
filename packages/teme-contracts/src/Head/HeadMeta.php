<?php

declare(strict_types=1);

namespace Teme\Contracts\Head;

use InvalidArgumentException;

/**
 * One `<meta>` in a page's head: a name or a property, and its content as
 * plain text. Never markup and never a script: core escapes both when it
 * draws the tag.
 *
 * @since 1.1.0
 */
final readonly class HeadMeta
{
    /** What a name or property may be, as `robots`, `og:image:width` and `msvalidate.01` are. @since 1.1.0 */
    public const string KEY = '/^[A-Za-z][A-Za-z0-9]*(?:[:._-][A-Za-z0-9]+)*$/D';

    /** The longest content, in characters. @since 1.1.0 */
    public const int MOST_CONTENT = 1_000;

    private function __construct(
        public string $attribute,
        public string $key,
        public string $content,
    ) {
        if (preg_match(self::KEY, $key) !== 1 || strlen($key) > 100) {
            throw new InvalidArgumentException('A meta tag’s name or property is letters and digits joined by colons, dots, hyphens or underscores, at most 100 of them.');
        }

        if (mb_strlen($content) > self::MOST_CONTENT || ! mb_check_encoding($content, 'UTF-8')) {
            throw new InvalidArgumentException('A meta tag’s content is text of at most 1,000 characters.');
        }
    }

    /**
     * `<meta name="…" content="…">`.
     *
     * @throws InvalidArgumentException for a name or content outside the bounds above
     *
     * @since 1.1.0
     */
    public static function name(string $name, string $content): self
    {
        return new self('name', $name, $content);
    }

    /**
     * `<meta property="…" content="…">`, as Open Graph's tags are written.
     *
     * @throws InvalidArgumentException for a property or content outside the bounds above
     *
     * @since 1.1.0
     */
    public static function property(string $property, string $content): self
    {
        return new self('property', $property, $content);
    }
}
