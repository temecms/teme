<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Terms;

use App\Domain\Contents\Slugs;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportResult;
use App\Domain\Import\Phases\ImportStopped;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Phases\PreparedObject;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\ImportTerm;
use App\Domain\Taxonomy\Operations\Input\ImportTermInput;
use App\Domain\Taxonomy\TermSlugs;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;

/**
 * The terms phase (I-397): each category and tag of the old site becomes
 * Teme's, at its own address, under its parent where that is already here;
 * post formats and other taxonomies' terms stay behind, counted.
 */
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

        $termId = $this->map->temeId($context->sourceId, $ref);

        if ($termId !== null && ! Term::query()->whereKey($termId)->exists()) {
            return ImportResult::skipped(new ImportMessage('term.deleted-here', 'It was imported before and has since been deleted in Teme, so it wasn’t imported again.'));
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
            ->when($termId !== null, fn ($query) => $query->whereKeyNot($termId))
            ->value('id');

        if ($holder !== null) {
            // A Teme term's address is one both sites claim (I-1706); another of this source's takes the next one free.
            if (! $this->terms->madeBy($context->sourceId, (string) $holder)) {
                $singular = mb_strtolower($taxonomy->singular);

                throw ImportStopped::because('address-clash', "Teme already has a {$singular} whose address ends in {$slug}, and “{$name}” from WordPress needs it too, so the import stopped. Change one of the two addresses, then carry on the import.");
            }

            $slug = TermSlugs::unique($taxonomy->key, $slug, $termId);
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

        if (array_key_exists('language', $item)) {
            $facts['language'] = $item['language'];
            $facts['translations'] = is_array($item['translations'] ?? null) ? $item['translations'] : [];
        }

        return $facts;
    }
}
