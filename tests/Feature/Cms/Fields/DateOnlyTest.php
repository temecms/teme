<?php

declare(strict_types=1);

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldShow;
use App\Domain\Fields\Types\DatetimeField;
use App\Domain\Fields\Types\TextField;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\Fixtures\Backups\ScreenRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // +13:00 in November, so a moment's date and a date-only value differ.
    $this->seed(RoleSeeder::class);
    ScreenRecords::settings(['general' => ['timezone' => 'Pacific/Auckland']]);
});

/**
 * A date field of this type, as the tests ask it questions.
 */
function fbDateField(DatetimeField $type): Field
{
    return new Field('01K9F1E1D0000000000000RD01', 'release_date', 'Release date', 'When it came out.', $type, ['post'], '0.2.0');
}

it('stores a date-only value as the date alone and renders it on the site’s clock without moving it', function (): void {
    $type = new DatetimeField(dateOnly: true);
    $field = fbDateField($type);

    expect($type->normalise($field, '2024-03-01'))->toBe('2024-03-01')
        ->and($type->normalise($field, ''))->toBeNull()
        ->and($type->render($field, '2024-03-01')->format('Y-m-d H:i e'))->toBe('2024-03-01 00:00 Pacific/Auckland')
        ->and($type->structuredData($field, '2024-03-01'))->toBe('2024-03-01')
        ->and($type->edit($field))->toBe(['component' => 'datetime', 'settings' => ['dateOnly' => true]])
        ->and($type->sqlType($field))->toBe('text');
});

it('refuses a time on a date-only field, and a date-only value is refused nowhere else', function (): void {
    $dateOnly = new DatetimeField(dateOnly: true);
    $moment = new DatetimeField;

    expect(Validator::make(['v' => '2024-03-01T10:00'], ['v' => $dateOnly->rules(fbDateField($dateOnly))])->fails())->toBeTrue()
        ->and(Validator::make(['v' => '2024-03-01'], ['v' => $dateOnly->rules(fbDateField($dateOnly))])->fails())->toBeFalse()
        ->and(Validator::make(['v' => '2024-03-01T10:00:00+01:00'], ['v' => $moment->rules(fbDateField($moment))])->fails())->toBeFalse()
        ->and($moment->normalise(fbDateField($moment), '2024-03-01T10:00:00+01:00'))->toBe('2024-03-01T09:00:00+00:00');
});

it('tells the editor a date-and-time field is not date only', function (): void {
    expect((new DatetimeField)->edit(fbDateField(new DatetimeField)))->toBe(['component' => 'datetime', 'settings' => ['dateOnly' => false]]);
});

it('gives code’s fields no search, no filtering and no place on the site, as before', function (): void {
    $field = new Field('01K9F1E1D0000000000000FB01', 'any', 'Any', 'Any.', new TextField, ['post'], '0.2.0');

    expect([$field->search, $field->filterable, $field->show, $field->stored])->toBe([false, false, FieldShow::Hidden, false]);
});
