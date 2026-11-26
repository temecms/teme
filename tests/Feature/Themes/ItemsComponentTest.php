<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    ltTypes();
    $this->author = User::factory()->create()->assignRole('author');
    ltItem($this->author, 'film', 'Arrival');
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Dune');
    ltItem($this->author, 'recipe', 'Fish stew');
    lifecycleDraft($this->author, 'Not yet', 'film');
});

it('lists a type\'s published items from any template, newest first', function (): void {
    $html = Blade::render('<x-teme::items type="film" />');

    expect($html)->toContain('Dune')->toContain('Arrival')
        ->and(strpos($html, 'Dune'))->toBeLessThan(strpos($html, 'Arrival'))
        ->and($html)->not->toContain('Not yet')->not->toContain('Fish stew')
        ->and($html)->toContain('href="/films/dune/"');
});

it('stops at the limit', function (): void {
    expect(Blade::render('<x-teme::items type="film" :limit="1" />'))->toContain('Dune')->not->toContain('Arrival');
});

it('draws nothing for a type that does not exist', function (): void {
    expect(trim(Blade::render('<x-teme::items type="nothing" />')))->toBe('');
});

it('hands the items to the theme\'s own view when it names one', function (): void {
    expect(Blade::render('<x-teme::items type="film" partial="partials.film-count" />'))->toContain('data-count="2"');
});

it('records the type\'s list for the page, so adding an item sets the page aside', function (): void {
    app(RenderRecord::class)->start(0);

    Blade::render('<x-teme::items type="film" />');

    expect(app(RenderRecord::class)->tags())->toContain(PageTags::listOf('film'));
});
