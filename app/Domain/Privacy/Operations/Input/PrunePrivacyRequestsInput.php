<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What PrunePrivacyRequests needs: only who is asking, which must be the
 * operator (null).
 */
final readonly class PrunePrivacyRequestsInput implements OperationInput
{
    public function __construct(
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
