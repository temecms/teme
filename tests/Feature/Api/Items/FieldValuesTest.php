<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Api\FieldList;
use App\Domain\Api\Items\FieldValues;
use App\Domain\Cards\CardMedia;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://example.com']);
    URL::forceRootUrl('https://example.com');
    URL::forceScheme('https');
    $this->site = DeliverySite::build();
    Switches::apiOn();
});

/**
 * The fields of an item's answer.
 *
 * @return array<string, mixed>
 */
function fieldValuesOf(string $id): array
{
    return (array) test()->getJson('/api/v1/items/'.$id)->assertOk()->json('data.fields');
}

/**
 * Saves and publishes a movie of the fixture's with these fields.
 *
 * @param  array<string, mixed>  $fields
 */
function fieldValuesMovie(DeliverySite $site, string $title, array $fields): string
{
    $movie = lifecycleDraft($site->author, $title, 'movie');
    $movie->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $title, ['type' => 'doc', 'content' => []], $fields, $movie->working_revision_id, (string) $site->author->id));
    listsPublish($movie, $site->admin);

    return $movie->id;
}

it('gives each field type its value', function (): void {
    $site = $this->site;
    $fields = fieldValuesOf($site->public->id);
    $poster = $fields['poster'];

    // D6's table, one row a type, over Arrival's stored values.
    $expected = [
        'date' => ['release_date', '2016-11-11'],
        'datetime' => ['premiere', '2016-09-01T18:00:00Z'],
        'number' => ['runtime', 116],
        'select' => ['rating', ['value' => 'pg', 'label' => 'PG-13']],
        'boolean' => ['in_cinemas', false],
        'url' => ['trailer_url', 'https://video.example/arrival'],
        'text' => ['tagline', 'Why are they here?'],
    ];

    foreach ($expected as $type => [$handle, $value]) {
        expect($fields[$handle])->toBe($value, $type);
    }

    expect(array_keys($poster))->toEqualCanonicalizing(['id', 'kind', 'mime_type', 'alt', 'decorative', 'width', 'height', 'focal', 'url', 'sizes'])
        ->and($poster['id'])->toBe($site->poster->id)
        ->and($poster['alt'])->toBe('The poster')
        ->and($fields['sequel'])->toBe([
            'id' => $site->sequel->id,
            'type' => 'movie',
            'language' => 'en-GB',
            'title' => 'Arrival II',
            'path' => Addresses::relativeUrl($site->sequel),
            'url' => Addresses::urlFor($site->sequel),
        ])
        ->and(array_keys(FieldValues::TYPES))->toEqualCanonicalizing(['text', 'textarea', 'number', 'select', 'boolean', 'url', 'date', 'datetime', 'media', 'entry']);
});

it('gives null for an empty field and false for an unticked one', function (): void {
    $fields = fieldValuesOf($this->site->sequel->id);

    expect($fields)->toBe([
        'release_date' => null,
        'premiere' => null,
        'runtime' => null,
        // A choice left alone is the field's first option, here "None", stored as ''.
        'rating' => null,
        'in_cinemas' => false,
        'trailer_url' => null,
        'tagline' => null,
        'poster' => null,
        'sequel' => null,
    ]);
});

it('gives an entry field the item while it is live and public, and null when it is a draft, private or in the trash', function (): void {
    $site = $this->site;
    $actor = (string) $site->admin->id;

    expect(fieldValuesOf($site->public->id)['sequel']['id'])->toBe($site->sequel->id)
        ->and(fieldValuesOf($site->pointer->id)['sequel'])->toBeNull();

    app(UnpublishContent::class)->execute(new ContentActionInput($site->sequel->id, $actor));
    expect(fieldValuesOf($site->public->id)['sequel'])->toBeNull();

    listsPublish($site->sequel, $site->admin);
    expect(fieldValuesOf($site->public->id)['sequel']['id'])->toBe($site->sequel->id);

    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($site->sequel->id, 'private', null, $actor));
    expect(fieldValuesOf($site->public->id)['sequel'])->toBeNull();

    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($site->sequel->id, 'public', null, $actor));
    app(TrashContent::class)->execute(new ContentActionInput($site->sequel->id, $actor));
    $trashed = $this->getJson('/api/v1/items/'.$site->public->id)->assertOk();

    expect($trashed->json('data.fields.sequel'))->toBeNull()
        ->and((string) $trashed->getContent())->not->toContain($site->sequel->id);
});

it('gives a media field this use\'s alt text, and null once the file is in the trash', function (): void {
    $site = $this->site;

    expect(fieldValuesOf($site->public->id)['poster']['alt'])->toBe('The poster');

    app(TrashMedia::class)->execute(new MediaActionInput($site->poster->id, (string) $site->admin->id));
    // What a request has read it keeps for that request only (CardMedia); the next answer reads anew.
    app()->forgetInstance(CardMedia::class);

    expect(fieldValuesOf($site->public->id)['poster'])->toBeNull();
});

it('gives a date-only field as a date and a date and time in UTC', function (): void {
    $site = $this->site;
    $id = fieldValuesMovie($site, 'Late show', [
        DeliverySite::RELEASE => '2026-12-31',
        // Half past midnight in Lagos on New Year's Day is still 31 December in UTC.
        DeliverySite::PREMIERE => '2027-01-01T00:30:00+01:00',
    ]);
    $fields = fieldValuesOf($id);

    expect($fields['release_date'])->toBe('2026-12-31')
        ->and($fields['premiere'])->toBe('2026-12-31T23:30:00Z');
});

it('shows only the fields shown on the site, never a hidden, removed or built-in one', function (): void {
    $site = $this->site;
    StoredFieldRows::remove(DeliverySite::TAGLINE);
    $response = $this->getJson('/api/v1/items/'.$site->public->id)->assertOk();
    $fields = (array) $response->json('data.fields');
    $builtIn = array_map(fn (Field $field): string => $field->handle, array_filter(app(FieldRegistry::class)->forType('movie'), fn (Field $field): bool => ! $field->stored));

    expect(array_keys($fields))->toBe(['release_date', 'premiere', 'runtime', 'rating', 'in_cinemas', 'trailer_url', 'poster', 'sequel'])
        ->and($fields)->not->toHaveKeys(['notes', 'backstage', 'tagline', 'noindex', 'canonical', 'template'])
        ->and(array_intersect(array_keys($fields), $builtIn))->toBe([])
        ->and((string) $response->getContent())->not->toContain(DeliverySite::NOTES_VALUE)
        ->not->toContain($site->hiddenOnly->id)
        ->not->toContain('Why are they here?')
        ->not->toContain(BuiltInFields::NOINDEX);
});

it('keeps the fields in the type\'s order', function (): void {
    expect(array_keys(fieldValuesOf($this->site->public->id)))
        ->toBe(array_map(fn (Field $field): string => $field->handle, FieldList::shown('movie')))
        ->toBe(['release_date', 'premiere', 'runtime', 'rating', 'in_cinemas', 'trailer_url', 'tagline', 'poster', 'sequel']);
});
