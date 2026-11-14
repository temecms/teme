<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases;

use App\Domain\Import\ImportOutcome;
use InvalidArgumentException;

/**
 * What a phase made of one object: created or updated as a Teme item, or
 * skipped, saying why, never silently (I-345); its warnings; and facts the
 * map keeps for it (a post's language and translation group).
 */
final readonly class ImportResult
{
    /**
     * @param  list<ImportMessage>  $messages
     * @param  array<string, mixed>  $facts
     */
    private function __construct(
        public ImportOutcome $outcome,
        public ?string $temeType,
        public ?string $temeId,
        public array $messages = [],
        public array $facts = [],
    ) {}

    public static function created(string $temeType, string $temeId): self
    {
        return self::imported(ImportOutcome::Created, $temeType, $temeId);
    }

    public static function updated(string $temeType, string $temeId): self
    {
        return self::imported(ImportOutcome::Updated, $temeType, $temeId);
    }

    public static function skipped(ImportMessage $why): self
    {
        return new self(ImportOutcome::Skipped, null, null, [$why]);
    }

    public function withWarning(ImportMessage $warning): self
    {
        return new self($this->outcome, $this->temeType, $this->temeId, [...$this->messages, $warning], $this->facts);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function withFacts(array $facts): self
    {
        return new self($this->outcome, $this->temeType, $this->temeId, $this->messages, [...$this->facts, ...$facts]);
    }

    private static function imported(ImportOutcome $outcome, string $temeType, string $temeId): self
    {
        if (trim($temeType) === '' || trim($temeId) === '') {
            throw new InvalidArgumentException('A created or updated object needs its Teme type and ID.');
        }

        return new self($outcome, $temeType, $temeId);
    }
}
