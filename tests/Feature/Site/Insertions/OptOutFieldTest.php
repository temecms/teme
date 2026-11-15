<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Types\ContentType;

it('gives the opt-out field to every type that supports search and social, not only posts and pages', function (): void {
    $keys = fn (ContentType $type): array => array_map(fn (Field $field): string => $field->key, app(FieldRegistry::class)->forDeclaration($type));

    expect($keys(new ContentType('recipe', 'Recipe', 'Recipes', 'recipes', supports: ['seo'])))->toContain(BuiltInFields::INSERTIONS_OFF)
        ->and($keys(new ContentType('note', 'Note', 'Notes', 'notes', public: false)))->not->toContain(BuiltInFields::INSERTIONS_OFF)
        ->and(app(FieldRegistry::class)->find(BuiltInFields::INSERTIONS_OFF)?->contentTypes)->toBe([]);
});
