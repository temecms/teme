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
use App\Domain\Hooks\Payloads;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\Input\DeleteTermInput;
use App\Domain\Taxonomy\PluginTermGrant;
use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Types\TypeOwners;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Taxonomy\TermDeleted;

final class DeleteTerm extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var DeleteTermInput $input */
        if ($input->plugin !== null) {
            PluginTermGrant::require($input->plugin);

            return;
        }

        $actor = $input->actorId() === null ? null : User::query()->find($input->actorId());
        $taxonomy = (string) Term::query()->whereKey($input->termId)->value('taxonomy');
        $taxonomies = app(TaxonomyRegistry::class);
        $ability = $taxonomies->has($taxonomy) ? $taxonomies->get($taxonomy)->ability : 'terms.manage';

        if ($actor === null || ! $actor->can($ability)) {
            throw new AuthorizationException('Not permitted to manage these terms.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var DeleteTermInput $input */
        if ($input->plugin === null) {
            return;
        }

        $term = Term::query()->findOrFail($input->termId);

        if ($input->pluginName === null || TypeOwners::owner('taxonomies', (string) $term->taxonomy) !== $input->pluginName) {
            throw new AuthorizationException(__('This plugin may only change terms of the taxonomies it brought.'));
        }

        if ((int) $term->working_count > 0 || (int) $term->published_count > 0) {
            throw ValidationException::withMessages(['term' => __('This term is in use, so it can’t be removed.')]);
        }

        if (Term::query()->where('parent_id', $term->id)->exists()) {
            throw ValidationException::withMessages(['term' => __('This term has children, so it can’t be removed.')]);
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var DeleteTermInput $input */
        $term = Term::query()->lockForUpdate()->findOrFail($input->termId);

        if ($input->plugin !== null) {
            if ((int) $term->working_count > 0 || (int) $term->published_count > 0) {
                throw ValidationException::withMessages(['term' => __('This term is in use, so it can’t be removed.')]);
            }

            if (Term::query()->where('parent_id', $term->id)->exists()) {
                throw ValidationException::withMessages(['term' => __('This term has children, so it can’t be removed.')]);
            }

            $shown = Footprints::terms([(string) $term->id]);
            $deleted = Payloads::term($term);
            $term->delete();
            $this->notifyAfterCommit(TermDeleted::class, fn (): TermDeleted => new TermDeleted($deleted, $input->actorId()));
            app(PathProjection::class)->forget('term', (string) $term->id);
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::TERM, (string) $term->id, $shown[(string) $term->id] ?? []));

            return null;
        }

        // It and every term beneath it; its children move up, and their
        // addresses with them (I-263).
        $shown = Footprints::termAndBeneath((string) $term->id);

        $moved = Term::query()->where('parent_id', $term->id)->pluck('id')->all();
        Term::query()->where('parent_id', $term->id)->update(['parent_id' => $term->parent_id]);

        // Taken before the row goes, for the hook (see Payloads::term).
        $deleted = Payloads::term($term);

        // content_terms rows cascade. published_count belongs to this term,
        // so nothing else needs adjusting.
        $term->delete();
        $this->notifyAfterCommit(TermDeleted::class, fn (): TermDeleted => new TermDeleted($deleted, $input->actorId()));

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
