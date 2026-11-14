<?php

declare(strict_types=1);

use App\Domain\Types\Exceptions\InvalidTaxonomyDefinition;
use App\Domain\Types\TaxonomyDefinition;

it('reads the whole shape and writes it back in the file’s order', function (): void {
    $raw = ['key' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres', 'segment' => 'genres', 'icon' => 'folder', 'hierarchical' => true, 'public' => true, 'base' => 'genre', 'code_types' => ['post']];

    expect(TaxonomyDefinition::fromArray($raw)->toArray())->toBe($raw);
});

it('gives the defaults, and settles the base', function (): void {
    $taxonomy = TaxonomyDefinition::fromArray(['key' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres', 'segment' => 'genres']);

    expect($taxonomy->icon)->toBe('tag')
        ->and($taxonomy->hierarchical)->toBeFalse()
        ->and($taxonomy->public)->toBeTrue()
        ->and($taxonomy->base)->toBeNull()
        ->and($taxonomy->codeTypes)->toBe([])
        ->and($taxonomy->settled()->base)->toBe('genre')
        ->and(TaxonomyDefinition::fromArray([...$taxonomy->toArray(), 'public' => false, 'base' => 'genre'])->settled()->base)->toBeNull();
});

it('names every problem in a definition it can’t read', function (array $raw, string $message): void {
    $base = ['key' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres', 'segment' => 'genres'];

    expect(fn () => TaxonomyDefinition::fromArray($raw === [] ? [] : array_replace($base, $raw)))
        ->toThrow(function (InvalidTaxonomyDefinition $invalid) use ($message): void {
            expect($invalid->problems)->toContain($message);
        });
})->with([
    'a missing key' => [[], '[key] is missing.'],
    'a number for a name' => [['singular' => 4], '[singular] must be text.'],
    'a number for an icon' => [['icon' => 4], '[icon] must be text.'],
    'a word for nested' => [['hierarchical' => 'yes'], '[hierarchical] must be true or false.'],
    'a word for public' => [['public' => 'yes'], '[public] must be true or false.'],
    'a number for a base' => [['base' => 4], '[base] must be text or null.'],
    'a word for the types' => [['code_types' => 'post'], '[code_types] must be a list of text.'],
    'a key it doesn’t have' => [['colour' => 'red'], '[colour] isn’t part of a version 1 taxonomy.'],
]);

it('refuses a list for a taxonomy', function (): void {
    expect(fn () => TaxonomyDefinition::fromArray(['genre']))
        ->toThrow(function (InvalidTaxonomyDefinition $invalid): void {
            expect($invalid->problems)->toContain('A taxonomy must be an object of named values.');
        });
});

it('reads a row as far as it can, defaulting the rest', function (): void {
    $taxonomy = TaxonomyDefinition::lenient(['key' => 'genre', 'plural' => 'Genres', 'segment' => 'genres', 'hierarchical' => 'yes', 'public' => false, 'code_types' => 'post', 'colour' => 'red']);

    expect($taxonomy->key)->toBe('genre')
        ->and($taxonomy->singular)->toBe('')
        ->and($taxonomy->plural)->toBe('Genres')
        ->and($taxonomy->hierarchical)->toBeFalse()
        ->and($taxonomy->public)->toBeFalse()
        ->and($taxonomy->icon)->toBe('tag')
        ->and($taxonomy->codeTypes)->toBe([]);
});

it('becomes a stored taxonomy governed by the terms ability', function (): void {
    $taxonomy = txDefinition()->toTaxonomy();

    expect($taxonomy->stored)->toBeTrue()
        ->and($taxonomy->ability)->toBe('terms.manage')
        ->and($taxonomy->base)->toBe('genre')
        ->and($taxonomy->wordpress)->toBeNull();
});
