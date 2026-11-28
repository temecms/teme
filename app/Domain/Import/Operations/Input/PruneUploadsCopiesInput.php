<?php

/**
 * What the uploads copies' prune needs: who is asking (null for the
 * operator's daily command).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class PruneUploadsCopiesInput implements OperationInput
{
    public function __construct(
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
