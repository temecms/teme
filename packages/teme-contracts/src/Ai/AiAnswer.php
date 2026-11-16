<?php

declare(strict_types=1);

namespace Teme\Contracts\Ai;

/**
 * The answer: text, or data when a schema was sent; the model; the tokens;
 * the cost when known; and the ID of the request's record.
 *
 * @since 0.9.0
 */
final readonly class AiAnswer
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  int|null  $costMicrodollars  null when the model has no price
     *
     * @since 0.9.0
     */
    public function __construct(
        public string $model,
        public ?string $text,
        public ?array $data,
        public int $inputTokens,
        public int $outputTokens,
        public ?int $costMicrodollars,
        public string $requestId,
    ) {}
}
