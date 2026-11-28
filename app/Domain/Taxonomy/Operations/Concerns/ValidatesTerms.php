<?php

/**
 * The checks CreateTerm and UpdateTerm share: who may manage the taxonomy's
 * terms, and what a valid name, slug and parent are. The taxonomy registry
 * decides which taxonomies exist and which of them nest (I-414).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy\Operations\Concerns;

use App\Domain\Contents\Slugs;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Taxonomy\Queries\TermTree;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

trait ValidatesTerms
{
    /**
     * Authorization runs before validation, so a taxonomy nobody registered
     * is judged by the default ability here and refused by validateTerm().
     */
    private function authorizeTerms(OperationInput $input, string $taxonomy): void
    {
        $actor = $input->actorId() === null ? null : User::query()->find($input->actorId());
        $taxonomies = app(TaxonomyRegistry::class);
        $ability = $taxonomies->has($taxonomy) ? $taxonomies->get($taxonomy)->ability : 'terms.manage';

        if ($actor === null || ! $actor->can($ability)) {
            throw new AuthorizationException('Not permitted to manage these terms.');
        }
    }

    private function validateTerm(string $taxonomy, string $name, ?string $slug, ?string $parentId, ?string $termId): void
    {
        $taxonomies = app(TaxonomyRegistry::class);

        Validator::make(
            ['taxonomy' => $taxonomy, 'name' => trim($name), 'slug' => $slug],
            [
                'taxonomy' => ['required', Rule::in($taxonomies->keys())],
                'name' => ['required', 'string', 'max:200'],
                'slug' => ['nullable', 'string', 'max:190', 'regex:'.Slugs::RULE],
            ],
            [
                'name.required' => __('Give it a name.'),
                'slug.regex' => __('Use only lowercase letters, numbers and single hyphens in the address.'),
            ],
        )->validate();

        if ($parentId === null) {
            return;
        }

        $declared = $taxonomies->get($taxonomy);

        if (! $declared->hierarchical) {
            throw ValidationException::withMessages(['parent' => __(':plural cannot have a parent.', ['plural' => $declared->plural])]);
        }

        if (DB::table('terms')->where('id', $parentId)->value('taxonomy') !== $taxonomy) {
            throw ValidationException::withMessages(['parent' => __('The parent must be another :singular.', ['singular' => mb_strtolower($declared->singular)])]);
        }

        if ($termId !== null && in_array($parentId, app(TermTree::class)->descendantIds($termId), true)) {
            throw ValidationException::withMessages(['parent' => __('A :singular cannot sit inside itself or one of its own sub:plural.', ['singular' => mb_strtolower($declared->singular), 'plural' => mb_strtolower($declared->plural)])]);
        }
    }

    /**
     * Refuses a parent in another language than the term's (I-3360): a
     * term's tree is in one language, as a page's is.
     *
     * @param  ?string  $language  the term's language as a row stores it, null for the default
     *
     * @throws ValidationException
     */
    private function refuseParentInOtherLanguage(string $taxonomy, ?string $parentId, ?string $language): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = DB::table('terms')->where('id', $parentId)->first(['language']);

        if ($parent === null || $parent->language === $language) {
            return;
        }

        $languages = app(SiteLanguages::class);

        throw ValidationException::withMessages(['parent' => __('The parent must be another :language :singular.', [
            'language' => $languages->label($languages->of($language)),
            'singular' => mb_strtolower(app(TaxonomyRegistry::class)->get($taxonomy)->singular),
        ])]);
    }

    /**
     * Refuses a plugin write of a term that is a translation (I-3360): the
     * terms door never makes or changes translations.
     *
     * @throws ValidationException
     */
    private function refusePluginTranslation(?string $language): void
    {
        if ($language !== null) {
            throw ValidationException::withMessages(['term' => __('A plugin can’t change a translation of a term.')]);
        }
    }
}
