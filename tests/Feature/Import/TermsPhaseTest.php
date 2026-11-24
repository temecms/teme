<?php

declare(strict_types=1);

use App\Domain\Addresses\Models\Path;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\Terms\TermParentsStep;
use App\Domain\Import\Phases\Terms\TermsPhase;
use App\Domain\Taxonomy\Models\Term;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Import\FakeCompanion;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->companion = importFake();
    $this->source = importSource();
});

/**
 * One category as the companion sends it, its term_id 100 above its
 * term_taxonomy_id, but for $fields.
 *
 * @param  array<string, mixed>  $fields
 *
 * @return array<string, mixed>
 */
function tpsItem(int $id, array $fields = []): array
{
    return [
        'id' => $id, 'term_id' => $id + 100, 'taxonomy' => 'category', 'name' => "Term {$id}", 'slug' => "term-{$id}",
        'description' => '', 'parent' => 0, 'count' => 0, 'link' => "https://old.example/category/term-{$id}/", 'seo' => null,
        ...$fields,
    ];
}

/**
 * The terms phase and its parents step alone, run as the operator to their
 * end over the fixture's terms, or these.
 *
 * @param  list<array<string, mixed>>|null  $terms
 */
function tpsRun(FakeCompanion $companion, ImportSource $source, ?array $terms = null): ImportRun
{
    if ($terms !== null) {
        $companion->items('terms', $terms);
    }

    importPhaseAlone(app(TermsPhase::class), app(TermParentsStep::class));

    return importRun($source);
}

function tpsRow(ImportSource $source, int $id): ImportMapEntry
{
    return ImportMapEntry::query()->where('source_id', $source->id)->where('object_type', 'term')->where('object_id', (string) $id)->firstOrFail();
}

function tpsTerm(ImportSource $source, int $id): Term
{
    return Term::query()->findOrFail(tpsRow($source, $id)->teme_id);
}

/**
 * @return list<string>
 */
function tpsCodes(ImportSource $source, int $id): array
{
    return ImportMessageRecord::query()->where('map_id', tpsRow($source, $id)->id)->orderBy('code')->pluck('code')->all();
}

function tpsWords(ImportSource $source, int $id, string $code): string
{
    return (string) ImportMessageRecord::query()->where('map_id', tpsRow($source, $id)->id)->where('code', $code)->value('message');
}

function tpsPath(string $termId): mixed
{
    return Path::query()->where('target_type', 'term')->where('target_id', $termId)->where('is_current', true)->value('path');
}

/**
 * @return list<string>
 */
function tpsHistory(string $termId): array
{
    return Path::query()->where('target_type', 'term')->where('target_id', $termId)->where('is_current', false)->orderBy('path')->pluck('path')->all();
}

it('imports categories and tags with their names, descriptions and decoded addresses', function (): void {
    $run = tpsRun($this->companion, $this->source);
    $animals = tpsTerm($this->source, 2);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and([$run->created_count, $run->skipped_count, $run->failed_count])->toBe([8, 2, 1])
        ->and([$animals->taxonomy, $animals->name, $animals->slug, $animals->description])->toBe(['category', 'Animals', 'animals', 'All about animals & more.'])
        ->and(tpsPath($animals->id))->toBe('category/animals')
        ->and(tpsTerm($this->source, 4)->only(['taxonomy', 'name', 'slug']))->toBe(['taxonomy' => 'tag', 'name' => 'Night & Day', 'slug' => 'night-day'])
        ->and(tpsTerm($this->source, 5)->slug)->toBe('café')
        ->and(tpsPath(tpsTerm($this->source, 5)->id))->toBe('tag/café')
        ->and(tpsTerm($this->source, 11)->name)->toBe('Fish &amp; Chips')
        ->and(tpsRow($this->source, 2)->teme_type)->toBe('term')
        ->and(tpsCodes($this->source, 5))->toBe([]);
});

