<?php

/**
 * What PrunePrivacyRequests needs: only who is asking, which must be the
 * operator (null).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
