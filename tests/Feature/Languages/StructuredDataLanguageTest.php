<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\StructuredData\NodeIds;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

it('says an item\'s own language in its nodes', function (): void {
    $page = 'http://localhost/fr/bonjour/';
    $graph = sdGraph('/fr/bonjour/');

    expect(sdNode($graph, $page)['inLanguage'] ?? null)->toBe('fr')
        ->and(sdNode($graph, $page.NodeIds::ARTICLE)['inLanguage'] ?? null)->toBe('fr');
});

it('says the page\'s language on a French listing, and the default\'s on the site\'s node', function (): void {
    $page = 'http://localhost/fr/category/actualites/';
    $graph = sdGraph('/fr/category/actualites/');

    expect(sdNode($graph, $page)['inLanguage'] ?? null)->toBe('fr')
        ->and(sdNode($graph, 'http://localhost/'.NodeIds::WEBSITE)['inLanguage'] ?? null)->toBe('en-GB');
});

it('documents the three names and their shapes in the theme reference', function (): void {
    $reference = (string) file_get_contents(base_path('docs/reference/theme.md'));

    expect($reference)->toContain('`$language`', '`$direction`', '`$alternates`', 'PageLanguageView', 'Alternate');
});