it('places a category under its parent, even when WordPress lists the child first', function (): void {
    tpsRun($this->companion, $this->source);
    $owls = tpsTerm($this->source, 3);
    $birds = tpsTerm($this->source, 7);

    expect($owls->parent_id)->toBe(tpsTerm($this->source, 2)->id)
        ->and(tpsPath($owls->id))->toBe('category/animals/owls')
        ->and(tpsHistory($owls->id))->toBe([])
        ->and($birds->parent_id)->toBe(tpsTerm($this->source, 9)->id)
        ->and(tpsPath($birds->id))->toBe('category/nature/birds')
        ->and(tpsHistory($birds->id))->toBe(['category/birds']);
});

it('leaves post formats and other taxonomies behind, saying which', function (): void {
    tpsRun($this->companion, $this->source);

    expect(tpsRow($this->source, 6)->outcome)->toBe(ImportOutcome::Skipped)
        ->and(tpsCodes($this->source, 6))->toBe(['term.post-format'])
        ->and(tpsWords($this->source, 8, 'term.other-taxonomy'))->toBe('A term of genre, a taxonomy Teme doesn’t have, so it wasn’t imported.')
        ->and(Term::query()->where('name', 'Jazz')->exists())->toBeFalse();
});

it('keeps WordPress\'s term ID, taxonomy, parent and address in the map, and a translation\'s language', function (): void {
    tpsRun($this->companion, $this->source, [
        tpsItem(2, ['name' => 'Animals', 'slug' => 'animals']),
        tpsItem(3, ['name' => 'Owls', 'slug' => 'owls', 'parent' => 102, 'link' => 'https://old.example/category/animals/owls/']),
        tpsItem(4, ['name' => 'Hiboux', 'slug' => 'hiboux', 'language' => 'fr', 'translations' => ['en' => 103, 'fr' => 104]]),
    ]);
    $owls = tpsRow($this->source, 3)->facts;
    ksort($owls);
    $hiboux = tpsRow($this->source, 4)->facts;
    $translations = (array) $hiboux['translations'];
    ksort($translations);

    expect($owls)->toBe(['parent' => 102, 'path' => '/category/animals/owls/', 'taxonomy' => 'category', 'term_id' => 103])
        ->and($hiboux['language'])->toBe('fr')
        ->and($translations)->toBe(['en' => 103, 'fr' => 104]);
});

it('files a WordPress term under the one Teme already has at its address, leaving that one as it is, run after run (#704)', function (): void {
    $ours = taxonomyTerm('category', 'Animals');
    Term::query()->whereKey($ours->id)->update(['description' => 'Ours, from another site.']);
    $terms = [
        tpsItem(2, ['name' => 'Animals', 'slug' => 'animals', 'description' => 'Theirs.']),
        tpsItem(3, ['name' => 'Owls', 'slug' => 'owls', 'parent' => 102, 'link' => 'https://old.example/category/animals/owls/']),
    ];

    $run = tpsRun($this->companion, $this->source, $terms);
    $facts = tpsRow($this->source, 2)->facts;

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(tpsRow($this->source, 2)->teme_id)->toBe($ours->id)
        ->and(Term::query()->where('taxonomy', 'category')->where('slug', 'animals')->count())->toBe(1)
        ->and(Term::query()->whereKey($ours->id)->value('description'))->toBe('Ours, from another site.')
        ->and(tpsCodes($this->source, 2))->toContain('term.shared')
        ->and(tpsWords($this->source, 2, 'term.shared'))->toBe('Teme already has a category at this address, ending in animals, so the posts in it are filed under that one, left as it is.')
        ->and([$facts['shared'] ?? null, $facts['parent'] ?? null, $facts['term_id'] ?? null])->toBe([true, 0, 102])
        ->and(tpsTerm($this->source, 3)->parent_id)->toBe($ours->id);

    $again = tpsRun($this->companion, $this->source, $terms);

    expect($again->state)->toBe(ImportRunState::Finished)
        ->and(tpsRow($this->source, 2)->teme_id)->toBe($ours->id)
        ->and(Term::query()->whereKey($ours->id)->value('description'))->toBe('Ours, from another site.')
        ->and(Term::query()->where('taxonomy', 'category')->count())->toBe(2);
});

