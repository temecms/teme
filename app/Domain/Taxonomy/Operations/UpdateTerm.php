<?php

/**
 * Renames a category or tag, or changes its slug, parent or description. A
 * category can never be moved inside itself. Its assignments are untouched.
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
use App\Domain\Contents\Slugs;
use App\Domain\Hooks\Payloads;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\Concerns\ValidatesTerms;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\PluginTermGrant;
use App\Domain\Taxonomy\TermSlugs;
use App\Domain\Types\TypeOwners;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Taxonomy\TermUpdated;

final class UpdateTerm extends BaseOperation
{
    use ValidatesTerms;

    protected function authorize(OperationInput $input): void
    {
        /** @var SaveTermInput $input */
        if ($input->plugin !== null) {
            PluginTermGrant::require($input->plugin);

            return;
        }

        $this->authorizeTerms($input, $input->taxonomy);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SaveTermInput $input */
        $term = Term::query()->findOrFail($input->termId);

        if ($term->taxonomy !== $input->taxonomy) {
            throw ValidationException::withMessages(['taxonomy' => __('A term cannot move to another taxonomy.')]);
        }

        if ($input->plugin !== null) {
            $record = PluginTermGrant::require($input->plugin);

            if (TypeOwners::owner('taxonomies', $input->taxonomy) !== $record->name) {
                throw new AuthorizationException(__('This plugin may only change terms of the taxonomies it brought.'));
            }

            $this->refusePluginTranslation($term->language === null ? null : (string) $term->language);
        }

        $parentId = $input->keepParent ? ($term->parent_id === null ? null : (string) $term->parent_id) : $input->parentId;

        $this->validateTerm($input->taxonomy, $input->name, $input->slug, $parentId, $term->id);
        $this->refuseParentInOtherLanguage($input->taxonomy, $parentId, $term->language === null ? null : (string) $term->language);
    }

    protected function perform(OperationInput $input): Term
    {
        /** @var SaveTermInput $input */
        $term = Term::query()->lockForUpdate()->findOrFail($input->termId);
        // It and every term beneath it, whose addresses hold its slug (I-263).
        $shown = Footprints::termAndBeneath((string) $term->id);
        // 190, not 200: room for a numeric suffix inside the 200-character column.
        $base = rtrim(mb_substr($input->slug ?? Slugs::fromTitle($input->name), 0, 190), '-');

        $term->update([
            'name' => trim($input->name),
            'slug' => TermSlugs::unique($term->taxonomy, $base, $term->id, $term->language),
            'parent_id' => $input->keepParent ? $term->parent_id : $input->parentId,
            'description' => $input->keepDescription ? $term->description : $input->description,
        ]);

        // Its descendants' addresses hold its slug too.
        app(PathProjection::class)->syncTerm($term);

        $after = Footprints::terms(array_map(strval(...), array_keys($shown)));

        foreach ($shown as $id => $before) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::TERM, (string) $id, [...$before, ...($after[(string) $id] ?? [])]));
        }

        $this->notifyAfterCommit(TermUpdated::class, fn (): TermUpdated => new TermUpdated(Payloads::term($term), $input->actorId()));

        // So do the items filed under it, where an address takes {category} from its taxonomy.
        if (AddressSettings::current()->termsMoveItems((string) $term->taxonomy)) {
            $actor = $input->plugin !== null
                ? PluginTermGrant::jobActor(PluginTermGrant::require($input->plugin))
                : JobActor::of($input->actorId());
            RecomputeAddresses::dispatch($actor, (string) $term->id)->afterCommit();
        }

        return $term;
    }
}
