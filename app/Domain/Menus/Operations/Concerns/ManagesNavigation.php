<?php

/**
 * What the menu Operations share: only someone who may manage navigation
 * changes a menu, a menu's name is 1 to 120 characters, and its language
 * is one of the site's, stored as null for the default (I-3360).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Concerns;

use App\Domain\Languages\SiteLanguages;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

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

    private function validateLanguage(?string $language): void
    {
        if ($language !== null && ! app(SiteLanguages::class)->has($language)) {
            throw ValidationException::withMessages(['language' => __('Choose one of the site’s languages.')]);
        }
    }

    /**
     * The language as the menu's row holds it: null for the default.
     */
    private static function storedLanguage(?string $language): ?string
    {
        return $language === null ? null : app(SiteLanguages::class)->stored($language);
    }
}
