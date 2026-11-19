<?php

/**
 * What ClearRunMessages needs: the run, the first word of the codes to clear with its dot, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ClearRunMessagesInput implements OperationInput
{
    public function __construct(
        public string $runId,
        public string $prefix,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
