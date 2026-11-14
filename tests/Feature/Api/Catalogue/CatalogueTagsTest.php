<?php

declare(strict_types=1);

use App\Domain\Api\Catalogue\MediaQuery;
use App\Domain\Api\Catalogue\TermFilters;
use App\Domain\Api\Catalogue\TermsQuery;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
    $this->site = DeliverySite::build();
    Switches::apiOn();
});

it('tags a list of terms with its taxonomies\' lists, each term and its count', function (): void {
    app(RenderRecord::class)->start(0);

    $page = app(TermsQuery::class)->page(new TermFilters(taxonomy: 'genre', parent: null, slug: null, limit: 20, after: null));
    $tags = app(RenderRecord::class)->tags();

    expect($page['rows'])->toHaveCount(1)
        ->and($tags)->toContain(PageTags::termsOf('genre'))
        ->and($tags)->toContain(PageTags::term($this->site->genre->id))
        ->and($tags)->toContain(PageTags::filedUnder('genre', $this->site->genre->id))
        ->and($tags)->not->toContain(PageTags::termsOf('shelf'));
});

it('tags a list over every public taxonomy with each one\'s list', function (): void {
    app(RenderRecord::class)->start(0);

    app(TermsQuery::class)->page(new TermFilters(taxonomy: null, parent: null, slug: null, limit: 20, after: null));
    $tags = app(RenderRecord::class)->tags();

    expect($tags)->toContain(PageTags::termsOf('genre'), PageTags::termsOf('category'), PageTags::termsOf('tag'))
        ->and($tags)->not->toContain(PageTags::termsOf('shelf'));
});

it('tags one term with itself and its count', function (): void {
    app(RenderRecord::class)->start(0);

    $row = app(TermsQuery::class)->byId($this->site->genre->id);
    $tags = app(RenderRecord::class)->tags();

    expect($row)->not->toBeNull()
        ->and($tags)->toContain(PageTags::term($this->site->genre->id), PageTags::filedUnder('genre', $this->site->genre->id));
});

it('tags a file with itself and each item showing it', function (): void {
    app(RenderRecord::class)->start(0);

    $file = app(MediaQuery::class)->shown($this->site->shell->id);
    $tags = app(RenderRecord::class)->tags();

    expect($file)->not->toBeNull()
        ->and($tags)->toContain(PageTags::media($this->site->shell->id))
        ->and($tags)->toContain(PageTags::content($this->site->public->id));
});

it('tags a file it doesn\'t show with the file\'s own tag, so showing it later purges the 404', function (): void {
    app(RenderRecord::class)->start(0);

    $file = app(MediaQuery::class)->shown($this->site->draftOnly->id);

    expect($file)->toBeNull()
        ->and(app(RenderRecord::class)->tags())->toContain(PageTags::media($this->site->draftOnly->id));
});
