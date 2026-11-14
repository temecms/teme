<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Concerns;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Addresses\Paths;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Tree\SubtreeAddresses;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;

/**
 * For an Operation that changes an item's translation group (I-3360): the
 * home page's version in another language has that language's home as its
 * address, so joining or leaving the home page's group moves it. The tree
 * locks come first, then the rows; each published item whose address
 * changes is moved, the old address kept as history, and both purged
 * after commit.
 */
trait MovesVersionAddresses
{
    /**
     * Takes the tree lock of every type named that nests, in one order,
     * before any row lock (D5's order: the languages, the tree, the rows).
     *
     * @param  list<string>  $contentIds
     */
    private function takeTrees(array $contentIds): void
    {
        $types = Content::withTrashed()->whereIn('id', $contentIds)->distinct()->orderBy('type')->pluck('type')->all();

        foreach ($types as $type) {
            app(SubtreeAddresses::class)->lock((string) $type);
        }
    }

    /**
     * Moves each published item, read again, to the address its group now
     * gives it, refusing one another target holds; the old address stays as
     * history and redirects, and both are purged after commit.
     *
     * @param  Collection<array-key, Content>  $before  the items as locked, before the group changed
     *
     * @throws ValidationException
     */
    private function moveVersionAddresses(Collection $before, string $key): void
    {
        $footprints = Footprints::contents($before->all());

        foreach ($before as $item) {
            $now = Content::query()->find($item->id);

            if ($now === null || $now->status !== 'published') {
                continue;
            }

            $address = Addresses::forContent($now);

            if ($address === null || $address === Paths::current('content', (string) $now->id)) {
                continue;
            }

            $clash = Paths::conflict($address, 'content', (string) $now->id);

            if ($clash !== null) {
                throw ValidationException::withMessages([$key => $clash]);
            }

            app(PathProjection::class)->syncContent($now);
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $now->id, [...($footprints[(string) $now->id] ?? []), ...Footprints::content($now)]));
        }
    }
}
