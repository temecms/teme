<?php

declare(strict_types=1);

use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardReference;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldPlace;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\FieldType;
use App\Domain\Fields\Types\BooleanField;
use App\Domain\Fields\Types\CanonicalField;
use App\Domain\Fields\Types\DatetimeField;
use App\Domain\Fields\Types\EntryField;
use App\Domain\Fields\Types\MediaField;
use App\Domain\Fields\Types\NumberField;
use App\Domain\Fields\Types\SelectField;
use App\Domain\Fields\Types\TermField;
use App\Domain\Fields\Types\TextareaField;
use App\Domain\Fields\Types\TextField;
use App\Domain\Fields\Types\UrlField;
use App\Domain\Site\ViewModels\ImageRef;
use App\Domain\Site\ViewModels\TermLink;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Backups\ScreenRecords;

uses(RefreshDatabase::class);

/**
 * A field of this type, as the contract's tests ask it questions.
 *
 * @param  array<string, string>  $options
 */
function fcField(FieldType $type, array $options = [], ?int $maxLength = null): Field
{
    return new Field('01K6A0P1CE000000000000FC01', 'subject', 'Subject', 'What it is about.', $type, ['post'], '0.2.0', maxLength: $maxLength, options: $options);
}

/** The select options the contract's tests share. */
const FC_OPTIONS = ['' => 'Default', 'b' => 'B', '10' => 'Ten', '2' => 'Two'];

it('tells the editor which control draws each type and with what', function (FieldType $type, array $options, ?int $max, array $expected): void {
    expect($type->edit(fcField($type, $options, $max)))->toBe($expected);
})->with([
    'text with a maximum' => [new TextField, [], 200, ['component' => 'text', 'settings' => ['maxLength' => 200]]],
    'text with none' => [new TextField, [], null, ['component' => 'text', 'settings' => ['maxLength' => 255]]],
    'textarea with a maximum' => [new TextareaField, [], 320, ['component' => 'textarea', 'settings' => ['maxLength' => 320]]],
    'textarea with none' => [new TextareaField, [], null, ['component' => 'textarea', 'settings' => ['maxLength' => 5000]]],
    'number' => [new NumberField, [], null, ['component' => 'number', 'settings' => []]],
    'boolean' => [new BooleanField, [], null, ['component' => 'boolean', 'settings' => []]],
    'datetime' => [new DatetimeField, [], null, ['component' => 'datetime', 'settings' => ['dateOnly' => false]]],
    'datetime date only' => [new DatetimeField(dateOnly: true), [], null, ['component' => 'datetime', 'settings' => ['dateOnly' => true]]],
    'select' => [new SelectField, FC_OPTIONS, null, ['component' => 'select', 'settings' => ['options' => [
        ['value' => '', 'label' => 'Default'], ['value' => 'b', 'label' => 'B'], ['value' => '10', 'label' => 'Ten'], ['value' => '2', 'label' => 'Two'],
    ]]]],
    'url' => [new UrlField, [], null, ['component' => 'url', 'settings' => ['maxLength' => 2048]]],
    'media' => [new MediaField(['image'], allowsDecorative: false), [], null, ['component' => 'media', 'settings' => ['kinds' => ['image'], 'allowsDecorative' => false]]],
    'entry' => [new EntryField, [], null, ['component' => 'entry', 'settings' => []]],
    'term' => [new TermField, [], null, ['component' => null, 'settings' => []]],
    'canonical' => [new CanonicalField, [], null, ['component' => null, 'settings' => []]],
]);

it('sends options as an ordered list of strings, so a browser can’t reorder or retype number-like values', function (): void {
    // PHP stores the keys '10' and '2' as the integers 10 and 2; edit() casts each back.
    $settings = (new SelectField)->edit(fcField(new SelectField, ['10' => 'Ten', '2' => 'Two']))['settings'];

    expect(json_decode((string) json_encode($settings), true)['options'])->toBe([['value' => '10', 'label' => 'Ten'], ['value' => '2', 'label' => 'Two']]);
});

