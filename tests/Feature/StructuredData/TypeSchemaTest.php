<?php

declare(strict_types=1);

use App\Domain\StructuredData\TypeSchema;
use App\Domain\StructuredData\TypeSchemas;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => typesFresh());

it('gives posts a web page whose main entity is a blog post, pages a web page with none, and both the three article types to choose from (I-709)', function (): void {
    $schemas = app(TypeSchemas::class);

    expect($schemas->for('post'))->toEqual(new TypeSchema('WebPage', 'BlogPosting', ['Article', 'NewsArticle', 'BlogPosting']))
        ->and($schemas->for('page'))->toEqual(new TypeSchema('WebPage', null, ['Article', 'NewsArticle', 'BlogPosting']))
        ->and([$schemas->pageType('post', []), $schemas->mainEntity('post', [])])->toBe(['WebPage', 'BlogPosting'])
        ->and([$schemas->pageType('page', []), $schemas->mainEntity('page', [])])->toBe(['WebPage', null]);
});

it('gives a type that declares no entry, and one nobody registered, a web page with no main entity, so its items still describe themselves (I-701)', function (): void {
    app(ContentTypeRegistry::class)->register(new ContentType('event', 'Event', 'Events', 'events'));

    expect(app(TypeSchemas::class)->for('event'))->toEqual(new TypeSchema)
        ->and(app(TypeSchemas::class)->for('no_such_type'))->toEqual(new TypeSchema)
        ->and(app(TypeSchemas::class)->mainEntity('event', []))->toBeNull();
});

it('refuses an entry that couldn\'t describe a page (I-709)', function (string $case, string $message): void {
    expect(fn () => match ($case) {
        'an unknown page type' => new TypeSchema('ProfilePage'),
        'a name that isn\'t a type\'s' => new TypeSchema('WebPage', null, ['blogPosting']),
        default => new TypeSchema('WebPage', 'NewsArticle', ['Article']),
    })->toThrow(InvalidArgumentException::class, $message);
})->with([
    'an unknown page type' => ['an unknown page type', "A type's page type must be WebPage, AboutPage, ContactPage or CollectionPage, not [ProfilePage]."],
    'a name that isn\'t a type\'s' => ['a name that isn\'t a type\'s', "[blogPosting] isn't a schema.org type's name: a capital letter, then letters."],
    'a main entity its items couldn\'t choose' => ['a main entity its items couldn\'t choose', "A type's main entity [NewsArticle] must be one of those its items may choose."],
]);
