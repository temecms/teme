<?php

/**
 * One request as laravel/ai asks it (I-311): the system message as its
 * instructions, any earlier user messages, the answer's token limit and the
 * options that set a model's reasoning. Made for one request and dropped: it
 * remembers nothing and has no tools.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;

/**
 * @phpstan-consistent-constructor
 */
class RequestAgent implements Agent, Conversational, HasProviderOptions
{
    use Promptable;

    /**
     * @param  list<UserMessage>  $earlier
     * @param  array<string, mixed>  $options
     * @param  array<string, Type>  $types  the answer's shape, when one is asked for
     */
    public function __construct(
        protected readonly string $system,
        protected readonly array $earlier,
        protected readonly int $limit,
        protected readonly array $options,
        protected readonly array $types = [],
    ) {}

    public function instructions(): string
    {
        return $this->system;
    }

    public function messages(): iterable
    {
        return $this->earlier;
    }

    /**
     * The answer's token limit, which the library sends as each service's own field.
     */
    public function maxTokens(): int
    {
        return $this->limit;
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->options;
    }
}
