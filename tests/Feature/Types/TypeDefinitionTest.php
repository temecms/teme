<?php

declare(strict_types=1);

use App\Domain\Comments\Discussion;
use App\Domain\Types\Exceptions\InvalidTypeDefinition;
use App\Domain\Types\TypeDefinition;

it('reads a definition and gives it back in the file’s order', function (): void {
    $raw = ['pattern' => '/films/{slug}/', 'comments' => 'open', 'supports' => ['seo'], 'taxonomies' => ['category'], 'searchable' => true, 'archive' => true, 'public' => true, 'single' => true, 'icon' => 'film', 'segment' => 'films', 'plural' => 'Films', 'singular' => 'Film', 'key' => 'film'];

    expect(TypeDefinition::fromArray($raw)->toArray())->toBe([
        'key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'icon' => 'film', 'single' => true,
        'public' => true, 'archive' => true, 'searchable' => true,
        'taxonomies' => ['category'], 'supports' => ['seo'], 'comments' => 'open', 'pattern' => '/films/{slug}/', 'permissions' => 'shared',
    ]);
});

it('fills in what a short definition leaves out', function (): void {
    expect(TypeDefinition::fromArray(['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films'])->toArray())->toBe([
        'key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'icon' => 'file', 'single' => false,
        'public' => true, 'archive' => false, 'searchable' => true,
        'taxonomies' => [], 'supports' => [], 'comments' => null, 'pattern' => null, 'permissions' => 'shared',
    ]);
});

it('names every problem with a definition it can’t read, in the file’s order', function (array $raw, string $problems): void {
    expect(fn () => TypeDefinition::fromArray($raw))->toThrow(function (InvalidTypeDefinition $invalid) use ($problems): void {
        expect($invalid->getMessage())->toBe($problems);
    });
})->with([
    'nothing' => [[], '[key] is missing. [singular] is missing. [plural] is missing. [segment] is missing.'],
    'a list' => [['film', 'Film'], 'A type must be an object of named values.'],
    'a number for a name' => [['key' => 'film', 'singular' => 7, 'plural' => 'Films', 'segment' => 'films'], '[singular] must be text.'],
    'yes for true' => [['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'public' => 'yes'], '[public] must be true or false.'],
    'a taxonomy that isn’t text' => [['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'taxonomies' => ['category', 3]], '[taxonomies] must be a list of text.'],
    'taxonomies by name' => [['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'taxonomies' => ['a' => 'category']], '[taxonomies] must be a list of text.'],
    'hidden comments' => [['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'comments' => 'hidden'], '[comments] must be open, closed or null.'],
    'a numbered pattern' => [['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'pattern' => 5], '[pattern] must be text or null.'],
    'a key a later version adds' => [['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'hierarchical' => true], '[hierarchical] isn’t part of a version 1 type.'],
    'one item only as text' => [['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'single' => 'yes'], '[single] must be true or false.'],
    'permissions of a kind Teme hasn’t' => [['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'permissions' => 'mine'], '[permissions] must be shared or own.'],
    'two at once' => [['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'public' => 'yes', 'archive' => 1], '[public] must be true or false. [archive] must be true or false.'],
]);

it('makes a stored, listed content type of it, with the global abilities', function (): void {
    $type = TypeDefinition::fromArray(['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'comments' => 'open', 'pattern' => '/films/{slug}/', 'taxonomies' => ['category']])->toContentType();

    expect($type->stored)->toBeTrue()
        ->and($type->listed)->toBeTrue()
        ->and($type->comments)->toBe(Discussion::Open)
        ->and($type->pattern)->toBe('/films/{slug}/')
        ->and($type->taxonomies)->toBe(['category'])
        ->and($type->ability('publish'))->toBe('content.publish');
});

it('settles a public type’s address pattern from its segment, and gives one that isn’t public none', function (): void {
    $film = ['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films'];

    expect(TypeDefinition::fromArray($film)->settled()->pattern)->toBe('/films/{slug}/')
        ->and(TypeDefinition::fromArray([...$film, 'pattern' => '/watch/{slug}/'])->settled()->pattern)->toBe('/watch/{slug}/')
        ->and(TypeDefinition::fromArray([...$film, 'public' => false])->settled()->pattern)->toBeNull()
        ->and(TypeDefinition::fromArray($film)->withPattern('/watch/{slug}/')->pattern)->toBe('/watch/{slug}/');
});

it('reads a saved row as far as it can, for the screen that mends it', function (): void {
    $raw = ['key' => 'event', 'segment' => 'events', 'public' => 'yes', 'icon' => 'calendar', 'single' => 1, 'taxonomies' => ['venue', 3], 'comments' => 'hidden', 'pattern' => '/events/{slug}/', 'colour' => 'red'];

    expect(TypeDefinition::lenient($raw)->toArray())->toBe([
        'key' => 'event', 'singular' => '', 'plural' => '', 'segment' => 'events', 'icon' => 'calendar', 'single' => false,
        'public' => true, 'archive' => false, 'searchable' => true,
        'taxonomies' => [], 'supports' => [], 'comments' => null, 'pattern' => '/events/{slug}/', 'permissions' => 'shared',
    ])
        ->and(TypeDefinition::lenient(ctDefinition()->toArray())->toArray())->toBe(ctDefinition()->toArray());
});
