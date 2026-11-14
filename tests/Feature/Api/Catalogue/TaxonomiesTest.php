<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
    $this->site = DeliverySite::build();
    Switches::apiOn();
});

it('lists the public taxonomies with the public types using them', function (): void {
    $response = $this->get('/api/v1/taxonomies')->assertOk()->assertHeader('Content-Type', 'application/json');
    $keys = array_column($response->json('data'), 'key');
    $genre = collect($response->json('data'))->firstWhere('key', 'genre');

    expect(array_keys((array) $response->json()))->toBe(['data', 'links'])
        ->and($response->json('links'))->toBe(['self' => 'http://localhost/api/v1/taxonomies'])
        ->and($keys)->toContain('genre', 'category', 'tag')
        ->and($keys)->not->toContain('shelf')
        ->and(array_keys($genre))->toEqualCanonicalizing(['key', 'singular', 'plural', 'hierarchical', 'types', 'links'])
        ->and($genre['singular'])->toBe('Genre')
        ->and($genre['plural'])->toBe('Genres')
        ->and($genre['hierarchical'])->toBeTrue()
        ->and($genre['types'])->toBe(['movie'])
        ->and($genre['links'])->toBe(['terms' => 'http://localhost/api/v1/terms?taxonomy=genre']);
});

it('refuses any parameter', function (): void {
    $this->get('/api/v1/taxonomies?page=2')->assertStatus(400)->assertJsonPath('code', 'invalid_parameter')->assertJsonPath('errors.0.parameter', 'page');
});
