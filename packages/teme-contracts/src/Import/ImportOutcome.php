<?php

/**
 * What a plugin's importer made of one record: brought across, skipped with
 * why, or failed with a sentence the run's report shows.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Import;

use InvalidArgumentException;

/**
 * What a plugin's importer made of one record: brought across, skipped
 * with why, or failed with a sentence the run's report shows.
 *
 * @since 1.0.0
 */
final readonly class ImportOutcome
{
    /**
     * The record was brought across.
     *
     * @since 1.0.0
     */
    public const string IMPORTED = 'imported';

    /**
     * The record was left out on purpose.
     *
     * @since 1.0.0
     */
    public const string SKIPPED = 'skipped';

    /**
     * The record couldn't be brought across; the run goes on.
     *
     * @since 1.0.0
     */
    public const string FAILED = 'failed';

    /**
     * @param  string  $status  one of IMPORTED, SKIPPED or FAILED
     * @param  string  $words  why, for a skip or a failure; '' for an import
     */
    private function __construct(
        public string $status,
        public string $words,
    ) {}

    /**
     * The record was brought across.
     *
     * @since 1.0.0
     */
    public static function imported(): self
    {
        return new self(self::IMPORTED, '');
    }

    /**
     * The record was left out on purpose, saying why in a sentence.
     *
     * @throws InvalidArgumentException for no words, or more than 1,000 characters
     *
     * @since 1.0.0
     */
    public static function skipped(string $why): self
    {
        return new self(self::SKIPPED, self::sentence($why));
    }

    /**
     * The record couldn't be brought across, saying why in a sentence
     * people read in the run's report.
     *
     * @throws InvalidArgumentException for no words, or more than 1,000 characters
     *
     * @since 1.0.0
     */
    public static function failed(string $sentence): self
    {
        return new self(self::FAILED, self::sentence($sentence));
    }

    private static function sentence(string $words): string
    {
        if (trim($words) === '' || mb_strlen($words) > 1000) {
            throw new InvalidArgumentException('An import outcome’s words are 1 to 1,000 characters.');
        }

        return $words;
    }
}
