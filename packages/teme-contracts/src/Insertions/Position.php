<?php

declare(strict_types=1);

namespace Teme\Contracts\Insertions;

use InvalidArgumentException;

/**
 * Where an insertion sits among an article's top-level blocks: before or
 * after the content, after a paragraph, every N paragraphs, beside a
 * heading, or near the end. Core maps each to an index; a position the
 * article does not reach draws nothing.
 *
 * @since 0.8.0
 */
final class Position
{
    /** @since 0.8.0 */
    public const string BEFORE_CONTENT = 'before_content';

    /** @since 0.8.0 */
    public const string AFTER_CONTENT = 'after_content';

    /** @since 0.8.0 */
    public const string AFTER_PARAGRAPH = 'after_paragraph';

    /** @since 0.8.0 */
    public const string EVERY_PARAGRAPHS = 'every_paragraphs';

    /** @since 0.8.0 */
    public const string BEFORE_HEADING = 'before_heading';

    /** @since 0.8.0 */
    public const string AFTER_HEADING = 'after_heading';

    /** @since 0.8.0 */
    public const string NEAR_END = 'near_end';

    /**
     * The most times one everyParagraphs insertion is drawn in an article; a
     * larger ceiling is held to this.
     *
     * @since 0.8.0
     */
    public const int EVERY_PARAGRAPHS_MAX = 10;

    /**
     * @param  array{n?: int, max?: int, level?: int, nth?: int}  $args
     */
    private function __construct(
        public readonly string $kind,
        public readonly array $args = [],
    ) {}

    /** @since 0.8.0 */
    public static function beforeContent(): self
    {
        return new self(self::BEFORE_CONTENT);
    }

    /** @since 0.8.0 */
    public static function afterContent(): self
    {
        return new self(self::AFTER_CONTENT);
    }

    /** @since 0.8.0 */
    public static function afterParagraph(int $n): self
    {
        if ($n < 1) {
            throw new InvalidArgumentException('afterParagraph needs a positive paragraph number.');
        }

        return new self(self::AFTER_PARAGRAPH, ['n' => $n]);
    }

    /**
     * Every $n paragraphs, at most $max times, and never more than
     * EVERY_PARAGRAPHS_MAX.
     *
     * @since 0.8.0
     */
    public static function everyParagraphs(int $n, int $max = self::EVERY_PARAGRAPHS_MAX): self
    {
        if ($n < 1) {
            throw new InvalidArgumentException('everyParagraphs needs a positive step.');
        }

        if ($max < 1) {
            throw new InvalidArgumentException('everyParagraphs needs a positive ceiling.');
        }

        return new self(self::EVERY_PARAGRAPHS, ['n' => $n, 'max' => min($max, self::EVERY_PARAGRAPHS_MAX)]);
    }

    /** @since 0.8.0 */
    public static function beforeHeading(int $level, int $nth = 1): self
    {
        self::headingArgs($level, $nth);

        return new self(self::BEFORE_HEADING, ['level' => $level, 'nth' => $nth]);
    }

    /** @since 0.8.0 */
    public static function afterHeading(int $level, int $nth = 1): self
    {
        self::headingArgs($level, $nth);

        return new self(self::AFTER_HEADING, ['level' => $level, 'nth' => $nth]);
    }

    /** @since 0.8.0 */
    public static function nearEnd(): self
    {
        return new self(self::NEAR_END);
    }

    private static function headingArgs(int $level, int $nth): void
    {
        if ($level < 1 || $level > 6) {
            throw new InvalidArgumentException('A heading level is 1 to 6.');
        }

        if ($nth < 1) {
            throw new InvalidArgumentException('A heading index needs a positive nth.');
        }
    }
}
