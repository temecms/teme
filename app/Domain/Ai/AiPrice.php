<?php

/**
 * What a model costs, in whole US micro-dollars per million tokens (I-1303):
 * input, output, cache reads and cache writes. A cache price left out costs
 * as input, so spend is never under-counted.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai;

use InvalidArgumentException;

final readonly class AiPrice
{
    private const int MILLION = 1_000_000;

    public function __construct(
        public int $input,
        public int $output,
        public ?int $cacheRead = null,
        public ?int $cacheWrite = null,
    ) {
        foreach ([$input, $output, $cacheRead ?? 0, $cacheWrite ?? 0] as $price) {
            if ($price < 0) {
                throw new InvalidArgumentException('An AI price can\'t be negative.');
            }
        }
    }

    /**
     * The cost of one request, in whole micro-dollars, rounded up. The four
     * counts never overlap (AiUsage), so nothing is charged twice.
     */
    public function cost(AiUsage $usage): int
    {
        $total = $usage->input * $this->input
            + $usage->output * $this->output
            + $usage->cacheRead * ($this->cacheRead ?? $this->input)
            + $usage->cacheWrite * ($this->cacheWrite ?? $this->input);

        return intdiv($total + self::MILLION - 1, self::MILLION);
    }
}
