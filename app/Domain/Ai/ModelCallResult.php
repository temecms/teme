<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * How one call through a task's model and fallback ended: the model last
 * asked, its answer or the failure's type, the tokens that count (a billed
 * failure's included, I-1322), their cost when known, the time taken, and the
 * services the text was sent to.
 */
final readonly class ModelCallResult
{
    /**
     * @param  list<string>  $sentTo  each provider's label, in the order asked
     */
    public function __construct(
        public ModelRef $ref,
        public ?AiResult $result,
        public ?AiFailureType $failure,
        public AiUsage $usage,
        public ?int $cost,
        public string $model,
        public int $latencyMs,
        public array $sentTo,
    ) {}
}
