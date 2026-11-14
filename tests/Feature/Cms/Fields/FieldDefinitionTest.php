<?php

declare(strict_types=1);

use App\Domain\Fields\FieldPlace;
use App\Domain\Fields\Stored\FieldDefinition;
use App\Domain\Fields\Stored\FieldRules;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A field as teme-types.json carries it: a required date-only release
 * date on posts, with no position, unless told otherwise.
 *
 * @param  array<string, mixed>  $overrides
 *
 * @return array<string, mixed>
 */
function fbEntry(array $overrides = []): array
{
    return array_replace([
        'key' => '01K9F1E1D0000000000000RD01',
        'type' => 'post',
        'handle' => 'release_date',
        'label' => 'Release date',
        'help' => '',
        'fieldType' => 'datetime',
        'settings' => ['dateOnly' => true],
        'required' => true,
        'search' => false,
        'filterable' => true,
        'show' => 'after',
    ], $overrides);
}

it('reads a definition and writes it back in the file’s order', function (): void {
    $entry = fbEntry();

    expect(array_keys(FieldDefinition::fromArray($entry)->toArray()))->toBe(array_keys($entry))
        ->and(FieldDefinition::fromArray($entry)->toArray())->toBe($entry)
        ->and(FieldDefinition::fromArray($entry)->toArray())->not->toHaveKey('position');
});

it('refuses an entry of the wrong shape, naming each problem', function (): void {
    expect(fn () => FieldDefinition::fromArray(['handle' => 7, 'required' => 'yes', 'position' => 1]))
        ->toThrow(InvalidArgumentException::class, '[type] is missing. [handle] must be text. [label] is missing. [fieldType] is missing. [required] must be true or false. [position] isn’t part of a version 1 field.');
});

it('builds each of the nine field types from its settings', function (string $fieldType, array $settings, string $component, array $editSettings): void {
    $field = FieldDefinition::fromArray(fbEntry(['fieldType' => $fieldType, 'settings' => $settings]))->toField();

    expect($field->type->name())->toBe($fieldType)
        ->and($field->type->edit($field))->toBe(['component' => $component, 'settings' => $editSettings])
        ->and($field->place)->toBe(FieldPlace::Details)
        ->and($field->stored)->toBeTrue();
})->with([
    ['text', [], 'text', ['maxLength' => 255]],
    ['textarea', [], 'textarea', ['maxLength' => 5000]],
    ['number', [], 'number', []],
    ['select', ['choices' => [['value' => 'pg', 'label' => 'PG']]], 'select', ['options' => [['value' => '', 'label' => 'None'], ['value' => 'pg', 'label' => 'PG']]]],
    ['boolean', [], 'boolean', []],
    ['url', [], 'url', ['maxLength' => 2048]],
    ['datetime', ['dateOnly' => true], 'datetime', ['dateOnly' => true]],
    ['media', [], 'media', ['kinds' => ['image'], 'allowsDecorative' => true]],
    ['entry', [], 'entry', []],
]);

