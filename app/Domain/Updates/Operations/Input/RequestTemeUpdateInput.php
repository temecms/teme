<?php

/**
 * What RequestTemeUpdate needs: the release asked for, whether it is an update or a roll back, and who asks, or null for the operator.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Operations\Input;

use App\Domain\Updates\Requests\UpdateKind;
use Teme\Contracts\Operations\OperationInput;

final readonly class RequestTemeUpdateInput implements OperationInput
{
    public function __construct(
        public string $version,
        public UpdateKind $kind,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
