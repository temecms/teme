<?php

/**
 * The terms phase (I-397): each category and tag of the old site becomes
 * Teme's, at its own address, under its parent where that is already here;
 * post formats and other taxonomies' terms stay behind, counted.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Terms;

use App\Domain\Contents\Slugs;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\AdoptImportedTerm;
use App\Domain\Import\Operations\Input\AdoptImportedTermInput;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportResult;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Phases\PreparedObject;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Source\FileReader;
use App\Domain\Import\Translations\ImportedLanguages;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\ImportTerm;
use App\Domain\Taxonomy\Operations\Input\ImportTermInput;
use App\Domain\Taxonomy\TermSlugs;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;

final readonly class TermsPhase implements ListPhase
{
    /** WordPress's taxonomy for post formats, which Teme doesn't carry (I-447). */
    public const string POST_FORMAT = 'post_format';

    public function __construct(private ImportMap $map, private ImportedTerms $terms, private TaxonomyRegistry $taxonomies) {}

    public function key(): string
    {
        return 'terms';
    }

    public function order(): int
    {
        return 300;
    }

    public function label(): string
    {
        return 'Categories and tags';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function lists(ImportContext $context): array
    {
        // A term's count changes with every post, and nothing imported depends on it.
        return [new ImportList('terms', [], 500, ['count'])];
    }

    public function identify(ImportList $list, array $item, int|string $key): SourceRef
    {
        return SourceRef::of('term', (int) $item['id']);
    }

    public function import(SourceRef $ref, array $item, ImportContext $context, ?PreparedObject $prepared): ImportResult
    {
        $wordpressTaxonomy = (string) ($item['taxonomy'] ?? '');
        $taxonomy = $this->taxonomyFor($wordpressTaxonomy);

        if ($taxonomy === null) {
            return ImportResult::skipped($wordpressTaxonomy === self::POST_FORMAT
                ? new ImportMessage('term.post-format', 'A post format, which Teme doesn’t carry over, so it wasn’t imported.')
                : new ImportMessage('term.other-taxonomy', "A term of {$wordpressTaxonomy}, a taxonomy Teme doesn’t have, so it wasn’t imported.", ['taxonomy' => $wordpressTaxonomy]));
        }

        // A term the site's export file brought under its term ID is this one (docs/architecture/import.md).
        if (! $context->readsFile()) {
            $this->adopt($ref, $item, $wordpressTaxonomy, $context);
        }

        $termId = $this->map->temeId($context->sourceId, $ref);

        if ($termId !== null && ! Term::query()->whereKey($termId)->exists()) {
            return ImportResult::skipped(new ImportMessage('term.deleted-here', 'It was imported before and has since been deleted in Teme, so it wasn’t imported again.'));
        }

        // A term Teme already had, which this import files its posts under, is never changed by it.
        if ($termId !== null && ($this->map->entry($context->sourceId, $ref)?->facts['shared'] ?? false) === true) {
            return ImportResult::updated('term', $termId)->withFacts(self::shared($this->facts($item, $wordpressTaxonomy, (int) ($item['parent'] ?? 0))));
        }

        // A new term is made in its language, its slug kept exactly, suffix and all (I-3446).
        $language = $termId === null
            ? app(ImportedLanguages::class)->stored($context->sourceId, $item['language'] ?? null, app(SiteLanguages::class)->translatesTaxonomy($taxonomy->key))
            : Term::query()->whereKey($termId)->value('language');

        if ($language === false) {
            return ImportResult::skipped(new ImportMessage('term.language-missing', __('Its language on the old site isn’t one of this site’s, so it wasn’t imported. The Languages step of the report says why.')));
        }

        $name = mb_substr(WordPressText::decoded($item['name'] ?? ''), 0, 200);
        $from = (string) ($item['slug'] ?? '');
        ['slug' => $slug, 'kept' => $kept] = WordPressSlugs::fromWordPress($from);

        if ($slug === '') {
            ['slug' => $slug] = WordPressSlugs::fromWordPress(Slugs::fromTitle($name));
        }

        $holder = Term::query()
            ->where('taxonomy', $taxonomy->key)
            ->where('slug', $slug)
            ->where(fn ($query) => $language === null ? $query->whereNull('language') : $query->where('language', $language))
            ->when($termId !== null, fn ($query) => $query->whereKeyNot($termId))
            ->value('id');

        if ($holder !== null) {
            // A term Teme already has at this address, in this taxonomy and language, is the old site's term too, as
            // WordPress's importer takes it: its posts are filed under it, and it stays as Teme has it.
            if ($termId === null && ! $this->terms->madeBy($context->sourceId, (string) $holder)) {
                $singular = mb_strtolower($taxonomy->singular);

                return ImportResult::updated('term', (string) $holder)
                    ->withWarning(new ImportMessage('term.shared', "Teme already has a {$singular} at this address, ending in {$slug}, so the posts in it are filed under that one, left as it is.", ['slug' => $slug]))
                    ->withFacts(self::shared($this->facts($item, $wordpressTaxonomy, (int) ($item['parent'] ?? 0))));
            }

            $slug = TermSlugs::unique($taxonomy->key, $slug, $termId, $language);
            $kept = false;
        }

        $parent = (int) ($item['parent'] ?? 0);
        $parentId = $parent > 0 && $taxonomy->hierarchical ? $this->terms->byWordPressTerm($context->sourceId, $wordpressTaxonomy, $parent) : null;

        // A parent WordPress lists later is the parents step's to place; until then a term keeps the one it has.
        if ($parentId === null && $parent > 0 && $termId !== null) {
            $current = Term::query()->whereKey($termId)->value('parent_id');
            $parentId = $current === null ? null : (string) $current;
        }

        $saved = app(ImportTerm::class)->execute(new ImportTermInput(
            taxonomy: $taxonomy->key,
            name: $name,
            slug: $slug,
            description: WordPressText::plain($item['description'] ?? null),
            parentId: $parentId,
            termId: $termId,
            actorId: $context->actorId(),
            language: $language,
        ));

        $result = $saved['created'] ? ImportResult::created('term', $saved['termId']) : ImportResult::updated('term', $saved['termId']);

        if (! $kept && $from !== '') {
            $was = rawurldecode($from);
            $result = $result->withWarning(new ImportMessage('term.slug-changed', "Its address on WordPress ended in {$was}, which Teme can’t give it here, so it ends in {$slug}.", ['from' => $was, 'to' => $slug]));
        }

        if (is_array($item['seo'] ?? null) && array_filter($item['seo'], fn (mixed $value): bool => $value !== null) !== []) {
            $result = $result->withWarning(new ImportMessage('term.seo-not-kept', 'Its own SEO settings from WordPress aren’t kept: Teme has none for categories and tags yet.'));
        }

        return $result->withFacts($this->facts($item, $wordpressTaxonomy, $parent));
    }

    /**
     * Moves the map row an import from the site's export file made of this
     * term, found by its taxonomy and its term ID or slug, to the companion's ID for it.
     *
     * @param  array<string, mixed>  $item
     */
    private function adopt(SourceRef $ref, array $item, string $wordpressTaxonomy, ImportContext $context): void
    {
        $termId = (int) ($item['term_id'] ?? 0);
        $slug = (string) ($item['slug'] ?? '');
        $held = $this->map->entry($context->sourceId, $ref);

        if ($held !== null) {
            $facts = $held->facts;

            // A file's term kept its own number, which is this term's ID: it moves apart, as the next file's read finds it.
            if (! is_numeric($facts['file_term_id'] ?? null) || (($facts['taxonomy'] ?? null) === $wordpressTaxonomy && (int) ($facts['term_id'] ?? 0) === $termId)) {
                return;
            }

            $apart = (string) FileReader::apart((int) $facts['file_term_id']);
            app(AdoptImportedTerm::class)->execute(new AdoptImportedTermInput($context->sourceId, $ref->id, $apart, $context->actorId()));
        }

        $match = array_filter([
            $termId > 0 ? ['taxonomy' => $wordpressTaxonomy, 'term_id' => $termId] : null,
            $slug !== '' ? ['taxonomy' => $wordpressTaxonomy, 'slug' => $slug] : null,
        ]);

        foreach ($match as $facts) {
            $from = $this->terms->fromFile($context->sourceId, $facts);

            if ($from !== null && $from !== $ref->id) {
                app(AdoptImportedTerm::class)->execute(new AdoptImportedTermInput($context->sourceId, $from, $ref->id, $context->actorId()));

                return;
            }
        }
    }

    /**
     * The taxonomy whose WordPress name this is, or null when Teme has none.
     */
    private function taxonomyFor(string $wordpress): ?Taxonomy
    {
        foreach ($this->taxonomies->all() as $taxonomy) {
            if ($wordpress !== '' && $taxonomy->wordpress === $wordpress) {
                return $taxonomy;
            }
        }

        return null;
    }

    /**
     * A term's facts when it is one Teme already had: marked shared, with no
     * parent for the parents step to give it, since the import never changes it.
     *
     * @param  array<string, mixed>  $facts
     *
     * @return array<string, mixed>
     */
    private static function shared(array $facts): array
    {
        return [...$facts, 'parent' => 0, 'shared' => true];
    }

    /**
     * What the map keeps of a term: WordPress's IDs and its address's path,
     * and a translation's language and group (I-351).
     *
     * @param  array<string, mixed>  $item
     *
     * @return array<string, mixed>
     */
    private function facts(array $item, string $wordpressTaxonomy, int $parent): array
    {
        $path = parse_url((string) ($item['link'] ?? ''), PHP_URL_PATH);
        $facts = [
            'term_id' => (int) ($item['term_id'] ?? 0),
            'taxonomy' => $wordpressTaxonomy,
            'parent' => $parent,
            'path' => is_string($path) && $path !== '' ? $path : null,
        ];

        // Its number and slug in an export file, which the next file's read and a later live run look for.
        if (is_numeric($item['file_term_id'] ?? null)) {
            $facts['file_term_id'] = (int) $item['file_term_id'];
            $facts['slug'] = (string) ($item['slug'] ?? '');
        }

        if (array_key_exists('language', $item)) {
            $facts['language'] = $item['language'];
            $facts['translations'] = is_array($item['translations'] ?? null) ? $item['translations'] : [];
        }

        return $facts;
    }
}
