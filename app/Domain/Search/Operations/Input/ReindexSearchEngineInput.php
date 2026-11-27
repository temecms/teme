<?php

/**
 * Asking the chosen search engine to take every published item again. A null
 * actor is the operator at the server's shell.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Search\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ReindexSearchEngineInput implements OperationInput
{
    public function __construct(
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
