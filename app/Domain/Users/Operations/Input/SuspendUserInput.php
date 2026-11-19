<?php

/**
 * What `SuspendUser` needs: whose access changes, whether to suspend or
 * reactivate, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class SuspendUserInput implements OperationInput
{
    /**
     * @param  bool  $suspended  false reactivates
     */
    public function __construct(
        public string $userId,
        public bool $suspended,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
