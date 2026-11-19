<?php

/**
 * What ChangeBackupSchedule needs: the values as the form sent them, the
 * version the form was opened on, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ChangeBackupScheduleInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public array $values,
        public int $expectedVersion,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
