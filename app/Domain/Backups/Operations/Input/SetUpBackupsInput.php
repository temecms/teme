<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use App\Domain\Backups\NewPlace;
use Teme\Contracts\Operations\OperationInput;

/**
 * What SetUpBackups needs: the first place, or none to show a new key for a
 * setup not yet finished; the recovery key's label; who is asking.
 */
final readonly class SetUpBackupsInput implements OperationInput
{
    public function __construct(
        public ?NewPlace $place,
        public string $label = 'Recovery key',
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