it('refuses what a definition may not say, each with its words', function (array $change, string $input, string $message): void {
    expect(FieldRules::problems(FieldDefinition::fromArray(fbEntry($change)), null, null))->toHaveKey($input, $message);
})->with([
    'an empty label' => [['label' => '  '], 'label', 'Give the field a label.'],
    'a long label' => [['label' => str_repeat('a', 81)], 'label', 'A label is at most 80 characters.'],
    'a long help' => [['help' => str_repeat('a', 301)], 'help', 'Help text is at most 300 characters.'],
    'a bad handle' => [['handle' => 'Release-Date'], 'handle', 'A handle is lower case letters, digits and underscores, starting with a letter, at most 64 characters.'],
    'a built-in handle' => [['handle' => 'excerpt'], 'handle', 'excerpt is one of Teme’s own fields, so a field of yours can’t use it.'],
    'no field type' => [['fieldType' => ''], 'fieldType', 'Choose a field type.'],
    'an unknown field type' => [['fieldType' => 'hologram', 'settings' => []], 'fieldType', 'That isn’t a field type Teme has.'],
    'an unknown setting' => [['settings' => ['dateOnly' => true, 'colour' => 'red']], 'settings', '[settings.colour] isn’t part of a version 1 field.'],
    'a choice with no choices' => [['fieldType' => 'select', 'settings' => ['choices' => []]], 'settings.choices', 'Give a choice at least one option.'],
    'too many choices' => [['fieldType' => 'select', 'settings' => ['choices' => array_map(fn (int $i): array => ['value' => "c{$i}", 'label' => "C{$i}"], range(1, 101))]], 'settings.choices', 'At most 100 choices.'],
    'a bad choice value' => [['fieldType' => 'select', 'settings' => ['choices' => [['value' => 'PG 13', 'label' => 'PG-13']]]], 'settings.choices', 'A choice is stored as lower case letters, digits, hyphens and underscores, starting with a letter or digit.'],
    'two equal choice values' => [['fieldType' => 'select', 'settings' => ['choices' => [['value' => 'pg', 'label' => 'PG'], ['value' => 'pg', 'label' => 'Parental']]]], 'settings.choices', 'Two choices are stored as pg.'],
    'a choice with no label' => [['fieldType' => 'select', 'settings' => ['choices' => [['value' => 'pg', 'label' => '']]]], 'settings.choices', 'Give each choice a label.'],
    'Required on Yes or no' => [['fieldType' => 'boolean', 'settings' => []], 'required', 'A Yes or no field can’t be required.'],
    'search on a Number' => [['fieldType' => 'number', 'settings' => [], 'search' => true], 'search', 'Only text, long text and choice fields can be searched.'],
    'filterable on Media' => [['fieldType' => 'media', 'settings' => []], 'filterable', 'This kind of field can’t be filtered.'],
    'show sideways' => [['show' => 'sideways'], 'show', 'Choose where it shows on the site.'],
    'a type the site hasn’t' => [['type' => 'ghost'], 'type', 'There is no type ghost on this site.'],
]);

it('accepts a definition that says only what it may', function (): void {
    expect(FieldRules::problems(FieldDefinition::fromArray(fbEntry()), null, null))->toBe([]);
});

it('refuses a type change, a changed choice value and a date-only change while items hold values', function (): void {
    $date = FieldDefinition::fromArray(fbEntry());
    $choice = FieldDefinition::fromArray(fbEntry(['fieldType' => 'select', 'required' => false, 'settings' => ['choices' => [['value' => 'g', 'label' => 'G'], ['value' => 'pg', 'label' => 'PG']]]]));
    $choices = fn (array $choices): FieldDefinition => FieldDefinition::fromArray(fbEntry(['fieldType' => 'select', 'required' => false, 'settings' => ['choices' => $choices]]));
    $flipped = FieldDefinition::fromArray(fbEntry(['settings' => ['dateOnly' => false]]));

    expect(FieldRules::problems(FieldDefinition::fromArray(fbEntry(['fieldType' => 'text', 'settings' => []])), $date, 3))
        ->toHaveKey('fieldType', 'Can’t change: stored values would be read as another kind. To change it, add a new field and remove this one.')
        ->and(FieldRules::problems($choices([['value' => 'g', 'label' => 'G'], ['value' => 'pg_', 'label' => 'PG']]), $choice, 3))
        ->toHaveKey('settings.choices', 'A choice’s stored value can’t change once it is made; relabel it instead.')
        // Relabelling, taking off and adding are free.
        ->and(FieldRules::problems($choices([['value' => 'g', 'label' => 'General'], ['value' => 'r', 'label' => 'R']]), $choice, 3))->not->toHaveKey('settings.choices')
        ->and(FieldRules::problems($choices([['value' => 'pg', 'label' => 'PG']]), $choice, 3))->not->toHaveKey('settings.choices')
        ->and(FieldRules::problems($flipped, $date, 3))->toHaveKey('settings.dateOnly', 'Can’t change once a post holds a value.')
        ->and(FieldRules::problems($flipped, $date, 0))->toBe([]);
});
