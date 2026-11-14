<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

/**
 * What `ConnectImportSource` needs: the WordPress site's address, an
 * administrator's user name there, the application password made for Teme,
 * and who is asking (null for the operator).
 */
final readonly class ConnectImportSourceInput implements OperationInput
{
    public function __construct(
        public string $address,
        public string $userName,
        #[SensitiveParameter] public string $applicationPassword,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
