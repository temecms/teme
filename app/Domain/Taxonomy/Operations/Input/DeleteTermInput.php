<?php

/**
 * What DeleteTerm needs: which term, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class DeleteTermInput implements OperationInput
{
    public function __construct(
        public string $termId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
