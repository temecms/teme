<?php

declare(strict_types=1);

use App\Domain\Site\Document\JsonLd;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\Document\RequiredProperties;
use App\Domain\Site\Document\Withheld;

it('drops empty values at any depth, keeping false and zero, and writes one @type as a name (I-697)', function (): void {
    $graph = JsonLd::graph([new JsonLdNode([
        '@id' => 'https://example.test/harbour-news/#article',
        '@type' => ['Article', 'Article'],
        'headline' => 'Harbour news',
        'description' => '',
        'alternativeHeadline' => null,
        'keywords' => [],
        'isAccessibleForFree' => false,
        'wordCount' => 0,
        'author' => [['@type' => 'Person', 'name' => 'Jane Doe', 'url' => '']],
        'publisher' => ['logo' => ['url' => null]],
    ])]);

    expect($graph)->toBe([[
        '@id' => 'https://example.test/harbour-news/#article',
        '@type' => 'Article',
        'headline' => 'Harbour news',
        'isAccessibleForFree' => false,
        'wordCount' => 0,
        'author' => [['@type' => 'Person', 'name' => 'Jane Doe']],
    ]]);
});

it('withholds a node missing what Google requires, says what it lacks, drops references to it, and keeps one to a node never in the graph (I-703, I-725)', function (): void {
    $page = 'https://example.test/soup/';

    $graph = JsonLd::build([
        new JsonLdNode(['@id' => $page, '@type' => 'WebPage', 'name' => 'Soup']),
        new JsonLdNode(['@id' => $page.'#recipe', '@type' => 'Recipe', 'name' => 'Leek soup', 'image' => '', 'mainEntityOfPage' => ['@id' => $page]]),
        new JsonLdNode(['@id' => $page.'#article', '@type' => 'Article', 'headline' => 'Soup', 'about' => ['@id' => $page.'#recipe'], 'hasPart' => [['@id' => $page.'#recipe'], ['@id' => 'https://elsewhere.example/#organization']]]),
    ]);

    expect(array_column($graph->nodes, '@id'))->toBe([$page, $page.'#article'])
        ->and($graph->nodes[1])->toBe(['@id' => $page.'#article', '@type' => 'Article', 'headline' => 'Soup', 'hasPart' => [['@id' => 'https://elsewhere.example/#organization']]])
        ->and($graph->withheld)->toEqual([new Withheld($page.'#recipe', ['Recipe'], [['image']])]);
});

it('checks every type a node carries, reading through a reference to the node it names: a profile page whose person has no name is withheld (I-703)', function (): void {
    $author = 'https://example.test/author/jane-doe/';
    $nameless = JsonLd::build([
        new JsonLdNode(['@id' => $author, '@type' => ['CollectionPage', 'ProfilePage'], 'mainEntity' => ['@id' => $author.'#person']]),
        new JsonLdNode(['@id' => $author.'#person', '@type' => 'Person', 'url' => $author]),
    ]);
    $named = JsonLd::build([
        new JsonLdNode(['@id' => $author, '@type' => 'ProfilePage', 'mainEntity' => ['@id' => $author.'#person']]),
        new JsonLdNode(['@id' => $author.'#person', '@type' => 'Person', 'name' => 'Jane Doe']),
    ]);

    expect(array_column($nameless->nodes, '@id'))->toBe([$author.'#person'])
        ->and($nameless->withheld)->toEqual([new Withheld($author, ['CollectionPage', 'ProfilePage'], [['mainEntity.name']])])
        ->and(array_column($named->nodes, '@id'))->toBe([$author, $author.'#person'])
        ->and($named->withheld)->toBe([]);
});

it('meets a requirement of several properties with any one of them, and holds a type a pack adds to its own (I-703)', function (): void {
    app(RequiredProperties::class)->add('Course', [['name'], ['provider']]);

    $graph = JsonLd::build([
        new JsonLdNode(['@id' => 'https://example.test/kettle/', '@type' => 'Product', 'name' => 'Kettle', 'review' => ['@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => 'Ada']]]),
        new JsonLdNode(['@id' => 'https://example.test/pan/', '@type' => 'Product', 'name' => 'Pan']),
        new JsonLdNode(['@id' => 'https://example.test/sailing/', '@type' => 'Course', 'name' => 'Sailing']),
    ]);

    expect(array_column($graph->nodes, '@id'))->toBe(['https://example.test/kettle/'])
        ->and($graph->withheld)->toEqual([
            new Withheld('https://example.test/pan/', ['Product'], [['offers', 'review', 'aggregateRating']]),
            new Withheld('https://example.test/sailing/', ['Course'], [['provider']]),
        ])
        ->and(fn () => app(RequiredProperties::class)->add('Course', [[]]))->toThrow(InvalidArgumentException::class, 'A requirement of [Course] must name one or more properties, such as name or location.address.');
});
