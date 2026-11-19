<?php

/**
 * What `RecordImportFailure` needs: the run and its token, the object that failed and why, and who acts.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\SourceRef;
use Teme\Contracts\Operations\OperationInput;

final readonly class RecordImportFailureInput implements OperationInput
{
    public function __construct(
        public string $runId,
        public string $token,
        public SourceRef $ref,
        public ImportMessage $why,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
