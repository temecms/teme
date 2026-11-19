<?php

/**
 * Keeps a departing account's credit (issue 396): what it wrote still names
 * it, now as a person with no account who keeps the account's name, address
 * and biography, and its author page. Inside the deleting Operation's
 * perform(), after its locks on the user and their items.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\People\Operations\Concerns;

use App\Domain\Addresses\PathProjection;
use App\Domain\People\Credits;
use App\Models\User;
use Illuminate\Support\Facades\DB;

trait KeepsDepartedWriters
{
    use GivesAccountsPeople;

    /**
     * Whether anything credits the account: an item it owns that has no
     * bylines, in any state, or a byline that names its person.
     */
    protected function isCredited(int $userId): bool
    {
        return app(Credits::class)->creditsAccount($userId);
    }

    /**
     * Credits the account's person by name on every item it owns that
     * credited it only as its owner; the person's ID, made now if no byline
     * had named the account.
     */
    protected function creditByName(int $userId): string
    {
        $personId = $this->personIdsFor([$userId])[$userId];

        DB::insert(<<<'SQL'
            INSERT INTO content_bylines (content_id, person_id, position)
            SELECT c.id, ?, 0 FROM contents c
            WHERE c.author_id = ? AND NOT EXISTS (SELECT 1 FROM content_bylines o WHERE o.content_id = c.id)
            ON CONFLICT DO NOTHING
            SQL, [$personId, $userId]);

        return $personId;
    }

    /**
     * Gives the person the account's name, address and biography and takes
     * it off the account, so the account's delete leaves it; then hands it
     * the account's author page, its current and old rows alike, so the
     * address and every redirect to it stay.
     */
    protected function keepAsPerson(User $user, string $personId): void
    {
        DB::update(
            'UPDATE people SET name = ?, slug = ?, bio = ?, user_id = NULL, updated_at = now() WHERE id = ?',
            [(string) $user->name, (string) $user->slug, $user->bio, $personId],
        );

        DB::update(
            "UPDATE paths SET target_type = 'person', target_id = ? WHERE target_type = 'user' AND target_id = ?",
            [$personId, (string) $user->id],
        );

        // An account whose page was never written gets one now.
        if (! DB::table('paths')->where('target_type', 'person')->where('target_id', $personId)->where('is_current', true)->exists()) {
            app(PathProjection::class)->syncPerson($personId);
        }
    }
}
