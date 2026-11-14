<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * One AI service Teme can ask: Anthropic, OpenAI and
 * the rest, each a class registered under AiProviders::TAG from its part's own
 * provider. Teme's own contract, with no library type in it (I-314); a library
 * stays inside the class that implements it.
 */
interface AiProvider
{
    /**
     * Its key, lower-case words joined by hyphens, unique among providers:
     * what a model choice names before its colon.
     */
    public function key(): string;

    /**
     * Its name, as people read it: "Sent to Anthropic" (I-1304).
     */
    public function label(): string;

    /**
     * Whether it has what it needs to answer, such as a key. A provider that
     * isn't ready offers no task.
     */
    public function ready(): bool;

    /**
     * The models it offers, each with its capabilities and, where known, its
     * price.
     *
     * @return list<AiModel>
     */
    public function models(): array;

    /**
     * Asks one model, waiting at most the request's timeout. Any failure is an
     * AiFailure, made by its own constructors, never carrying the service's
     * words.
     *
     * @throws AiFailure
     */
    public function complete(AiRequest $request): AiResult;

    // Later (I-886): embed(), for the models that declare embeddings.

    // Later (I-887): rerank and images out, for the models that declare them.

    // Later (I-1319): decide(), a decision model's typed questions answered with probabilities.
}
