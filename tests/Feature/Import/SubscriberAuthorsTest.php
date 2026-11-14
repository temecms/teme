<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Input\MarkAuthorsWaitingInput;
use App\Domain\Import\Operations\MarkAuthorsWaiting;
use App\Domain\Import\Phases\Content\ContentPhase;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\Users\AuthorsWaitingStep;
use App\Domain\Import\Phases\Users\UsersPhase;
use App\Domain\Import\Queries\ImportMap;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

/*
 * I-2998: a WordPress subscriber who has published posts comes across as a
 * contributor and keeps their posts, on a fresh import and on one made
 * before the change.
 */

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->owner = User::factory()->create(['name' => 'Fallback Owner'])->assignRole('editor');
    $this->companion = importFake();
    $this->source = importSource();
});

/**
 * WordPress's subscriber 6, Grace, with one published post and WordPress's
 * phpass hash of "correct horse battery staple".
 *
 * @return array<string, mixed>
 */
function satAccount(): array
{
    return [
        'id' => 6, 'login' => 'grace', 'email' => 'grace@old.example', 'nicename' => 'grace', 'display_name' => 'Grace Writer',
        'first_name' => '', 'last_name' => '', 'description' => '', 'url' => '', 'registered' => '2020-01-02 03:04:05',
        'roles' => ['subscriber'], 'password_hash' => '$P$BTeme1234mc6/ctael236960/qKEKM1', 'published_posts' => 1,
    ];
}

/**
 * A run of the people, the step after them and the posts, as the operator,
 * over these accounts and items, with the fallback owner chosen.
 *
 * @param  list<array<string, mixed>>  $accounts
 * @param  list<array<string, mixed>>  $items
 */
function satRun(object $test, array $accounts, array $items, ImportRunKind $kind = ImportRunKind::Full): ImportRun
{
    $test->companion->items('users', $accounts)->items('content', $items);
    importPhaseAlone(app(UsersPhase::class), app(AuthorsWaitingStep::class), app(ContentPhase::class));

    return importRun($test->source, kind: $kind, choices: ['owner' => (string) $test->owner->id]);
}

/**
 * The item WordPress's post $wordpressId became.
 */
function satPost(string $sourceId, int $wordpressId): Content
{
    return Content::withTrashed()->findOrFail(ContentImports::row($sourceId, $wordpressId)?->teme_id);
}

/**
 * The person WordPress's account $wordpressId became.
 */
function satPerson(string $sourceId, int $wordpressId): User
{
    return User::query()->findOrFail(ImportMapEntry::query()->where('source_id', $sourceId)->where('object_type', 'user')->where('object_id', (string) $wordpressId)->value('teme_id'));
}

function satStatus(string $mapId): string
{
    return (string) DB::table('import_map')->where('id', $mapId)->value('status');
}

it('brings a subscriber with published posts across as a contributor who keeps their post and their author page (I-2998)', function (): void {
    $run = satRun($this, [satAccount()], [ContentImports::wpItem(12, ['author' => 6])]);
    $grace = satPerson($this->source->id, 6);

    expect($run->refresh()->state)->toBe(ImportRunState::Finished)
        ->and($grace->getRoleNames()->all())->toBe(['contributor'])
        ->and((int) satPost($this->source->id, 12)->author_id)->toBe($grace->id)
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 12)))->not->toContain('post.author-missing');

    addrGet('/author/grace/')->assertOk();
});

it('brings across a subscriber an earlier import left behind on a catch-up, and gives their posts back at the next full import, but for one changed here (I-2998)', function (): void {
    $account = satAccount();
    $items = [
        ContentImports::wpItem(12, ['author' => 6]),
        ContentImports::wpItem(13, ['author' => 6, 'slug' => 'tide-tables', 'title' => 'Tide tables', 'permalink' => 'https://old.example/tide-tables/']),
    ];

    // The earlier import, as it was before I-2998: both posts credited to the owner, and
    // the subscriber skipped, the users list's hash then leaving their count of posts out.
    $earlier = ContentImports::run($this->companion, $this->source, $items, choices: ['owner' => (string) $this->owner->id]);
    $row = ImportRecords::entry($this->source->id, $earlier->id, 'user', '6', 'skipped', ['teme_type' => null, 'source_hash' => ImportMap::hashOf($account, ['published_posts'])]);
    ImportRecords::message($earlier->id, $row, 'user.subscriber-with-posts', 'A subscriber with 1 published post: Teme has no accounts for readers yet, so they weren’t imported. Their posts need another author.');
    pageSaveDraft(satPost($this->source->id, 13), 'Tide tables, as the owner wrote them');
    $this->travel(2)->hours();

    $catchUp = satRun($this, [$account], $items, ImportRunKind::CatchUp);
    $grace = satPerson($this->source->id, 6);

    expect($catchUp->refresh()->state)->toBe(ImportRunState::Finished)
        ->and($grace->getRoleNames()->all())->toBe(['contributor'])
        // A catch-up lists only what WordPress changed, so the posts wait for a full import.
        ->and(ContentImports::row($this->source->id, 12)?->status)->toBe(ImportStatus::NeedsUpdate)
        ->and(ContentImports::row($this->source->id, 13)?->status)->toBe(ImportStatus::NeedsUpdate)
        ->and((int) satPost($this->source->id, 12)->author_id)->toBe($this->owner->id);

    $this->travel(5)->seconds();
    satRun($this, [$account], $items);
    $given = ContentImports::row($this->source->id, 12);
    $kept = ContentImports::row($this->source->id, 13);

    expect((int) satPost($this->source->id, 12)->author_id)->toBe($grace->id)
        ->and($given?->outcome)->toBe(ImportOutcome::Updated)
        ->and($given?->status)->toBe(ImportStatus::Imported)
        ->and(ContentImports::codes($given))->not->toContain('post.author-missing')
        ->and((int) satPost($this->source->id, 13)->author_id)->toBe($this->owner->id)
        ->and(ContentImports::codes($kept))->toContain('post.changed-here');

    addrGet('/author/grace/')->assertOk();
});

