<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiInput;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\AiSubject;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiTask;
use App\Domain\Contents\Operations\SaveDraft;

/**
 * A task for tests: a plain-text rewrite of one subject, its prompt's hash
 * right unless a test gives a wrong one. Registered by aiTask() in
 * tests/Pest.php.
 */
final readonly class FixtureTask implements AiTask
{
    public const string INSTRUCTIONS = 'Rewrite the text more briefly. Answer with the new text alone.';

    /**
     * @param  list<AiCapability>  $capabilities
     */
    public function __construct(
        private string $key = 'fixture',
        private array $capabilities = [AiCapability::Text],
        private AiSubjectType $subject = AiSubjectType::Content,
        private ?string $hash = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return ucfirst(str_replace('-', ' ', $this->key));
    }

    public function subject(): AiSubjectType
    {
        return $this->subject;
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function prompt(): AiPrompt
    {
        return new AiPrompt($this->key, 1, self::INSTRUCTIONS, $this->hash ?? AiPrompt::hashOf(self::INSTRUCTIONS));
    }

    public function timeoutSeconds(): int
    {
        return 30;
    }

    public function maxOutputTokens(): int
    {
        return 200;
    }

    public function outputSchema(): ?array
    {
        return null;
    }

    public function input(AiSubject $subject): AiInput
    {
        return new AiInput(['subject' => $subject->id]);
    }

    public function check(AiResult $result): array
    {
        $text = trim($result->text ?? '');

        if ($text === '' || mb_strlen($text) > 2_000) {
            throw AiFailure::invalidOutput();
        }

        return ['text' => $text];
    }

    public function appliedBy(): string
    {
        return SaveDraft::class;
    }
}
