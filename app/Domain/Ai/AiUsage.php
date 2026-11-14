<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use InvalidArgumentException;

/**
 * The tokens one request used, in four counts that never overlap: input not
 * read from or written to the cache, output, cache reads and cache writes. A
 * provider whose service counts input inclusively subtracts the cached tokens
 * first, so AiPrice charges nothing twice.
 */
final readonly class AiUsage
{
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
    ) {
        if (min($input, $output, $cacheRead, $cacheWrite) < 0) {
            throw new InvalidArgumentException('AI usage counts can\'t be negative.');
        }
    }
}
