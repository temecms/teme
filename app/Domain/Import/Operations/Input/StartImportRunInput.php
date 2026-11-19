<?php

/**
 * What `StartImportRun` needs: the site, the kind of run, its options and choices, and who starts it (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use App\Domain\Import\ImportRunKind;
use Teme\Contracts\Operations\OperationInput;

final readonly class StartImportRunInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $options
     * @param  array<int|string, mixed>  $choices
     */
    public function __construct(
        public string $sourceId,
        public ImportRunKind $kind,
        public array $options = [],
        public array $choices = [],
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
