<?php

/**
 * What ChangeDiscussionSettings needs: the Discussion settings to change,
 * keyed by setting, the group's version the form was opened on, and who is
 * asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
