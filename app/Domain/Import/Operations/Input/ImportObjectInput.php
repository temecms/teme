<?php

/**
 * What `ImportObject` needs: the attempt's token, the phase, the run's context, the object and its item and hash, and what the phase prepared.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\PreparedObject;
use App\Domain\Import\Phases\SourceRef;
use Teme\Contracts\Operations\OperationInput;

final readonly class ImportObjectInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $item
     */
    public function __construct(
        public string $token,
        public string $phase,
        public ImportContext $context,
        public SourceRef $ref,
        public array $item,
        public string $hash,
        public ?PreparedObject $prepared,
    ) {}

    public function actorId(): ?string
    {
        return $this->context->actorId();
    }
}
