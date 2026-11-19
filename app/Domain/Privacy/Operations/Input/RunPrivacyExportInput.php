<?php

/**
 * What RunPrivacyExport needs: the export request to take one step through,
 * and who is asking (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RunPrivacyExportInput implements OperationInput
{
    public function __construct(
        public string $requestId,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
