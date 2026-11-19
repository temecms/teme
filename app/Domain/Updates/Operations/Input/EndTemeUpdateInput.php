<?php

/**
 * What EndTemeUpdate needs: the request, how it ended, and the sentence that says why. Only the operator, the updater or a restore, runs it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Operations\Input;

use App\Domain\Updates\Requests\UpdateStatus;
use Teme\Contracts\Operations\OperationInput;

final readonly class EndTemeUpdateInput implements OperationInput
{
    public function __construct(
        public string $updateId,
        public UpdateStatus $status,
        public ?string $error = null,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
