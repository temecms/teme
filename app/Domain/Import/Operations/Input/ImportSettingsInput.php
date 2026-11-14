<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What ImportSettings needs: the source and run, the step whose map row keeps the fingerprints, its values by group and key, and who is asking.
 */
final readonly class ImportSettingsInput implements OperationInput
{
    /**
     * @param  array<string, array<string, mixed>>  $values  group => key => value
     */
    public function __construct(
        public string $sourceId,
        public string $runId,
        public string $step,
        public array $values,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
