<?php

/**
 * What ImportRedirects needs: the CSV file's text, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
