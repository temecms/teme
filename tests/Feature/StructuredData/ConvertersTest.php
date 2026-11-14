<?php

declare(strict_types=1);

use App\Domain\Fields\Field;
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
use App\Domain\StructuredData\ImageObjects;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    cmSettings(['timezone' => 'Europe/Paris'], 'general');
    $this->author = User::factory()->create()->assignRole('author');
});

it('gives each field type\'s value as structured data states it, and nothing for one that says nothing (I-709)', function (string $type): void {
    [$fieldType, $value, $expected, $empty] = match ($type) {
        'text' => [new TextField, '  Leek soup  ', 'Leek soup', '  '],
        'textarea' => [new TextareaField, " A soup for winter.\n", 'A soup for winter.', ''],
        'number' => [new NumberField, '12.5', 12.5, null],
        'boolean' => [new BooleanField, false, false, null],
        'url' => [new UrlField, ' https://other.example/soup ', 'https://other.example/soup', ''],
        'datetime' => [new DatetimeField, '2026-11-01T18:00:00+00:00', '2026-11-01T19:00:00+01:00', ''],
        'select' => [new SelectField, 'NewsArticle', 'NewsArticle', ''],
        'entry' => [new EntryField, headPost($this->author, 'Harbour news')->id, 'http://localhost/harbour-news/', lifecycleDraft($this->author, 'Ferry fares')->id],
        'term' => [new TermField, headTerm('category', 'News')->id, 'News', '01k6a0p1ce000000000000nn01'],
        'media' => (function (): array {
            $picture = pageImage($this->author, 1600, 900);

            return [new MediaField, ['id' => $picture->id, 'alt' => null, 'decorative' => false], app(ImageObjects::class)->for($picture->id)['object'] ?? null, ['id' => '01K6A0P1CE000000000000NN01']];
        })(),
        default => [new CanonicalField, ['item' => headPost($this->author, 'Harbour news')->id], null, null],
    };
    $field = new Field('01K6A0P1CE000000000000CV01', 'subject', 'Subject', 'A field to convert.', $fieldType, ['post'], '0.1.0', options: ['NewsArticle' => 'News article']);

    expect($fieldType->structuredData($field, $value))->toEqual($expected)
        ->and($fieldType->structuredData($field, $empty))->toBeNull();
})->with(['text', 'textarea', 'number', 'boolean', 'url', 'datetime', 'select', 'entry', 'term', 'media', 'a canonical choice']);
