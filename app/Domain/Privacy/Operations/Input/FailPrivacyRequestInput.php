<?php

/**
 * What FailPrivacyRequest needs: the request that stopped, why, in at most
 * 500 characters, and who is asking (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class FailPrivacyRequestInput implements OperationInput
{
    public function __construct(
        public string $requestId,
        public string $failure,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
