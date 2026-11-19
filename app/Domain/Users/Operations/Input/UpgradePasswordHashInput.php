<?php

/**
 * What `UpgradePasswordHash` needs: whose hash, the password they have just
 * signed in with, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

final readonly class UpgradePasswordHashInput implements OperationInput
{
    public function __construct(
        public string $userId,
        #[SensitiveParameter]
        public string $password,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
