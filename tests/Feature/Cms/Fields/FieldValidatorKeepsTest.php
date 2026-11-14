<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentAutosave;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\SaveAutosaveInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\SaveAutosave;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Stored\StoredFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

const FBK_RATING = '01K9F1E1D0000000000000RA03';
const FBK_TAGLINE = '01K9F1E1D0000000000000TG05';
const FBK_TRAILER = '01K9F1E1D0000000000000TR04';
const FBK_RELEASE = '01K9F1E1D0000000000000RD01';

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(FBK_RATING, 'rating', ['fieldType' => 'select', 'settings' => ['choices' => [['value' => 'g', 'label' => 'G'], ['value' => 'pg', 'label' => 'PG']]]]), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FBK_TAGLINE, 'tagline'), 2);
    StoredFieldRows::store(StoredFieldRows::entry(FBK_TRAILER, 'trailer_url', ['fieldType' => 'url']), 3);
    StoredFieldRows::store(StoredFieldRows::entry(FBK_RELEASE, 'release_date', ['fieldType' => 'datetime', 'settings' => ['dateOnly' => true], 'required' => true]), 4);
});

/**
 * Saves the movie's draft with these field values, from its working revision.
 *
 * @param  array<string, mixed>  $fields
 */
function fbkSave(Content $movie, User $actor, array $fields): Content
{
    $movie->refresh();

    return app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $movie->title, ['type' => 'doc', 'content' => []], $fields, $movie->working_revision_id, (string) $actor->id));
}

/**
 * Keeps the movie's autosave with these field values.
 *
 * @param  array<string, mixed>  $fields
 */
function fbkAutosave(Content $movie, User $actor, array $fields): void
{
    $movie->refresh();
    app(SaveAutosave::class)->execute(new SaveAutosaveInput($movie->id, $movie->title, ['type' => 'doc', 'content' => []], $fields, [], $movie->working_revision_id, (string) $actor->id));
}

/** @return array<string, mixed> */
function fbkWorking(Content $movie): array
{
    return (array) ContentRevision::query()->findOrFail($movie->refresh()->working_revision_id)->fields;
}

it('keeps a removed field’s value through a save, so Restore finds it', function (): void {
    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie');
    fbkSave($movie, $this->editor, [FBK_TAGLINE => 'First contact', FBK_TRAILER => 'https://a.test/t']);
    StoredFieldRows::remove(FBK_TRAILER);

    fbkSave($movie, $this->editor, fbkWorking($movie));

    expect(fbkWorking($movie))->toMatchArray([FBK_TAGLINE => 'First contact', FBK_TRAILER => 'https://a.test/t']);
});

it('keeps a removed field’s stored value when a save leaves it out', function (): void {
    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie');
    fbkSave($movie, $this->editor, [FBK_TAGLINE => 'First contact', FBK_TRAILER => 'https://a.test/t']);
    StoredFieldRows::remove(FBK_TRAILER);

    fbkSave($movie, $this->editor, [FBK_TAGLINE => 'Second contact']);

    expect(fbkWorking($movie))->toMatchArray([FBK_TAGLINE => 'Second contact', FBK_TRAILER => 'https://a.test/t']);
});

it('ignores a value sent for a removed field, and stores none the item didn’t have', function (): void {
    $holding = lifecycleDraft($this->editor, 'Arrival', 'movie');
    fbkSave($holding, $this->editor, [FBK_TRAILER => 'https://a.test/t']);
    $never = lifecycleDraft($this->editor, 'Sicario', 'movie');
    StoredFieldRows::remove(FBK_TRAILER);

    fbkSave($holding, $this->editor, [FBK_TRAILER => 'https://evil.test/x']);
    fbkSave($never, $this->editor, [FBK_TRAILER => 'x']);
    fbkAutosave($never, $this->editor, [FBK_TRAILER => 'x']);

    expect(fbkWorking($holding)[FBK_TRAILER])->toBe('https://a.test/t')
        ->and(fbkWorking($never))->not->toHaveKey(FBK_TRAILER)
        ->and((array) ContentAutosave::query()->where('content_id', $never->id)->firstOrFail()->fields)->not->toHaveKey(FBK_TRAILER);
});

it('keeps a choice taken off the list while the item doesn’t change it, and refuses it when chosen anew', function (): void {
    $rated = lifecycleDraft($this->editor, 'Arrival', 'movie');
    fbkSave($rated, $this->editor, [FBK_RATING => 'pg']);
    StoredFieldRows::change(FBK_RATING, ['settings' => ['choices' => [['value' => 'g', 'label' => 'G']]]]);
    $unrated = lifecycleDraft($this->editor, 'Sicario', 'movie');

    fbkSave($rated, $this->editor, [FBK_RATING => 'pg', FBK_TAGLINE => 'First contact']);
    fbkAutosave($rated, $this->editor, [FBK_RATING => 'pg']);

    expect(fbkWorking($rated)[FBK_RATING])->toBe('pg')
        ->and(fn () => fbkSave($unrated, $this->editor, [FBK_RATING => 'pg']))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey(FBK_RATING));
});

it('still refuses a key the type has never had', function (): void {
    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie');

    expect(fn () => fbkSave($movie, $this->editor, ['01K9F1E1D0000000000000NOPE' => 'x']))
        ->toThrow(ValidationException::class, 'These fields do not exist on a movie: 01K9F1E1D0000000000000NOPE');
});

it('saves a draft with a required field empty', function (): void {
    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie');

    fbkSave($movie, $this->editor, [FBK_TAGLINE => 'First contact']);
    fbkSave($movie, $this->editor, [FBK_TAGLINE => 'First contact', FBK_RELEASE => '']);

    expect(fbkWorking($movie)[FBK_RELEASE])->toBeNull();
});

it('checks only the fields a quick edit changes', function (): void {
    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie')->refresh();

    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($movie->id, $movie->working_revision_id, (string) $this->editor->id, fields: [FBK_TAGLINE => 'Quick']));

    expect(fbkWorking($movie)[FBK_TAGLINE])->toBe('Quick');
});

it('keeps the value of a field whose stored row can’t be read, through every save', function (): void {
    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie');
    fbkSave($movie, $this->editor, [FBK_TAGLINE => 'First contact', FBK_TRAILER => 'https://a.test/t']);
    DB::table(StoredFields::TABLE)->where('key', FBK_TRAILER)->update(['definition' => '{"fieldType":"url"}']);
    app(StoredFields::class)->remember();
    app(FieldRegistry::class)->refreshStored();

    fbkSave($movie, $this->editor, fbkWorking($movie));

    expect(fbkWorking($movie))->toMatchArray([FBK_TAGLINE => 'First contact', FBK_TRAILER => 'https://a.test/t']);

    fbkSave($movie, $this->editor, [FBK_TAGLINE => 'Second contact']);

    expect(fbkWorking($movie))->toMatchArray([FBK_TAGLINE => 'Second contact', FBK_TRAILER => 'https://a.test/t'])
        ->and(app(FieldRegistry::class)->unreadable('movie'))->toBe([['key' => FBK_TRAILER, 'handle' => 'trailer_url', 'reason' => '[label] is missing.']]);
});
