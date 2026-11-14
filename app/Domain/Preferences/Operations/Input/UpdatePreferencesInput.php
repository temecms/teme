<?php

declare(strict_types=1);

namespace App\Domain\Preferences\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * Preferences to change for the person asking, and only them: there is no
 * user id beyond the actor's, so no path sets someone else's.
 */
final readonly class UpdatePreferencesInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $preferences  key => value, as a form or the editor sends it
     */
    public function __construct(
        public array $preferences,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
