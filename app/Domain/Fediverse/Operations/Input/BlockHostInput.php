<?php

/**
 * What BlockHost needs: the server's name as typed, why, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class BlockHostInput implements OperationInput
{
    public function __construct(
        public string $host,
        public ?string $reason,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
