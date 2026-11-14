<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiModel;
use App\Domain\Ai\AiPrice;
use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResult;
use LogicException;

/**
 * A provider for tests that never leaves the process: it answers each request
 * with the next scripted result or failure, and keeps every request it was
 * sent. Registered by aiFake() in tests/Pest.php.
 */
final class FakeAiProvider implements AiProvider
{
    public bool $ready = true;

    /** @var list<AiRequest> */
    public array $requests = [];

    /** @var list<AiResult|AiFailure> */
    private array $script = [];

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake AI';
    }

    public function ready(): bool
    {
        return $this->ready;
    }

    public function models(): array
    {
        // $1 a million input tokens, $2 a million output.
        $price = new AiPrice(input: 1_000_000, output: 2_000_000);

        return [
            new AiModel('fake-text', 'Fake text', [AiCapability::Text, AiCapability::StructuredOutput], $price),
            new AiModel('fake-vision', 'Fake vision', [AiCapability::Text, AiCapability::ImagesIn, AiCapability::StructuredOutput], $price),
            new AiModel('fake-unpriced', 'Fake unpriced', [AiCapability::Text]),
            new AiModel('fake-embed', 'Fake embeddings', [AiCapability::Embeddings]),
        ];
    }

    public function answer(AiResult ...$results): self
    {
        foreach ($results as $result) {
            $this->script[] = $result;
        }

        return $this;
    }

    public function fail(AiFailure ...$failures): self
    {
        foreach ($failures as $failure) {
            $this->script[] = $failure;
        }

        return $this;
    }

    public function complete(AiRequest $request): AiResult
    {
        $this->requests[] = $request;
        $next = array_shift($this->script) ?? throw new LogicException('The fake AI provider has no answer left.');

        if ($next instanceof AiFailure) {
            throw $next;
        }

        return $next;
    }
}
