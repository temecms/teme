<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use InvalidArgumentException;

/**
 * One model's answer: its text, or its data decoded from JSON when the request
 * carried a schema; the model as the provider named it; the tokens it used;
 * and how long it took.
 */
final readonly class AiResult
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public string $model,
        public AiUsage $usage,
        public int $latencyMs,
        public ?string $text = null,
        public ?array $data = null,
    ) {
        if ($latencyMs < 0) {
            throw new InvalidArgumentException('AI usage counts can\'t be negative.');
        }

        if (($text === null) === ($data === null)) {
            throw new InvalidArgumentException('An AI result carries text or data, never both or neither.');
        }
    }
}