it('gives two WordPress terms that need one address the next free one, saying so', function (): void {
    tpsRun($this->companion, $this->source, [
        tpsItem(1, ['name' => 'News', 'slug' => 'news']),
        tpsItem(2, ['name' => 'News!', 'slug' => 'news_']),
    ]);

    expect(tpsTerm($this->source, 2)->slug)->toBe('news-2')
        ->and(tpsWords($this->source, 2, 'term.slug-changed'))->toBe('Its address on WordPress ended in news_, which Teme can’t give it here, so it ends in news-2.')
        ->and(tpsCodes($this->source, 1))->toBe([]);
});

it('says when a term\'s own SEO settings from WordPress are not kept', function (): void {
    tpsRun($this->companion, $this->source);

    expect(tpsCodes($this->source, 3))->toBe(['term.seo-not-kept'])
        ->and(tpsWords($this->source, 3, 'term.seo-not-kept'))->toBe('Its own SEO settings from WordPress aren’t kept: Teme has none for categories and tags yet.')
        ->and(tpsCodes($this->source, 2))->toBe([]);
});

it('follows a term WordPress renamed on a later run, keeping its old address and moving its children\'s', function (): void {
    tpsRun($this->companion, $this->source, [
        tpsItem(2, ['name' => 'Animals', 'slug' => 'animals']),
        tpsItem(3, ['name' => 'Owls', 'slug' => 'owls', 'parent' => 102]),
    ]);

    $run = tpsRun($this->companion, $this->source, [
        tpsItem(2, ['name' => 'Wildlife', 'slug' => 'wildlife', 'description' => 'Everything that lives.']),
        tpsItem(3, ['name' => 'Owls', 'slug' => 'owls', 'parent' => 102]),
    ]);
    $wildlife = tpsTerm($this->source, 2);

    expect($run->updated_count)->toBe(1)
        ->and([$wildlife->name, $wildlife->slug, $wildlife->description])->toBe(['Wildlife', 'wildlife', 'Everything that lives.'])
        ->and(tpsPath($wildlife->id))->toBe('category/wildlife')
        ->and(tpsHistory($wildlife->id))->toBe(['category/animals'])
        ->and(tpsPath(tpsTerm($this->source, 3)->id))->toBe('category/wildlife/owls');
});

it('reports a category whose parent was never imported, leaving it at the top', function (): void {
    $run = tpsRun($this->companion, $this->source, [tpsItem(3, ['name' => 'Owls', 'slug' => 'owls', 'parent' => 199])]);
    $said = ImportMessageRecord::query()->where('run_id', $run->id)->whereNull('map_id')->pluck('message', 'code')->all();

    expect(tpsTerm($this->source, 3)->parent_id)->toBeNull()
        ->and($said)->toBe(['term.parent-missing' => '“Owls” sits under a category that wasn’t imported, so it’s at the top level here.']);
});

it('leaves a term deleted in Teme since deleted', function (): void {
    tpsRun($this->companion, $this->source, [tpsItem(2, ['name' => 'Animals', 'slug' => 'animals'])]);
    $id = (string) tpsRow($this->source, 2)->teme_id;
    Path::query()->where('target_type', 'term')->where('target_id', $id)->delete();
    Term::query()->whereKey($id)->delete();

    tpsRun($this->companion, $this->source, [tpsItem(2, ['name' => 'Wildlife', 'slug' => 'wildlife'])]);

    expect(Term::query()->count())->toBe(0)
        ->and(tpsRow($this->source, 2)->outcome)->toBe(ImportOutcome::Skipped)
        ->and(tpsCodes($this->source, 2))->toBe(['term.deleted-here']);
});

it('registers the terms phase at 300 and its parents step at 310, for every kind of run', function (): void {
    $phases = app(ImportPhases::class);

    expect($phases->find('terms'))->toBeInstanceOf(TermsPhase::class)
        ->and($phases->find('terms')?->order())->toBe(300)
        ->and($phases->find('term-parents'))->toBeInstanceOf(TermParentsStep::class)
        ->and($phases->find('term-parents')?->order())->toBe(310)
        ->and($phases->find('term-parents')?->runsIn(ImportRunKind::CatchUp))->toBeTrue();
});
