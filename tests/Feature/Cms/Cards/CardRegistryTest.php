<?php

declare(strict_types=1);

use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\CardType;
use App\Domain\Cards\RenderContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers the six cards of slice 1.5, by name', function (): void {
    expect(app(CardRegistry::class)->names())->toBe(['image', 'gallery', 'embed', 'callout', 'snippet', 'button', 'audio', 'video', 'imported', 'site-code', 'featured-posts']);
});

it('records who registered each card', function (): void {
    $registry = app(CardRegistry::class);

    expect($registry->ownerOf('image'))->toBe('core')
        ->and($registry->ownerOf('no-such-card'))->toBeNull();
});

it('gives every card a label, a description, a version and a menu entry', function (): void {
    foreach (app(CardRegistry::class)->all() as $card) {
        expect(trim($card->label()))->not->toBe('')
            ->and(trim($card->description()))->not->toBe('')
            ->and(trim($card->since()))->not->toBe('')
            ->and($card->menu()->keywords)->not->toBeEmpty();
    }
});

it('declares every attribute a card stores, with its rules', function (): void {
    $image = app(CardRegistry::class)->find('image');

    expect(array_keys($image->attributes()))->toBe(['id', 'mediaId', 'alt', 'decorative', 'caption', 'credit', 'width'])
        ->and($image->attributes()['mediaId']->rules)->toContain('required');
});

it('reports what a card refers to', function (): void {
    $refs = app(CardRegistry::class)->find('image')->references(['mediaId' => '01K5Z9P2QH7N3X4C8V6B0M1T2R']);

    expect($refs)->toHaveCount(1)
        ->and($refs[0]->kind)->toBe('media')
        ->and($refs[0]->id)->toBe('01K5Z9P2QH7N3X4C8V6B0M1T2R');
});

it('contributes a card\'s own words to the search text', function (): void {
    expect(app(CardRegistry::class)->find('image')->plainText(['caption' => 'Boats at dawn', 'alt' => 'Sailing boats', 'credit' => 'Harbour Trust']))
        ->toBe('Boats at dawn Sailing boats');
});

/**
 * The browser builds one ProseMirror schema from the prose set and the cards,
 * and there a node and a mark may not share a name: a card called `code`
 * stopped the editor loading (B-137).
 */
it('refuses a card named like a prose node or mark', function (string $name): void {
    $card = new class($name) implements CardType
    {
        public function __construct(private string $cardName) {}

        public function name(): string
        {
            return $this->cardName;
        }

        public function label(): string
        {
            return 'Clash';
        }

        public function description(): string
        {
            return 'A card whose name is taken by the prose set.';
        }

        public function since(): string
        {
            return '0.1.0';
        }

        public function attributes(): array
        {
            return [];
        }

        public function menu(): CardMenuEntry
        {
            return new CardMenuEntry('Clash', 'Cards', ['clash'], 'box');
        }

        public function renderHtml(array $attrs, RenderContext $context): string
        {
            return '';
        }

        public function plainText(array $attrs): string
        {
            return '';
        }

        public function references(array $attrs): array
        {
            return [];
        }

        public function validate(array $attrs): void {}

        public function describeChange(array $before, array $after): string
        {
            return '';
        }
    };

    expect(fn () => (new CardRegistry)->register($card))
        ->toThrow(InvalidArgumentException::class, "Card name [{$name}] is taken by the prose set.");
})->with(['code', 'link', 'paragraph', 'text']);
