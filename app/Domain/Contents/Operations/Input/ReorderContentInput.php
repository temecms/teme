<?php

/**
 * What ReorderContent needs: which item, which way it moves among its
 * siblings (up or down), and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ReorderContentInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public string $direction,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