it('renders a stored value as a template draws it, and the default when nothing is stored', function (FieldType $type, array $options, mixed $stored, mixed $expected): void {
    $field = fcField($type, $options);

    $rendered = $type->render($field, $stored ?? $type->default($field));

    // toEqual() compares two dates by their moment alone, so the class is compared as well.
    expect($rendered)->toEqual($expected)
        ->and(is_object($expected) ? get_class($rendered) : null)->toBe(is_object($expected) ? get_class($expected) : null);
})->with([
    'text' => [new TextField, [], 'Ferry', 'Ferry'],
    'text default' => [new TextField, [], '', ''],
    'textarea' => [new TextareaField, [], 'Ferry', 'Ferry'],
    'textarea default' => [new TextareaField, [], '', ''],
    'whole number' => [new NumberField, [], 12, 12],
    'decimal' => [new NumberField, [], 1.5, 1.5],
    'number default' => [new NumberField, [], null, null],
    'boolean' => [new BooleanField, [], true, true],
    'boolean default' => [new BooleanField, [], false, false],
    'select' => [new SelectField, ['' => 'None', 'BlogPosting' => 'Blog post'], 'BlogPosting', ['value' => 'BlogPosting', 'label' => 'Blog post']],
    'select off the list' => [new SelectField, ['' => 'None', 'BlogPosting' => 'Blog post'], 'Gone', ['value' => 'Gone', 'label' => 'Gone']],
    'select empty' => [new SelectField, ['' => 'None', 'BlogPosting' => 'Blog post'], '', null],
    'select default' => [new SelectField, ['a' => 'Alpha', 'b' => 'Beta'], null, ['value' => 'a', 'label' => 'Alpha']],
    'url' => [new UrlField, [], 'https://example.org', 'https://example.org'],
    'url default' => [new UrlField, [], '', ''],
    'media' => [new MediaField, [], ['id' => 'M1', 'alt' => 'A boat', 'decorative' => false], new ImageRef('M1', 'A boat', false)],
    'media default' => [new MediaField, [], null, null],
    'datetime date only' => [new DatetimeField(dateOnly: true), [], '2024-03-01', new DateTimeImmutable('2024-03-01', new DateTimeZone('UTC'))],
    'canonical item' => [new CanonicalField, [], ['item' => 'C1'], null],
    'canonical default' => [new CanonicalField, [], null, null],
]);

it('renders the first option for a select with nothing stored', function (): void {
    $field = fcField(new SelectField, ['BlogPosting' => 'Blog post', 'Article' => 'Article']);

    expect((new SelectField)->render($field, (new SelectField)->default($field)))->toBe(['value' => 'BlogPosting', 'label' => 'Blog post']);
});

it('renders a datetime on the site’s clock', function (): void {
    $this->seed(RoleSeeder::class);
    ScreenRecords::settings(['general' => ['timezone' => 'Africa/Lagos']]);

    $value = (new DatetimeField)->render(fcField(new DatetimeField), '2026-11-07T09:00:00+00:00');

    expect(get_class($value))->toBe(DateTimeImmutable::class)
        ->and($value->format('Y-m-d H:i P'))->toBe('2026-11-07 10:00 +01:00')
        ->and((new DatetimeField)->render(fcField(new DatetimeField), null))->toBeNull();
});

it('renders an entry as its target’s address now, and nothing for one with no address', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('editor');
    $published = addrPublished($author, 'Ferry fares');
    $draft = lifecycleDraft($author, 'Not yet');
    $type = new EntryField;

    expect($type->render(fcField($type), $published->id))->toBe('/ferry-fares/')
        ->and($type->render(fcField($type), $published->id))->not->toBeNull()
        ->and($type->render(fcField($type), $draft->id))->toBeNull()
        ->and($type->render(fcField($type), '01K6A0P1CE00000000000NOSUCH'))->toBeNull()
        ->and($type->render(fcField($type), null))->toBeNull();
});

it('renders a term as its link, and records the term’s tag on the page', function (): void {
    $term = taxonomyTerm('category', 'News');
    app(RenderRecord::class)->start(0);
    $type = new TermField;

    $link = $type->render(fcField($type), $term->id);

    expect($link)->toBeInstanceOf(TermLink::class)
        ->and($link->name)->toBe('News')
        ->and(app(RenderRecord::class)->tags())->toContain("term:{$term->id}")
        ->and($type->render(fcField($type), null))->toBeNull();
});

it('gives the index type of each type, and none for those that can’t be sorted', function (FieldType $type, ?string $expected): void {
    expect($type->sqlType(fcField($type)))->toBe($expected);
})->with([
    'text' => [new TextField, 'text'],
    'textarea' => [new TextareaField, null],
    'number' => [new NumberField, 'numeric'],
    'boolean' => [new BooleanField, 'boolean'],
    'datetime' => [new DatetimeField, 'text'],
    'select' => [new SelectField, 'text'],
    'url' => [new UrlField, null],
    'media' => [new MediaField, null],
    'entry' => [new EntryField, null],
    'term' => [new TermField, null],
    'canonical' => [new CanonicalField, null],
]);

