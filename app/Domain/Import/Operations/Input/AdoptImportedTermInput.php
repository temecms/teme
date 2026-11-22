<?php

/**
 * Input of AdoptImportedTerm: the site, the term's ID in its export file's
 * map row, and the companion's ID for the same term.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class AdoptImportedTermInput implements OperationInput
{
    public function __construct(
        public string $sourceId,
        public string $fromId,
        public string $toId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
