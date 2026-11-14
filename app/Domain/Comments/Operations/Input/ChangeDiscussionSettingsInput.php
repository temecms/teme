<?php

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What ChangeDiscussionSettings needs: the Discussion settings to change,
 * keyed by setting, the group's version the form was opened on, and who is
 * asking.
 */
final readonly class ChangeDiscussionSettingsInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $values  setting key => submitted value
     */
    public function __construct(
        public array $values,
        public int $expectedVersion,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
