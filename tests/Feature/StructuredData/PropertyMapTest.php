<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Types\DatetimeField;
use App\Domain\Fields\Types\TextField;
use App\Domain\Site\Document\JsonLd;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\Document\Withheld;
use App\Domain\Site\Head\HeadValues;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\StructuredData\ItemNodes;
use App\Domain\StructuredData\TypeSchema;
use App\Domain\StructuredData\TypeSchemas;
use App\Domain\StructuredData\Vocabulary;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    typesFresh();
    cmSettings(['timezone' => 'UTC'], 'general');
    $this->summary = '01K6A0P1CE000000000000SM01';
    $this->lede = '01K6A0P1CE000000000000DE01';
    $this->starts = '01K6A0P1CE000000000000ST01';
    $fields = app(FieldRegistry::class);
    $fields->register(new Field($this->summary, 'summary', 'Summary', 'The talk in a line.', new TextField, ['talk', 'venue'], '0.1.0'));
    $fields->register(new Field($this->lede, 'lede', 'Lede', 'What the talk is about.', new TextField, ['talk', 'venue'], '0.1.0'));
    $fields->register(new Field($this->starts, 'starts', 'Starts', 'When the talk starts.', new DatetimeField, ['talk'], '0.1.0'));
    app(Vocabulary::class)->register(['Event'], ['startDate']);
    $this->talk = fn () => app(ContentTypeRegistry::class)->register(new ContentType('talk', 'Talk', 'Talks', 'talks', supports: ['excerpt'], schema: new TypeSchema(mainEntity: 'Event', mainEntities: ['Event'], properties: [
        'name' => [$this->summary],
        'description' => [$this->lede, BuiltInFields::EXCERPT],
        'startDate' => [$this->starts],
    ])));
    $this->nodes = function (string $type, array $fields): array {
        $view = new ArticleView(
            id: '01k6a0p1ce000000000000tk01', type: $type, title: 'Harbour talk', url: '/talks/harbour-talk/', template: null, standfirst: null, kicker: null,
            authors: [], published: new DateTimeImmutable('2026-10-08T09:00:00+00:00'), updated: null, publishedText: '8 October 2026', updatedText: null,
            readingMinutes: 1, lead: null, leadIsFeatured: false, bodyHtml: '', tags: [], related: [], fields: $fields,
        );
        $values = new HeadValues('Harbour talk', 'The head\'s description.', 'http://localhost/talks/harbour-talk/', null, [], null, null, 'article', null, null, null);

        return array_map(fn (JsonLdNode $node): array => $node->properties, app(ItemNodes::class)->for($view, $values));
    };
});

it('fills a main entity from its type\'s map, the first field with a value winning and replacing core\'s own (I-709)', function (): void {
    ($this->talk)();
    app(TypeSchemas::class)->verify();
    $event = fn (array $fields): ?array => sdNode(($this->nodes)('talk', $fields), 'http://localhost/talks/harbour-talk/#article');

    expect($event([BuiltInFields::EXCERPT => 'An evening about the tides.', $this->starts => '2026-11-01T18:00:00+00:00']))->toEqual([
        '@id' => 'http://localhost/talks/harbour-talk/#article', '@type' => 'Event', 'name' => 'Harbour talk',
        'mainEntityOfPage' => ['@id' => 'http://localhost/talks/harbour-talk/'], 'description' => 'An evening about the tides.', 'startDate' => '2026-11-01T18:00:00+00:00',
    ])
        ->and($event([$this->summary => 'The tides, explained', $this->lede => 'Bring a coat.', BuiltInFields::EXCERPT => 'An evening about the tides.']))
        ->toMatchArray(['name' => 'The tides, explained', 'description' => 'Bring a coat.']);
});

it('fills the page of a type with no main entity from its map (I-709)', function (): void {
    app(ContentTypeRegistry::class)->register(new ContentType('venue', 'Venue', 'Venues', 'venues', schema: new TypeSchema(properties: ['description' => [$this->lede]])));
    app(TypeSchemas::class)->verify();

    $nodes = ($this->nodes)('venue', [$this->lede => 'A hall by the harbour.']);

    expect(sdNode($nodes, 'http://localhost/talks/harbour-talk/')['description'])->toBe('A hall by the harbour.')
        ->and(sdNode($nodes, 'http://localhost/talks/harbour-talk/#article'))->toBeNull();
});

it('refuses at boot a type whose map names a field it doesn\'t have, or a term off the vocabulary (I-706, I-709)', function (string $case, string $message): void {
    app(ContentTypeRegistry::class)->register(new ContentType('broken', 'Broken', 'Brokens', 'brokens', schema: $case === 'a field it doesn\'t have'
        ? new TypeSchema(properties: ['description' => ['01K6A0P1CE000000000000ZZ01']])
        : new TypeSchema(mainEntity: 'Recipe', mainEntities: ['Recipe'])));

    expect(fn () => app(TypeSchemas::class)->verify())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a field it doesn\'t have' => ['a field it doesn\'t have', "Content type [broken] maps [description] from field [01K6A0P1CE000000000000ZZ01], which it doesn't have."],
    'a term off the vocabulary' => ['a term off the vocabulary', "Content type [broken] names [Recipe], which isn't on the structured-data vocabulary."],
]);

it('leaves out a mapped main entity that lacks what Google requires, keeping its page and the reason (I-703)', function (): void {
    ($this->talk)();

    $graph = JsonLd::build(array_map(fn (array $node): JsonLdNode => new JsonLdNode($node), ($this->nodes)('talk', [$this->starts => '2026-11-01T18:00:00+00:00'])));

    expect(array_column($graph->nodes, '@id'))->toBe(['http://localhost/talks/harbour-talk/'])
        ->and($graph->withheld)->toEqual([new Withheld('http://localhost/talks/harbour-talk/#article', ['Event'], [['location.address']])]);
});

it('refuses a map whose names couldn\'t be a property or a field key (I-709)', function (string $case, string $message): void {
    expect(fn () => new TypeSchema(properties: match ($case) {
        'a property\'s name' => ['StartDate' => [$this->starts]],
        'a property with no fields' => ['startDate' => []],
        default => ['startDate' => ['starts']],
    }))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a property\'s name' => ['a property\'s name', "[StartDate] isn't a schema.org property's name: a small letter, then letters."],
    'a property with no fields' => ['a property with no fields', "A type's property map gives [startDate] no field keys."],
    'a key that isn\'t a field\'s' => ['a key that isn\'t a field\'s', "A type's property map names [starts] for [startDate], which isn't a field key."],
]);
