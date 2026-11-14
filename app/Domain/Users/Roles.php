<?php

declare(strict_types=1);

namespace App\Domain\Users;

/**
 * The core roles, most powerful first. RoleSeeder creates them; forms and
 * settings offer them.
 */
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
