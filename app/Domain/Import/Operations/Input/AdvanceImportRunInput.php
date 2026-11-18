<?php

/**
 * What `AdvanceImportRun` needs: the run, the token of the phase that ended, the next phase and its new token.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class AdvanceImportRunInput implements OperationInput
{
    public function __construct(
        public string $runId,
        public string $fromToken,
        public string $toPhase,
        public string $toToken,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
