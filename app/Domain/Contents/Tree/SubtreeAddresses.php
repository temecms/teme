<?php

/**
 * Moves the pages under a page when what their addresses are made of
 * changes (I-3363): its slug, its place, or whether it has an address at
 * all. Called inside the Operation's transaction, after the page's own
 * change and its own address; each moved page keeps its old address as
 * history, answering 301.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Tree;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressPattern;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\PathProjection;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\Jobs\MoveSubtreeAddresses;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Visibility;
use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Support\Security\JobActor;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cache\CacheKeysFor;

final readonly class SubtreeAddresses
{
    /** The most pages moved in the save itself; above it, a queued job moves them all. */
    public const int INLINE = 50;

    public function __construct(private ContentTree $tree, private PathProjection $paths, private ContentTypeRegistry $types) {}

    /**
     * The {parent} value the item's children get: its own parents' that
     * have an address, then its own slug while it has one.
     */
    public function childChain(Content $item): string
    {
        $chain = $this->tree->parentPath($item->parent_id);

        return self::passOn($chain, $item->trashed(), (string) $item->status, $item->visibility, (string) $item->slug);
    }

    /**
     * Whether any page sits under the item, so that what its children's
     * addresses are made of is worth working out (one statement).
     */
    public function hasChildren(Content $item): bool
    {
        return $this->tree->hasChildren((string) $item->id);
    }

    /**
     * Why the pages under the item can't take the chain $chainAfter: the
     * first one whose address would be another target's, in words; null
     * when none would, or when it has none.
     */
    public function clash(Content $item, string $chainAfter): ?string
    {
        [$chains, $slugs] = $this->chains((string) $item->id, $chainAfter);
        $wanted = $this->addresses($item, $chains, $slugs);

        if ($wanted === []) {
            return null;
        }

        $holders = $this->holders($wanted, (string) $item->id);

        foreach ($wanted as $id => $address) {
            $holder = $holders[$address] ?? null;

            if ($holder !== null) {
                $title = (string) DB::table('contents')->where('id', $id)->value('title');

                return __('Its page :title would move to :address, which is used by :holder.', [
                    'title' => '“'.$title.'”',
                    'address' => Addresses::toUrl($address, (string) $item->type),
                    'holder' => Paths::describe($holder[0], $holder[1]),
                ]);
            }
        }

        return null;
    }

    /**
     * Takes the type's tree lock when its items nest, as the first statement
     * of an Operation that may move a branch (I-3363); answers whether it
     * did. A type that doesn't nest costs nothing.
     */
    public function lock(?string $type): bool
    {
        if ($type === null || ! $this->types->has($type) || ! $this->types->get($type)->hierarchical) {
            return false;
        }

        TreeLock::take($type);

        return true;
    }

    /**
     * Moves the pages under the item from the chain they had to the one
     * they have now; nothing when the chain is unchanged or it has none. At
     * most INLINE move in the transaction; above that, a tracked job moves
     * them all after commit. A page whose new address another target holds
     * keeps its old one, and is named in the outcome.
     */
    public function follow(Content $item, string $chainBefore, ?string $actorId): SubtreeOutcome
    {
        $chainAfter = $this->childChain($item);

        if ($chainAfter === $chainBefore) {
            return new SubtreeOutcome;
        }

        [$chains] = $this->chains((string) $item->id, $chainAfter);

        if ($chains === []) {
            return new SubtreeOutcome;
        }

        $outcome = count($chains) > self::INLINE
            ? $this->queue($item, count($chains), $actorId)
            : $this->move($item, $chains);

        app(SubtreeMoves::class)->add($outcome);

        return $outcome;
    }

    /**
     * Moves these pages now, under their row locks; one whose new address
     * another target holds keeps its old one.
     *
     * @param  array<string, string>  $chains
     */
    private function move(Content $item, array $chains): SubtreeOutcome
    {
        // Later (issue 364): announce the address change here.
        $pages = Content::query()->whereIn('id', array_keys($chains))->orderBy('id')->lockForUpdate()->get();
        $before = Footprints::contents($pages);
        $moved = [];
        $kept = [];

        foreach ($pages as $page) {
            $id = (string) $page->id;
            $wanted = Addresses::forContent($page, $chains[$id]);

            if ($this->paths->syncContentTo($page, $wanted)) {
                $moved[] = $page;
            } else {
                $kept[] = $this->keptLine($page, (string) $wanted);
            }
        }

        $after = Footprints::contents($moved);
        $purges = [];

        foreach ($moved as $page) {
            $id = (string) $page->id;
            $purges[] = new CacheKeysFor(CacheKeysFor::CONTENT, $id, [...($before[$id] ?? []), ...($after[$id] ?? [])]);
        }

        return new SubtreeOutcome(moved: count($moved), kept: $kept, purges: $purges);
    }

    /**
     * Starts the tracked job that moves every one of them after commit;
     * until it reaches a page, the page answers at its old address.
     */
    private function queue(Content $item, int $count, ?string $actorId): SubtreeOutcome
    {
        $jobId = app(StartJob::class)->execute(new StartJobInput(
            kind: SubtreeJobKind::KEY,
            label: app(SubtreeWords::class)->label((string) $item->title),
            total: $count,
            runType: SubtreeJobKind::RUN_TYPE,
            runId: (string) $item->id,
            actorId: $actorId,
        ));
        $actor = JobActor::of($actorId);
        $rootId = (string) $item->id;

        // After commit, so the first step never reads the tree as it was.
        DB::afterCommit(fn () => MoveSubtreeAddresses::dispatch($jobId, $rootId, '', $actor));

        return new SubtreeOutcome(queued: $count, jobId: $jobId);
    }

    /**
     * A page kept at its old address, for the outcome: its title, that
     * address and the one it would have, as the site shows them, and what
     * holds the second.
     *
     * @return array{title: string, path: string, address: string, holder: string}
     */
    public function keptLine(Content $page, string $wanted): array
    {
        $holder = DB::table('paths')->where('path', $wanted)->where('is_current', true)->first(['target_type', 'target_id']);
        $old = Paths::current('content', (string) $page->id);

        return [
            'title' => (string) $page->title,
            'path' => $old === null ? '' : Addresses::toUrl($old, (string) $page->type),
            'address' => Addresses::toUrl($wanted, (string) $page->type),
            'holder' => $holder === null ? '' : Paths::describe((string) $holder->target_type, (string) $holder->target_id),
        ];
    }

    /**
     * The address each page would have with its chain, keyed by its ID,
     * leaving out any that would have none. Worked out from the rows in
     * memory unless the pattern needs more than the slug and the chain.
     *
     * @param  array<string, string>  $chains
     * @param  array<string, string>  $slugs
     *
     * @return array<string, string>
     */
    private function addresses(Content $item, array $chains, array $slugs): array
    {
        if ($chains === []) {
            return [];
        }

        $type = (string) $item->type;
        $settings = AddressSettings::current();
        $tokens = $settings->hasPattern($type) ? $settings->pattern($type)->tokens() : [];
        $wanted = [];

        if (array_diff($tokens, ['slug', 'id', AddressPattern::PARENT]) !== []) {
            foreach (Content::query()->whereIn('id', array_keys($chains))->get() as $page) {
                $address = Addresses::forContent($page, $chains[(string) $page->id]);

                if ($address !== null) {
                    $wanted[(string) $page->id] = $address;
                }
            }

            return $wanted;
        }

        foreach ($chains as $id => $chain) {
            if (isset($slugs[$id])) {
                // Every page under the item is in its language, so carries its prefix (I-3360).
                $page = (new Content)->forceFill(['id' => $id, 'type' => $type, 'language' => $item->language]);
                $address = Addresses::wouldHave($page, $slugs[$id], [], null, null, null, $chain);

                if ($address !== null) {
                    $wanted[$id] = $address;
                }
            }
        }

        return $wanted;
    }

    /**
     * What holds each of these addresses now, other than the pages
     * themselves: another target's current row, or a type's archive page.
     *
     * @param  array<string, string>  $wanted
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function holders(array $wanted, string $rootId): array
    {
        $holders = [];
        $ids = array_keys($wanted);
        $ids[] = $rootId;

        foreach (array_chunk(array_values(array_unique($wanted)), 1000) as $chunk) {
            foreach (DB::table('paths')->whereIn('path', $chunk)->where('is_current', true)->get(['path', 'target_type', 'target_id']) as $row) {
                if ($row->target_type === 'content' && in_array((string) $row->target_id, $ids, true)) {
                    continue;
                }

                $holders[(string) $row->path] = [(string) $row->target_type, (string) $row->target_id];
            }
        }

        foreach ($wanted as $address) {
            $archive = TypeArchives::at($address);

            if ($archive !== null && ! isset($holders[$address])) {
                $holders[$address] = [TypeArchives::TARGET, $archive];
            }
        }

        return $holders;
    }

    /**
     * The {parent} value of each page under the item that has an address,
     * keyed by its ID, worked out from one walk: a hidden page passes its
     * parent's chain on unchanged; and every page's slug.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function chains(string $rootId, string $rootChain): array
    {
        $childChains = [$rootId => $rootChain];
        $shown = [];
        $slugs = [];

        foreach ($this->tree->descendants($rootId) as $row) {
            $slugs[$row->id] = $row->slug;

            $chain = $childChains[$row->parent_id] ?? null;

            if ($chain === null) {
                continue;
            }

            $childChains[$row->id] = $row->shown ? ltrim($chain.'/'.$row->slug, '/') : $chain;

            if ($row->shown) {
                $shown[$row->id] = $chain;
            }
        }

        return [$shown, $slugs];
    }

    private static function passOn(string $chain, bool $trashed, string $status, mixed $visibility, string $slug): string
    {
        $hasAddress = ! $trashed && $status === 'published' && $visibility !== Visibility::Private;

        return $hasAddress ? ltrim($chain.'/'.$slug, '/') : $chain;
    }
}