it('sorts datetimes as text because they are stored in UTC with one offset', function (): void {
    // In time order (00:00Z, 03:00Z, 04:30Z), though as typed the last sorts first.
    $typed = ['2026-11-08T01:00:00+01:00', '2026-11-08T03:00:00+00:00', '2026-11-07T23:30:00-05:00'];
    $type = new DatetimeField;
    $stored = array_map(fn (string $at): string => $type->normalise(fcField($type), $at), $typed);
    [$typedSorted, $storedSorted] = [$typed, $stored];
    sort($typedSorted, SORT_STRING);
    sort($storedSorted, SORT_STRING);

    expect($typedSorted)->not->toBe($typed)
        ->and($storedSorted)->toBe($stored)
        ->and($type->sqlType(fcField($type)))->toBe('text');
});

it('lists what a value points at by ID', function (FieldType $type, mixed $stored, array $expected): void {
    expect(array_map(fn (CardReference $reference): array => [$reference->kind, $reference->id], $type->references(fcField($type), $stored)))->toBe($expected);
})->with([
    'media' => [new MediaField, ['id' => 'M1', 'alt' => null, 'decorative' => false], [['media', 'M1']]],
    'entry' => [new EntryField, 'C1', [['content', 'C1']]],
    'term' => [new TermField, 'T1', [['term', 'T1']]],
    'canonical item' => [new CanonicalField, ['item' => 'C1'], [['content', 'C1']]],
    'canonical address' => [new CanonicalField, ['address' => 'https://example.org/a'], []],
    'text' => [new TextField, 'Ferry', []],
    'select' => [new SelectField, 'b', []],
    'media empty' => [new MediaField, null, []],
    'entry empty' => [new EntryField, null, []],
    'term empty' => [new TermField, '', []],
]);

it('gives the words a value adds to search', function (FieldType $type, array $options, mixed $stored, string $expected): void {
    expect($type->text(fcField($type, $options), $stored))->toBe($expected);
})->with([
    'text' => [new TextField, [], '  Ferry fares ', 'Ferry fares'],
    'textarea' => [new TextareaField, [], " A summary.\n", 'A summary.'],
    'select label' => [new SelectField, ['' => 'None', 'a' => 'Alpha'], 'a', 'Alpha'],
    'select empty' => [new SelectField, ['' => 'None', 'a' => 'Alpha'], '', ''],
    'number' => [new NumberField, [], 12, ''],
    'boolean' => [new BooleanField, [], true, ''],
    'datetime' => [new DatetimeField, [], '2026-11-07T09:00:00+00:00', ''],
    'url' => [new UrlField, [], 'https://example.org', ''],
    'media' => [new MediaField, [], ['id' => 'M1'], ''],
    'entry' => [new EntryField, [], 'C1', ''],
    'term' => [new TermField, [], 'T1', ''],
    'canonical' => [new CanonicalField, [], ['item' => 'C1'], ''],
]);

it('answers every method for an empty value without throwing', function (FieldType $type): void {
    $this->seed(RoleSeeder::class);
    $field = fcField($type, ['' => 'None']);

    foreach ([null, '', []] as $empty) {
        expect($type->references($field, $empty))->toBe([])
            ->and($type->text($field, $empty))->toBe('');
        $type->render($field, $empty);
    }
})->with([
    new TextField, new TextareaField, new NumberField, new BooleanField, new DatetimeField, new SelectField,
    new UrlField, new MediaField, new EntryField, new TermField, new CanonicalField,
]);

it('places every built-in field with core, and any other field in Details unless it says otherwise', function (): void {
    // Enum cases can't go through array_unique(); their values can.
    expect(array_values(array_unique(array_map(fn (Field $field): string => $field->place->value, app(FieldRegistry::class)->all()))))->toBe(['core'])
        ->and(fcField(new TextField)->place)->toBe(FieldPlace::Details);
});

it('refuses a field placed in Details whose type only core’s panels draw', function (FieldType $type): void {
    expect(fn () => (new FieldRegistry)->register(fcField($type)))
        ->toThrow(InvalidArgumentException::class, 'Field [subject] is of a type only core’s panels draw, so it can’t be placed in Details.');
})->with([new TermField, new CanonicalField]);
