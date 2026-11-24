<?php

declare(strict_types=1);

use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\CardType;
use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\DocumentValidator;
use App\Domain\Cards\Exceptions\InvalidDocumentException;
use App\Domain\Cards\RegionCard;
use App\Domain\Regions\RegionRules;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The rules a document is held to as the editor makes it: a region's, taking
 * every card, when it holds a card the editor offers only in a theme's
 * region (I-3127); none otherwise.
 *
 * @param  array<mixed>  $document
 */
function editorSchemaRules(array $document): ?RegionRules
{
    $regionCards = array_map(fn (CardType $card): string => $card->name(), array_filter(app(CardRegistry::class)->all(), fn (CardType $card): bool => $card instanceof RegionCard));
    $types = [];
    $keys = [];
    array_walk_recursive($document, function (mixed $value, mixed $key) use (&$types): void {
        if ($key === 'type' && is_string($value)) {
            $types[] = $value;
        }
    });
    // A plugin's card is named in a region by its key (docs/guides/developers/plugins/cards.md).
    array_walk_recursive($document, function (mixed $value, mixed $key) use (&$keys): void {
        if ($key === 'card' && (is_string($value) || $value === null)) {
            $keys[] = (string) $value;
        }
    });

    return array_intersect($types, $regionCards) === []
        ? null
        : new RegionRules([...array_map(fn (CardType $card): string => $card->name(), app(CardRegistry::class)->all()), ...$keys], PHP_INT_MAX);
}

/*
 * I-452: whatever the editor can produce, the server accepts. Everything
 * here comes from the editor's own schema, run in Node by
 * tests/Fixtures/editor-schema.mjs, which says what it makes and why.
 */

it('knows on the server every node and mark the editor has', function (): void {
    $editor = guardEditor();
    $cards = array_map(fn (CardType $card): string => $card->name(), app(CardRegistry::class)->all());

    expect(array_values(array_diff(array_keys($editor['nodes']), DocumentSchema::NODES, $cards)))->toBe([])
        ->and(array_values(array_diff(array_keys($editor['marks']), DocumentSchema::MARKS)))->toBe([]);
});

it('allows on the server every attribute the editor declares', function (): void {
    $missing = [];

    foreach (guardEditor()['nodes'] as $type => $attributes) {
        $card = app(CardRegistry::class)->find($type);
        $allowed = array_keys($card !== null ? $card->attributes() : (DocumentSchema::nodeAttributes()[$type] ?? []));

        foreach (array_diff($attributes, $allowed) as $attribute) {
            $missing[] = "{$type}.{$attribute}";
        }
    }

    foreach (guardEditor()['marks'] as $type => $attributes) {
        foreach (array_diff($attributes, $type === 'link' ? array_keys(DocumentSchema::linkAttributes()) : []) as $attribute) {
            $missing[] = "{$type}.{$attribute}";
        }
    }

    expect($missing)->toBe([]);
});

it('accepts every document the editor can make, once normalised', function (): void {
    $documents = guardEditor()['documents'];
    $refused = [];

    foreach ($documents as $name => $document) {
        try {
            app(DocumentValidator::class)->validate(DocumentNormaliser::normalise($document), allowUnfinishedCards: true, rules: editorSchemaRules($document));
        } catch (InvalidDocumentException $refusal) {
            $refused[$name] = ($refusal->path === '' ? '' : "{$refusal->path}: ").$refusal->errors()['document'][0];
        }
    }

    expect($refused)->toBe([], (string) json_encode($refused, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
});

it('keeps every card as it was through a copy and paste in the editor', function (): void {
    $broken = [];

    foreach (guardEditor()['cards'] as $name => $card) {
        try {
            app(DocumentValidator::class)->validate(docWith($card['after']), rules: editorSchemaRules(docWith($card['after'])));
        } catch (InvalidDocumentException $refusal) {
            $broken[$name] = ($refusal->path === '' ? '' : "{$refusal->path}: ").$refusal->errors()['document'][0];

            continue;
        }

        if ($card['after'] !== $card['before']) {
            $broken[$name] = 'changed to '.json_encode($card['after']);
        }
    }

    expect($broken)->toBe([], (string) json_encode($broken, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
});
