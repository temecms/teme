<?php

declare(strict_types=1);

namespace App\Domain\Redirects\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What ImportRedirects needs: the CSV file's text, and who is asking.
 */
final readonly class ImportRedirectsInput implements OperationInput
{
    public function __construct(
        public string $csv,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
