<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

/**
 * What the menu Operations share: only someone who may manage navigation
 * changes a menu, and a menu's name is 1 to 120 characters.
 */
trait ManagesNavigation
{
    private function authorizeNavigation(OperationInput $input): void
    {
        $actor = $input->actorId() === null ? null : User::query()->find($input->actorId());

        if ($actor === null || ! $actor->can('navigation.manage')) {
            throw new AuthorizationException('Not permitted to manage navigation.');
        }
    }

    private function validateName(string $name): void
    {
        Validator::make(['name' => trim($name)], ['name' => ['required', 'string', 'max:120']])->validate();
    }
}
