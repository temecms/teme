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

/**
 * The type this key has in /api/v1/types, or null.
 *
 * @return array<string, mixed>|null
 */
function catalogueType(mixed $response, string $key): ?array
{
    return collect($response->json('data'))->firstWhere('key', $key);
}

it('lists the public types with their archives, public taxonomies and shown fields', function (): void {
    $response = $this->get('/api/v1/types')->assertOk()->assertHeader('Content-Type', 'application/json');
    $movie = catalogueType($response, 'movie');
    $keys = array_column($response->json('data'), 'key');

    expect(array_keys((array) $response->json()))->toBe(['data', 'links'])
        ->and($response->json('links'))->toBe(['self' => 'http://localhost/api/v1/types'])
        ->and($keys)->toContain('post', 'page', 'movie')
        ->and($keys)->not->toContain('region')->not->toContain('documentary')
        ->and(array_keys($movie))->toEqualCanonicalizing(['key', 'singular', 'plural', 'hierarchical', 'archive', 'taxonomies', 'fields', 'links'])
        ->and([$movie['singular'], $movie['plural'], $movie['hierarchical']])->toBe(['Movie', 'Movies', false])
        ->and(array_keys($movie['archive']))->toEqualCanonicalizing(['path', 'url'])
        ->and($movie['archive'])->toBe(['path' => '/movies/', 'url' => 'http://localhost/movies/'])
        ->and($movie['taxonomies'])->toBe(['genre'])
        ->and($movie['links'])->toBe(['items' => 'http://localhost/api/v1/items?type=movie'])
        ->and(array_column($movie['fields'], 'handle'))->toBe(['release_date', 'premiere', 'runtime', 'rating', 'in_cinemas', 'trailer_url', 'tagline', 'poster', 'sequel'])
        ->and(array_column($movie['fields'], 'type'))->toBe(['date', 'datetime', 'number', 'select', 'boolean', 'url', 'text', 'media', 'entry'])
        ->and(array_column($movie['fields'], 'label'))->toBe(['Release date', 'Premiere', 'Runtime', 'Rating', 'In cinemas', 'Trailer URL', 'Tagline', 'Poster', 'Sequel']);

    foreach ($movie['fields'] as $field) {
        expect(array_keys($field))->toEqualCanonicalizing(['handle', 'label', 'type', 'options']);
    }
});

it('gives a type with no archive page a null archive', function (): void {
    $page = catalogueType($this->get('/api/v1/types')->assertOk(), 'page');

    expect($page)->not->toBeNull()
        ->and(array_key_exists('archive', $page))->toBeTrue()
        ->and($page['archive'])->toBeNull();
});

it('gives a select field its options and every other field null', function (): void {
    $fields = collect(catalogueType($this->get('/api/v1/types')->assertOk(), 'movie')['fields'])->keyBy('handle');

    // The empty choice a select field starts with is the admin's way of showing no value: not a choice of the field.
    expect($fields['rating']['options'])->toBe([['value' => 'pg', 'label' => 'PG-13'], ['value' => 'r', 'label' => 'R']]);

    foreach ($fields->except('rating') as $field) {
        expect(array_key_exists('options', $field))->toBeTrue()
            ->and($field['options'])->toBeNull();
    }
});

it('never lists a hidden field or a taxonomy that isn\'t public', function (): void {
    $response = $this->get('/api/v1/types')->assertOk();
    $movie = catalogueType($response, 'movie');
    $body = (string) $response->getContent();

    expect(array_column($movie['fields'], 'handle'))->not->toContain('notes')->not->toContain('backstage')
        ->and($movie['taxonomies'])->not->toContain('shelf')
        ->and($body)->not->toContain('shelf')
        ->and($body)->not->toContain('Backstage');
});

it('refuses any parameter', function (): void {
    $this->get('/api/v1/types?limit=5')->assertStatus(400)->assertJsonPath('code', 'invalid_parameter')->assertJsonPath('errors.0.parameter', 'limit');
});
