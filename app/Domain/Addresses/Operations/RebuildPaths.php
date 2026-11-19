<?php

/**
 * Brings one batch of items', terms', accounts' or people's addresses in step with the
 * settings, for RecomputeAddresses. Idempotent: a target already at its
 * address is left alone, and a moved one keeps its old address as history.
 * It tells the page cache of each target it moves. With `under`, it moves
 * a step of the pages under a page, as the person who changed that page
 * (I-3363).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Addresses\Operations;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\Operations\Input\RebuildPathsInput;
use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageTags;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Tree\ContentTree;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Contents\Tree\SubtreeMoves;
use App\Domain\Contents\Tree\SubtreeOutcome;
use App\Domain\Contents\Tree\TreeLock;
use App\Domain\People\Models\Person;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Taxonomy\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class RebuildPaths extends BaseOperation
{
    /** The largest batch, as RecomputeAddresses sends it. */
    public const int MOST = 500;

    /** @var list<string> The abilities whose changes move addresses. */
    private const array MAY_ASK = ['settings.addresses.manage', 'terms.manage', 'users.manage', 'settings.general.manage'];

    protected function authorize(OperationInput $input): void
    {
        /** @var RebuildPathsInput $input */
        if ($input->actorId === null) {
            if (Operator::isPresent()) {
                return;
            }

            throw new AuthorizationException('Not permitted to recompute addresses.');
        }

        $actor = User::query()->find($input->actorId);

        if ($input->under !== null) {
            // Whoever may change the page moves what is under it; so does the importer, whose moves are queued as them.
            $root = Content::withTrashed()->find($input->under);

            if ($actor !== null && ($actor->can('imports.run') || ($root !== null && $actor->can('update', $root)))) {
                return;
            }

            throw new AuthorizationException('Not permitted to move the pages under this page.');
        }

        if ($actor === null || ! collect(self::MAY_ASK)->contains(fn (string $ability): bool => $actor->can($ability))) {
            throw new AuthorizationException('Not permitted to recompute addresses.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RebuildPathsInput $input */
        // The kinds of Paths::KINDS it rebuilds, each by its own table below.
        Validator::make(
            ['kind' => $input->kind, 'ids' => $input->ids],
            ['kind' => ['required', Rule::in(['content', 'term', 'user', 'person'])], 'ids' => ['array', 'max:'.self::MOST], 'ids.*' => ['string']],
        )->validate();

        if ($input->under === null) {
            return;
        }

        $under = array_column(app(ContentTree::class)->descendants($input->under), 'id');

        if ($input->kind !== 'content' || array_diff(array_map(strval(...), $input->ids), $under) !== []) {
            throw ValidationException::withMessages(['ids' => __('Only the pages under the page can move with it.')]);
        }
    }

    protected function perform(OperationInput $input): int
    {
        /** @var RebuildPathsInput $input */
        $paths = app(PathProjection::class);
        $ids = array_map(strval(...), $input->ids);
        $root = $input->under === null ? null : Content::withTrashed()->find($input->under);

        // The tree first, then the rows, as every move of a branch takes them (I-3363).
        if ($root !== null) {
            TreeLock::take((string) $root->type);
        }

        $targets = match ($input->kind) {
            'content' => Content::withTrashed()->whereIn('id', $input->ids)->orderBy('id')->lockForUpdate()->get(),
            'term' => Term::query()->whereIn('id', $input->ids)->orderBy('id')->get(),
            // A person with no account, never an account read from a like ID (I-3366).
            'person' => Person::query()->whereIn('id', $input->ids)->whereNull('user_id')->orderBy('id')->get(),
            default => User::query()->whereIn('id', array_map('intval', $input->ids))->orderBy('id')->get(),
        };

        // Where each was, read after the lock, while the page cache is on (I-263).
        $watching = PageCache::enabled();
        $before = $watching ? self::currentPaths($input->kind, $ids) : [];

        foreach ($targets as $target) {
            if ($target instanceof Content && $root !== null) {
                $this->moveUnder($target, $paths);

                continue;
            }

            match (true) {
                $target instanceof Content => $paths->syncContent($target),
                // Its descendants are in the batches too.
                $target instanceof Term => $paths->syncTerm($target, withDescendants: false),
                $target instanceof Person => $paths->syncPerson((string) $target->id),
                default => $paths->syncUser($target),
            };
        }

        if ($watching) {
            $this->tellMoved($input->kind, $before, self::currentPaths($input->kind, $ids), $targets->all());
        }

        return $targets->count();
    }

    /**
     * Moves one page under the moving page to the address its parents give
     * it now; one whose new address another target holds keeps its old
     * one, and the request's SubtreeMoves names it.
     */
    private function moveUnder(Content $page, PathProjection $paths): void
    {
        // Later (issue 364): announce the address change here.
        if ($paths->syncContent($page)) {
            return;
        }

        $subtree = app(SubtreeAddresses::class);
        app(SubtreeMoves::class)->add(new SubtreeOutcome(kept: [$subtree->keptLine($page, (string) Addresses::forContent($page))]));
    }

    /**
     * Tells the page cache of each target whose address moved: the pages
     * that show it or list it, its old address and its new (I-263).
     *
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     * @param  array<int, Content|Term|User|Person>  $targets
     */
    private function tellMoved(string $kind, array $before, array $after, array $targets): void
    {
        $moved = [];
        $contents = [];

        foreach ($targets as $target) {
            $id = (string) $target->id;

            if (($before[$id] ?? null) !== ($after[$id] ?? null)) {
                $moved[] = $id;

                if ($target instanceof Content) {
                    $contents[] = $target;
                }
            }
        }

        if ($moved === []) {
            return;
        }

        [$subject, $footprints] = match ($kind) {
            'content' => [CacheKeysFor::CONTENT, Footprints::contents($contents)],
            'term' => [CacheKeysFor::TERM, Footprints::terms($moved)],
            default => [CacheKeysFor::PERSON, Footprints::people($moved)],
        };

        foreach ($moved as $id) {
            $keys = $footprints[$id] ?? [];

            if (isset($before[$id])) {
                $keys[] = PageTags::url($before[$id]);
            }

            $this->purgeAfterCommit(new CacheKeysFor($subject, $id, $keys));
        }
    }

    /**
     * Each target's current address, keyed by ID.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, string>
     */
    private static function currentPaths(string $kind, array $ids): array
    {
        $current = [];

        foreach (DB::table('paths')->where('target_type', $kind)->whereIn('target_id', $ids)->where('is_current', true)->get(['target_id', 'path']) as $row) {
            $current[(string) $row->target_id] = (string) $row->path;
        }

        return $current;
    }
}
