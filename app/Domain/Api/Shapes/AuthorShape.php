<?php

/**
 * A byline as the API gives it: name, slug, address and bio, as the
 * person's author page shows them. A person need not have an account.
 * Never an ID, an email or a role.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api\Shapes;

use App\Domain\Addresses\Addresses;

final class AuthorShape
{
    /**
     * @param  object{name: string, slug: string, bio: string|null}  $user  a person as `People::shown()` gives them
     * @param  string|null  $path  the author's current address as `paths` stores it, or null for none
     *
     * @return array{name: string, slug: string, path: string|null, url: string|null, bio: string|null}
     */
    public static function of(object $user, ?string $path): array
    {
        $relative = $path === null ? null : Addresses::toUrl($path);
        $bio = trim((string) ($user->bio ?? ''));

        return [
            'name' => (string) $user->name,
            'slug' => (string) $user->slug,
            'path' => $relative,
            'url' => $relative === null ? null : rtrim(url('/'), '/').$relative,
            'bio' => $bio === '' ? null : $bio,
        ];
    }
}
