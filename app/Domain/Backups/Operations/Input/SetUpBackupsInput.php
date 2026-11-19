<?php

/**
 * What SetUpBackups needs: the first place, or none to show a new key for a
 * setup not yet finished; the recovery key's label; who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use App\Domain\Backups\NewPlace;
use Teme\Contracts\Operations\OperationInput;

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
