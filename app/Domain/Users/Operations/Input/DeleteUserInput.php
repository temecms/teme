<?php

/**
 * What `DeleteUser` needs: who is deleted, who their content goes to, and who
 * is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class DeleteUserInput implements OperationInput
{
    /**
     * @param  string|null  $reassignTo  required when the person authored anything
     */
    public function __construct(
        public string $userId,
        public ?string $reassignTo,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