it('marks the posts credited to the owner as work again when the run made a person or first matched one, and only those (I-2998)', function (string $outcome, string $facts, int $marked): void {
    $earlier = ImportRecords::run($this->source->id);
    $orphan = ImportRecords::entry($this->source->id, $earlier, 'post', '12', 'created');
    ImportRecords::message($earlier, $orphan, 'post.author-missing', 'Its author on WordPress didn’t come across as a person here, so it’s credited to the person chosen to own such items.');
    $credited = ImportRecords::entry($this->source->id, $earlier, 'post', '13', 'created');
    $run = ImportRecords::run($this->source->id);
    $facts = $facts === 'matched here' ? (string) json_encode(['matched' => true, 'matched_in' => $run]) : $facts;
    ImportRecords::entry($this->source->id, $run, 'user', '6', $outcome, ['teme_type' => $outcome === 'skipped' ? null : 'user', 'facts' => $facts]);

    $count = scElevated(fn (): int => app(MarkAuthorsWaiting::class)->execute(new MarkAuthorsWaitingInput($this->source->id, $run, null)));

    expect($count)->toBe($marked)
        ->and(satStatus($orphan))->toBe($marked === 1 ? 'needs_update' : 'imported')
        ->and(satStatus($credited))->toBe('imported');
})->with([
    'a person made' => ['created', '{}', 1],
    'a person matched by their email address in this run' => ['updated', 'matched here', 1],
    'a person matched in an earlier run' => ['updated', '{"matched": true}', 0],
    'a person brought up to date' => ['updated', '{}', 0],
    'an account left behind' => ['skipped', '{}', 0],
]);

it('refuses a run of another source, writing nothing', function (): void {
    $other = ImportRecords::source(['address' => 'https://elsewhere.example']);
    $run = ImportRecords::run($other);

    expect(fn () => scElevated(fn () => app(MarkAuthorsWaiting::class)->execute(new MarkAuthorsWaitingInput($this->source->id, $run, null))))
        ->toThrow(ValidationException::class, 'That run isn’t one of this source’s.');
});

it('writes nothing when the actor may not import', function (string $who): void {
    $earlier = ImportRecords::run($this->source->id);
    $orphan = ImportRecords::entry($this->source->id, $earlier, 'post', '12', 'created');
    ImportRecords::message($earlier, $orphan, 'post.author-missing', 'Its author on WordPress didn’t come across as a person here, so it’s credited to the person chosen to own such items.');
    $run = ImportRecords::run($this->source->id);
    ImportRecords::entry($this->source->id, $run, 'user', '6', 'created', ['teme_type' => 'user']);
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => app(MarkAuthorsWaiting::class)->execute(new MarkAuthorsWaitingInput($this->source->id, $run, $who === 'an editor' ? (string) $editor->id : null)))
        ->toThrow(AuthorizationException::class)
        ->and(satStatus($orphan))->toBe('imported');
})->with(['an editor', 'no one, away from the operator’s shell']);

it('registers the step after the people and before the posts, for every kind of run', function (): void {
    $step = app(ImportPhases::class)->find('authors-waiting');

    expect($step)->toBeInstanceOf(AuthorsWaitingStep::class)
        ->and($step?->order())->toBe(210)
        ->and($step?->label())->toBe('Posts waiting for their author')
        ->and($step?->runsIn(ImportRunKind::Full))->toBeTrue()
        ->and($step?->runsIn(ImportRunKind::CatchUp))->toBeTrue();
});
