<?php

declare(strict_types=1);

namespace App\Domain\Addresses;

use App\Domain\Addresses\Models\Path;
use App\Domain\Contents\Models\Content;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Queries\TermTree;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the path table in step with the addresses things should have, as
 * ContentCounts and TermProjection keep theirs: called inside the
 * Operation's transaction, after its own changes are saved. It never checks
 * for clashes; the Operation has already validated.
 */
final readonly class PathProjection
{
    public function __construct(private TermTree $tree) {}

    /**
     * Brings one item's rows in step with the address it should have now;
     * false when another target holds that address, and it kept its own.
     */
    public function syncContent(Content $content): bool
    {
        return $this->sync('content', (string) $content->id, Addresses::forContent($content), keep: true);
    }

    /**
     * Brings one item's rows in step with an address already worked out,
     * as the pages under a moved page are (I-3363); false when another
     * target holds that address, and it kept its own.
     */
    public function syncContentTo(Content $content, ?string $wanted): bool
    {
        return $this->sync('content', (string) $content->id, $wanted, keep: true);
    }

    /**
     * Brings one term's rows in step, and every descendant's: a term's
     * address is part of theirs. A recompute that walks every term already
     * reaches the descendants, and passes false.
     */
    public function syncTerm(Term $term, bool $withDescendants = true): void
    {
        if (! $withDescendants) {
            $this->sync('term', (string) $term->id, Addresses::forTerm($term));

            return;
        }

        foreach ($this->tree->descendantIds((string) $term->id) as $id) {
            $each = $id === (string) $term->id ? $term : Term::query()->find($id);

            if ($each !== null) {
                $this->sync('term', $id, Addresses::forTerm($each));
            }
        }
    }

    /**
     * Brings one author's rows in step.
     */
    public function syncUser(User $user): void
    {
        $this->sync('user', (string) $user->id, Addresses::forUser($user));
    }

    /**
     * Brings one person with no account's rows in step (I-3366); a person
     * who is an account's, or no longer exists, keeps none.
     */
    public function syncPerson(string $personId): void
    {
        $person = DB::table('people')->where('id', $personId)->whereNull('user_id')->first(['slug']);

        $this->sync('person', $personId, $person === null ? null : Addresses::forPerson((string) $person->slug));
    }

    /**
     * Keeps the old address of each type's archive page that moved, so it
     * answers with a 301 (I-412): its own old row at the new address goes,
     * since that address is live again; at the old one a history row is
     * written for it, in place of another target's history row there. A
     * live row at the old address is someone else's, and is left alone: the
     * archive page never had it.
     *
     * @param  array<string, string>  $before  type key => its archive page's address before the change
     * @param  array<string, string>  $after  the same after it
     */
    public function archivesMoved(array $before, array $after): void
    {
        foreach ($before as $type => $from) {
            $to = $after[$type] ?? $from;

            if ($to === $from) {
                continue;
            }

            Path::query()->where('path', $to)->where('target_type', TypeArchives::TARGET)->where('target_id', $type)->where('is_current', false)->delete();

            $existing = Path::query()->where('path', $from)->lockForUpdate()->first();

            if ($existing !== null && ($existing->is_current || ($existing->target_type === TypeArchives::TARGET && $existing->target_id === $type))) {
                continue;
            }

            $existing?->delete();

            Path::query()->create(['path' => $from, 'target_type' => TypeArchives::TARGET, 'target_id' => $type, 'is_current' => false, 'replaced_at' => now()]);
        }
    }

    /**
     * Removes every row of a target that no longer exists.
     */
    public function forget(string $type, string $id): void
    {
        Path::query()->where('target_type', $type)->where('target_id', $id)->delete();
    }

    /**
     * The old current row becomes history; the target's own history row at
     * the new address becomes current again, or a new row is written, after
     * any other target's history row there is removed. With no address, the
     * current row only becomes history. With $keep, an item's: when another
     * target holds the new address now, nothing changes and the item keeps
     * its current row (I-3363), as a page under a moved page whose new
     * address is taken does; returns false then.
     */
    private function sync(string $type, string $id, ?string $wanted, bool $keep = false): bool
    {
        $current = Path::query()->where('target_type', $type)->where('target_id', $id)->where('is_current', true)->lockForUpdate()->first();

        if ($current !== null && $current->path === $wanted) {
            return true;
        }

        $existing = $wanted === null ? null : Path::query()->where('path', $wanted)->lockForUpdate()->first();
        $own = $existing !== null && $existing->target_type === $type && $existing->target_id === $id;

        // A live target's address comes first; this one keeps its own.
        // Without $keep, the unique index refuses the insert loudly below:
        // an Operation skipped its check.
        if ($keep && $existing !== null && $existing->is_current && ! $own) {
            return false;
        }

        $current?->update(['is_current' => false, 'replaced_at' => now()]);

        if ($wanted === null) {
            return true;
        }

        if ($own) {
            $existing->update(['is_current' => true, 'replaced_at' => null]);

            return true;
        }

        // A history row is a courtesy; a live target's address comes first.
        if ($existing !== null && ! $existing->is_current) {
            $existing->delete();
        }

        Path::query()->create(['path' => $wanted, 'target_type' => $type, 'target_id' => $id, 'is_current' => true]);

        return true;
    }
}
