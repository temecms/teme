<?php

/**
 * An export file's authors in the companion's `users` shape. A file has no
 * password, role, biography, website or sign-up date, so those stay empty.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

final class UserRows
{
    /**
     * Returns the payload of a channel's `wp:author`, by its fields without the `wp:` prefix.
     *
     * @param  array<string, string>  $fields
     *
     * @return array<string, mixed>
     */
    public static function fromAuthor(int $id, array $fields): array
    {
        $login = trim($fields['author_login'] ?? '');

        return [
            'id' => $id,
            'login' => $login,
            'email' => trim($fields['author_email'] ?? ''),
            'nicename' => $login,
            'display_name' => $fields['author_display_name'] ?? '',
            'first_name' => $fields['author_first_name'] ?? '',
            'last_name' => $fields['author_last_name'] ?? '',
            'description' => '',
            'url' => '',
            'registered' => null,
            'roles' => [],
            'password_hash' => null,
            'published_posts' => 0,
        ];
    }

    /**
     * Returns the payload of an author the file names only as an item's creator.
     *
     * @return array<string, mixed>
     */
    public static function fromLogin(int $id, string $login): array
    {
        return self::fromAuthor($id, ['author_login' => $login]);
    }
}
