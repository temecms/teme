<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use App\Models\User;

/**
 * Jobs started by a given person and people whose email isn't verified yet,
 * for the tests of the header's jobs button and bell.
 */
final class HeaderFixtures
{
    /**
     * Writes a job this person started, by default running, and returns its
     * ID; $changes by column, as JobsFixtures::job() takes them.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function jobOf(User $person, array $changes = []): string
    {
        return JobsFixtures::job(['starter' => 'person', 'started_by' => $person->id, ...$changes]);
    }

    /**
     * Creates someone who hasn't verified their email, holding this role.
     */
    public static function unverified(string $role = 'author'): User
    {
        return User::factory()->unverified()->create()->assignRole($role);
    }
}
