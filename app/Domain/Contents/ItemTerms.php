<?php

/**
 * Works out which taxonomies' terms an item gained or lost in a change, and
 * queues one TermAssigned for the item, so every write path to an item's terms
 * announces the change the same way.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Contents\Models\Content;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Hooks\Payloads;
use App\Domain\Hooks\PendingNotifications;
use App\Domain\Import\ImportMode;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Taxonomy\TermAssigned;

/**
 * Announces an item's term changes. Called from inside an Operation, so the
 * notification waits for its outermost commit like every other one.
 *
 * @since 1.7.0
 */
final class ItemTerms
{
    /**
     * Groups the terms an item gained and lost by their taxonomy, each taxonomy
     * once, and only the taxonomies that changed. A term whose row is gone cannot
     * be placed in a taxonomy, so it is left out.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     *
     * @return array<string, array{added: list<string>, removed: list<string>}>
     *
     * @since 1.7.0
     */
    public static function changes(array $before, array $after): array
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added === [] && $removed === []) {
            return [];
        }

        $taxonomies = DB::table('terms')->whereIn('id', [...$added, ...$removed])->pluck('taxonomy', 'id')->all();
        $changes = [];

        foreach ([['added', $added], ['removed', $removed]] as [$side, $ids]) {
            foreach ($ids as $id) {
                $taxonomy = $taxonomies[$id] ?? null;

                if ($taxonomy !== null) {
                    $changes[(string) $taxonomy][$side][] = $id;
                }
            }
        }

        ksort($changes);

        return array_map(fn (array $change): array => [
            'added' => $change['added'] ?? [],
            'removed' => $change['removed'] ?? [],
        ], $changes);
    }

    /**
     * Queues one TermAssigned for the item, carrying what it gained and lost in
     * each taxonomy that changed. Nothing is worked out unless a plugin listens,
     * nothing is queued when the lists name the same terms, and nothing is done
     * during an import run, which sends no term hooks yet.
     *
     * @param  list<string>  $before  the item's terms before the change
     * @param  list<string>  $after  the item's terms after the change
     *
     * @since 1.7.0
     */
    public static function announce(Content $content, array $before, array $after, ?string $actorId): void
    {
        if (app(ImportMode::class)->active() || ! app(HookDispatcher::class)->wants(TermAssigned::class)) {
            return;
        }

        $changes = self::changes($before, $after);

        if ($changes === []) {
            return;
        }

        app(PendingNotifications::class)->add(TermAssigned::class, fn (): TermAssigned => new TermAssigned(Payloads::item($content), $changes, $actorId));
    }
}
