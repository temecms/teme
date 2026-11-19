<?php

/**
 * The toasts the translation routes answer with (I-3360), read from the rows
 * after each Operation, in *Every word*'s words.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\TranslatedContent;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Types\TaxonomyRegistry;

final readonly class TranslationMessages
{
    public function __construct(private SiteLanguages $languages, private TranslationStatus $status) {}

    /**
     * After Translate: the draft made, then a sentence for each taxonomy
     * whose terms it made ("Made French versions of 2 categories: Sport,
     * Local. Rename them in Categories."), then why a page is at the top.
     */
    public function translated(TranslatedContent $made, string $sourceId): string
    {
        $source = Content::withTrashed()->find($sourceId, ['id', 'language']);

        return implode(' ', array_filter([
            __('Made a :language draft from the :source version.', ['language' => (string) $made->languageLabel, 'source' => $this->label($source?->language)]),
            ...$this->madeTerms($made),
            $made->parentLeftMessage(),
        ]));
    }

    /**
     * After Link: the item linked, by its title and language.
     */
    public function linked(string $contentId): string
    {
        $other = Content::query()->find($contentId, ['id', 'title', 'language']);

        return __('Linked “:title” as the :language version.', ['title' => (string) $other?->title, 'language' => $this->label($other?->language)]);
    }

    /**
     * The title of $otherId when it is a version in $contentId's group,
     * so an unlink can't name any item at all; null otherwise.
     */
    public function versionTitle(string $contentId, string $otherId): ?string
    {
        $group = Content::query()->whereKey($contentId)->value('translation_group');
        $other = Content::query()->find($otherId, ['id', 'title', 'translation_group']);

        return $group !== null && $other !== null && $other->translation_group === $group ? (string) $other->title : null;
    }

    /**
     * After Unlink: the version unlinked, by its title.
     */
    public function unlinked(string $title): string
    {
        return __('Unlinked “:title”.', ['title' => $title]);
    }

    /**
     * After Mark as up to date: the language of the version it is now up to date with.
     */
    public function upToDate(Content $marked): string
    {
        $version = collect($this->status->forItem($marked))->firstWhere('id', (string) $marked->id);

        return __('Marked as up to date with the :language version.', ['language' => $this->languages->label((string) ($version['sourceLanguage'] ?? $this->languages->defaultTag()))]);
    }

    /**
     * @return list<string>
     */
    private function madeTerms(TranslatedContent $made): array
    {
        if ($made->madeTerms === []) {
            return [];
        }

        $taxonomies = app(TaxonomyRegistry::class);
        $sentences = [];

        foreach (Term::query()->whereIn('id', $made->madeTerms)->get(['id', 'name', 'taxonomy'])->groupBy('taxonomy') as $key => $terms) {
            $taxonomy = $taxonomies->get((string) $key);
            $count = $terms->count();

            $sentences[] = __('Made :language versions of :count: :names. Rename them in :plural.', [
                'language' => (string) $made->languageLabel,
                'count' => $count.' '.mb_strtolower($count === 1 ? $taxonomy->singular : $taxonomy->plural),
                'names' => $terms->sortBy(fn (Term $term): int => (int) array_search($term->id, $made->madeTerms, true))->pluck('name')->implode(', '),
                'plural' => $taxonomy->plural,
            ]);
        }

        return $sentences;
    }

    private function label(?string $stored): string
    {
        return $this->languages->label($this->languages->of($stored));
    }
}
