<?php

/**
 * The core roles, most powerful first. RoleSeeder creates them; forms and
 * settings offer them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users;

final class Roles
{
    public const string ADMINISTRATOR = 'administrator';

    /** @var array<string, string> */
    public const array LABELS = [
        'administrator' => 'Administrator',
        'editor' => 'Editor',
        'author' => 'Author',
        'contributor' => 'Contributor',
    ];
}
