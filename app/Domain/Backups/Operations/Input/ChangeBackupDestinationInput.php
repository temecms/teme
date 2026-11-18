<?php

/**
 * What ChangeBackupDestination needs: the place, its name and location as
 * they should be, new credentials (none keeps the stored ones), and who is
 * asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

final readonly class ChangeBackupDestinationInput implements OperationInput
{
    /**
     * @param  array<string, string|int>  $location
     * @param  array<string, string>|null  $credentials
     */
    public function __construct(
        public string $placeId,
        public string $name,
        public array $location,
        #[SensitiveParameter] private ?array $credentials = null,
        public ?string $actorId = null,
    ) {}

    /**
     * The new credentials, or null to keep the stored ones.
     *
     * @return array<string, string>|null
     */
    public function credentials(): ?array
    {
        return $this->credentials;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['placeId' => $this->placeId, 'name' => $this->name, 'location' => $this->location, 'credentials' => '[hidden]', 'actorId' => $this->actorId];
    }
}
