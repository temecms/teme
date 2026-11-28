<?php

/**
 * A plugin's terms door: creates, updates, lists and removes terms of the
 * taxonomies the plugin owns, through the term Operations, as the site.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\DeleteTerm;
use App\Domain\Taxonomy\Operations\Input\DeleteTermInput;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\Operations\UpdateTerm;
use App\Domain\Taxonomy\Queries\PluginTerms;
use App\Domain\Types\TypeOwners;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Taxonomy\TermRef;
use Teme\Contracts\Taxonomy\TermWriter;

/**
 * A plugin's terms door, offered when it was granted terms.write. Every
 * write is of a taxonomy it owns; core's, the site's and another plugin's
 * stay out of reach.
 */
final readonly class PluginTermWriter implements TermWriter
{
    public function __construct(private string $name, private string $code) {}

    public function create(string $taxonomy, string $name, ?string $slug = null, ?string $parentId = null, ?string $description = null): TermRef
    {
        $this->ownTaxonomy($taxonomy);

        try {
            $term = app(CreateTerm::class)->execute(new SaveTermInput(
                taxonomy: $taxonomy,
                name: $name,
                slug: $slug,
                parentId: $parentId,
                description: $description,
                actorId: null,
                plugin: $this->code,
                pluginName: $this->name,
            ));
        } catch (AuthorizationException $refused) {
            throw new WriteRefused($refused->getMessage() !== '' ? $refused->getMessage() : __('Not permitted.'), previous: $refused);
        } catch (ValidationException $refused) {
            $first = collect($refused->errors())->flatten()->first();

            throw new WriteRefused(is_string($first) && $first !== '' ? $first : __('The term was refused.'), previous: $refused);
        }

        return app(PluginTerms::class)->find((string) $term->id) ?? throw new WriteRefused(__('The term was refused.'));
    }

    public function update(string $termId, string $name, ?string $slug = null, ?string $parentId = null, ?string $description = null): TermRef
    {
        $term = app(PluginTerms::class)->find($termId) ?? throw new WriteRefused(__('That term was not found.'));
        $this->ownTaxonomy($term->taxonomy);

        try {
            $saved = app(UpdateTerm::class)->execute(new SaveTermInput(
                taxonomy: $term->taxonomy,
                name: $name,
                slug: $slug,
                parentId: $parentId,
                description: $description,
                actorId: null,
                termId: $termId,
                plugin: $this->code,
                pluginName: $this->name,
            ));
        } catch (AuthorizationException $refused) {
            throw new WriteRefused($refused->getMessage() !== '' ? $refused->getMessage() : __('Not permitted.'), previous: $refused);
        } catch (ValidationException $refused) {
            $first = collect($refused->errors())->flatten()->first();

            throw new WriteRefused(is_string($first) && $first !== '' ? $first : __('The term was refused.'), previous: $refused);
        }

        return app(PluginTerms::class)->find((string) $saved->id) ?? throw new WriteRefused(__('The term was refused.'));
    }

    public function remove(string $termId): void
    {
        $term = app(PluginTerms::class)->find($termId) ?? throw new WriteRefused(__('That term was not found.'));
        $this->ownTaxonomy($term->taxonomy);

        try {
            app(DeleteTerm::class)->execute(new DeleteTermInput($termId, null, plugin: $this->code, pluginName: $this->name));
        } catch (AuthorizationException $refused) {
            throw new WriteRefused($refused->getMessage() !== '' ? $refused->getMessage() : __('Not permitted.'), previous: $refused);
        } catch (ValidationException $refused) {
            $first = collect($refused->errors())->flatten()->first();

            throw new WriteRefused(is_string($first) && $first !== '' ? $first : __('The term was refused.'), previous: $refused);
        }
    }

    public function list(string $taxonomy): array
    {
        $this->ownTaxonomy($taxonomy);

        return app(PluginTerms::class)->of($taxonomy);
    }

    /**
     * @throws WriteRefused
     */
    private function ownTaxonomy(string $taxonomy): void
    {
        if (TypeOwners::owner('taxonomies', $taxonomy) !== $this->name) {
            throw new WriteRefused(__('This plugin may only change terms of the taxonomies it brought.'));
        }
    }
}
