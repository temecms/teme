<?php

/**
 * Deletes a category or tag. A category's children move up to its parent.
 * Its assignments disappear from the projection; revisions keep the ID and
 * the projection skips it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy\Operations;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\Input\DeleteTermInput;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class DeleteTerm extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var DeleteTermInput $input */
        $actor = $input->actorId() === null ? null : User::query()->find($input->actorId());
        $taxonomy = (string) Term::query()->whereKey($input->termId)->value('taxonomy');
        $taxonomies = app(TaxonomyRegistry::class);
        $ability = $taxonomies->has($taxonomy) ? $taxonomies->get($taxonomy)->ability : 'terms.manage';

        if ($actor === null || ! $actor->can($ability)) {
            throw new AuthorizationException('Not permitted to manage these terms.');
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var DeleteTermInput $input */
        $term = Term::query()->lockForUpdate()->findOrFail($input->termId);
        // It and every term beneath it; its children move up, and their
        // addresses with them (I-263).
        $shown = Footprints::termAndBeneath((string) $term->id);

        $moved = Term::query()->where('parent_id', $term->id)->pluck('id')->all();
        Term::query()->where('parent_id', $term->id)->update(['parent_id' => $term->parent_id]);

        // content_terms rows cascade. published_count belongs to this term,
        // so nothing else needs adjusting.
        $term->delete();

        // Its children moved up to its parent, so their addresses, and their
        // descendants', change.
        $paths = app(PathProjection::class);
        $paths->forget('term', (string) $term->id);

        foreach (Term::query()->whereIn('id', $moved)->get() as $child) {
            $paths->syncTerm($child);
        }

        $after = Footprints::terms(array_values(array_diff(array_map(strval(...), array_keys($shown)), [(string) $term->id])));

        foreach ($shown as $id => $before) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::TERM, (string) $id, [...$before, ...($after[(string) $id] ?? [])]));
        }

        // Its items can no longer be named (their rows cascaded), so where an
        // address takes {category} from its taxonomy, every item is recomputed.
        if (AddressSettings::current()->termsMoveItems((string) $term->taxonomy)) {
            RecomputeAddresses::dispatch(JobActor::of($input->actorId()))->afterCommit();
        }

        return null;
    }
}
