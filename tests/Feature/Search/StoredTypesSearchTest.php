<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('finds every searchable public type’s items, the site’s own types included, each at its own address (I-3296)', function (): void {
    $admin = ctAdmin();
    ltTypes();
    $post = searchPublished($admin, 'Lighthouse notes', 'The lighthouse at dawn.');
    $film = searchPublished($admin, 'Lighthouse', 'A film about a lighthouse.', 'film');
    $recipe = searchPublished($admin, 'Lighthouse stew', 'A stew the lighthouse keeper made.', 'recipe');

    expect(searchIds('lighthouse'))->toEqualCanonicalizing([$post->id, $film->id, $recipe->id])
        ->and(Addresses::relativeUrl($film))->toBe('/films/lighthouse/')
        ->and(Addresses::relativeUrl($recipe))->toBe('/recipes/lighthouse-stew/');
});
