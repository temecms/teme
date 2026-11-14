<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\Input\ScheduledPublicationInput;
use App\Domain\Contents\Operations\Input\SubmitForReviewInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\RunScheduledPublication;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\SubmitForReview;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

const FBR_RELEASE = '01K9F1E1D0000000000000RD01';
const FBR_RATING = '01K9F1E1D0000000000000RA03';

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(FBR_RELEASE, 'release_date', ['fieldType' => 'datetime', 'label' => 'Release date', 'settings' => ['dateOnly' => true], 'required' => true]), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FBR_RATING, 'rating', ['fieldType' => 'select', 'settings' => ['choices' => [['value' => 'g', 'label' => 'G']]]]), 2);
});

/**
 * Saves the movie's draft with these field values.
 *
 * @param  array<string, mixed>  $fields
 */
function fbrSave(Content $movie, User $actor, array $fields): void
{
    $movie->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $movie->title, ['type' => 'doc', 'content' => []], $fields, $movie->working_revision_id, (string) $actor->id));
}

/** The error a publish or schedule threw, as its messages, or [] when it didn't. */
function fbrErrors(Closure $run): array
{
    try {
        $run();
    } catch (ValidationException $refused) {
        return $refused->errors();
    }

    return [];
}

it('refuses to publish an item missing a required value, saying so under the field', function (): void {
    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie');

    expect(fbrErrors(fn () => listsPublish($movie, $this->editor)))->toBe(['fields.'.FBR_RELEASE => ['Fill in “Release date” to publish this movie.']])
        ->and($movie->refresh()->published_revision_id)->toBeNull();
});

it('refuses to schedule it too', function (): void {
    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie');

    expect(fbrErrors(fn () => schedFor($movie, $this->editor, now()->addDay()->toIso8601String())))->toBe(['fields.'.FBR_RELEASE => ['Fill in “Release date” to publish this movie.']])
        ->and(DB::table('scheduled_publications')->count())->toBe(0);
});

it('counts None as missing for a required choice, and publishes once it is filled', function (): void {
    StoredFieldRows::change(FBR_RATING, ['required' => true]);
    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie');
    fbrSave($movie, $this->editor, [FBR_RELEASE => '2016-11-11', FBR_RATING => '']);

    expect(fbrErrors(fn () => listsPublish($movie, $this->editor)))->toBe(['fields.'.FBR_RATING => ['Fill in “Rating” to publish this movie.']]);

    fbrSave($movie, $this->editor, [FBR_RELEASE => '2016-11-11', FBR_RATING => 'g']);
    listsPublish($movie, $this->editor);

    expect($movie->refresh()->status)->toBe('published');
});

it('publishes items saved before Required was turned on only when filled', function (): void {
    StoredFieldRows::change(FBR_RATING, ['required' => false]);
    StoredFieldRows::change(FBR_RELEASE, ['required' => false]);
    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie');
    listsPublish($movie, $this->editor);
    StoredFieldRows::change(FBR_RELEASE, ['required' => true]);

    expect(fbrErrors(fn () => listsPublish($movie, $this->editor)))->toHaveKey('fields.'.FBR_RELEASE);

    fbrSave($movie, $this->editor, [FBR_RELEASE => '2016-11-11']);
    listsPublish($movie, $this->editor);

    expect($movie->refresh()->published_revision_id)->toBe($movie->working_revision_id);
});

it('leaves submitting for review alone', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $movie = lifecycleDraft($contributor, 'Arrival', 'movie')->refresh();

    app(SubmitForReview::class)->execute(new SubmitForReviewInput($movie->id, $movie->working_revision_id, (string) $contributor->id));

    expect($movie->refresh()->status)->toBe('review');
});

it('publishes at its time an item scheduled before a field was made required', function (): void {
    StoredFieldRows::change(FBR_RELEASE, ['required' => false]);
    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie');
    $rowId = schedDue($movie, $this->editor);
    StoredFieldRows::change(FBR_RELEASE, ['required' => true]);

    app(RunScheduledPublication::class)->execute(new ScheduledPublicationInput($rowId, (string) $this->editor->id));

    expect($movie->refresh()->status)->toBe('published')
        ->and((array) ContentRevision::query()->findOrFail($movie->published_revision_id)->fields)->not->toHaveKey(FBR_RELEASE);
});

it('still checks publishing by hand after Required was turned on', function (): void {
    StoredFieldRows::change(FBR_RELEASE, ['required' => false]);
    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie')->refresh();
    schedFor($movie, $this->editor, now()->addDay()->toIso8601String());
    StoredFieldRows::change(FBR_RELEASE, ['required' => true]);

    expect(fbrErrors(fn () => app(PublishRevision::class)->execute(new PublishRevisionInput($movie->id, $movie->working_revision_id, (string) $this->editor->id))))
        ->toBe(['fields.'.FBR_RELEASE => ['Fill in “Release date” to publish this movie.']]);
});
