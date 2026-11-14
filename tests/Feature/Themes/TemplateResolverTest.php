<?php

declare(strict_types=1);

use App\Domain\Themes\TemplateResolver;
use App\Domain\Themes\ViewIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;

// themeActivate() writes the settings table. Without a database trait the
// parallel gate doesn't give the test its own database, so the write would
// land in the one every process shares (I-441).
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
});

it('picks the most specific template that exists, and lists every candidate', function (): void {
    // The good fixture has templates/single-page-full-width, single, archive, index and error-404.
    $resolution = app(TemplateResolver::class)->resolve(new ViewIntent(kind: 'single', type: 'page', template: 'full-width'));

    expect($resolution->view)->toBe('theme::templates.single-page-full-width')
        ->and(array_column($resolution->candidates, 'name'))->toBe(['templates.single-page-full-width', 'templates.single-page', 'templates.single']);
});

it('never offers more than three candidates, and never lists the index floor', function (): void {
    $resolution = app(TemplateResolver::class)->resolve(new ViewIntent(kind: 'taxonomy', taxonomy: 'category', term: 'news'));

    expect(array_column($resolution->candidates, 'name'))->toBe(['templates.taxonomy-category-news', 'templates.taxonomy-category', 'templates.archive'])
        ->and($resolution->view)->toBe('theme::templates.archive');
});

it('uses the theme\'s general error template when it has none for that code', function (): void {
    // The good fixture has templates/error-404 and templates/error, but no error-500.
    $resolution = app(TemplateResolver::class)->resolve(new ViewIntent(kind: 'error', code: 500));

    expect($resolution->view)->toBe('theme::templates.error')
        ->and($resolution->fallback)->toBeFalse();
});

it('explains the choice for a URL from the command line, without rendering', function (): void {
    $this->artisan('teme:theme:resolve', ['url' => '/no-such-page/', '--json' => true])
        ->expectsOutputToContain('"kind":"notfound"')
        ->assertSuccessful();
});
