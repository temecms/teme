<?php

/**
 * What a read of Teme's plugin and theme directory answers: the data Teme
 * verified, or the reason there is none.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Directory;

use InvalidArgumentException;

/**
 * What a read of Teme's plugin and theme directory answers: the data Teme
 * verified, decoded from its JSON, or, when there is none, the reason in a
 * sentence people read, such as the directory being switched off on this
 * site, its list having run out, or a file not matching its signed list.
 * Exactly one of the two is null.
 *
 * @since next
 */
final readonly class DirectoryAnswer
{
    /**
     * @param  array<mixed>|null  $data  the verified file, decoded, or null
     * @param  string|null  $reason  why there is none, or null
     *
     * @throws InvalidArgumentException when both or neither are given
     *
     * @since next
     */
    public function __construct(
        public ?array $data,
        public ?string $reason = null,
    ) {
        if (($data === null) === ($reason === null)) {
            throw new InvalidArgumentException('A directory answer holds either data or the reason there is none.');
        }
    }

    /**
     * Whether the answer holds data.
     *
     * @since next
     */
    public function found(): bool
    {
        return $this->data !== null;
    }
}
