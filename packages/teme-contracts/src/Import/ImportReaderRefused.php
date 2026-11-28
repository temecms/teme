<?php

/**
 * Thrown by a reader that can't read the file it was given, with the
 * sentence the person sees in plain words.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Import;

use RuntimeException;

/**
 * A reader refuses a file it can't read, such as one that isn't its
 * system's export or one cut short. Core stops the import and shows the
 * sentence.
 *
 * @since 1.8.0
 */
final class ImportReaderRefused extends RuntimeException
{
    /**
     * A refusal with the sentence the person sees.
     *
     * @since 1.8.0
     */
    public static function because(string $sentence): self
    {
        return new self($sentence);
    }
}
