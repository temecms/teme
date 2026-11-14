<?php

declare(strict_types=1);

use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\MarkTranslationUpToDateInput;
use App\Domain\Contents\Operations\Input\RestoreRevisionInput;
use App\Domain\Contents\Operations\Input\TranslateContentInput;
use App\Domain\Contents\Operations\MarkTranslationUpToDate;
use App\Domain\Contents\Operations\RestoreRevision;
use App\Domain\Contents\Operations\TranslateContent;
use App\Domain\Contents\Queries\TranslationStatus;
use App\Domain\Languages\SiteLanguages;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
    $this->source = Content::query()->findOrFail($this->site->alone);
    $this->french = app(TranslateContent::class)->execute(new TranslateContentInput($this->site->alone, 'fr', (string) $this->site->editor->id))->content;
    $this->group = (string) $this->french->translation_group;
});

/**
 * The group's state of one version, by ID.
 *
 * @return array<string, mixed>
 */
function odState(string $group, string $id): array
{
    return collect(app(TranslationStatus::class)->forGroup($group))->firstWhere('id', $id) ?? [];
}

/**
 * The source saved with new words and, if asked, published, a minute on.
 */
function odSourceChanges(Content $source, User $editor, bool $publish): void
{
    test()->travel(1)->minutes();
    docSave($source, $editor, docWith(docText('Alone, rewritten')));

    if ($publish) {
        listsPublish($source, $editor);
    }
}

function odMark(User $actor, Content $version, ?string $expected = null): Content
{
    return app(MarkTranslationUpToDate::class)->execute(new MarkTranslationUpToDateInput($version->id, $expected ?? (string) $version->refresh()->working_revision_id, (string) $actor->id));
}

it('marks a version out of date once its source publishes a newer revision', function (): void {
    expect(odState($this->group, $this->french->id)['outOfDate'])->toBeFalse();

    odSourceChanges($this->source, $this->site->editor, publish: true);
    $state = odState($this->group, $this->french->id);

    expect($state['outOfDate'])->toBeTrue()
        ->and($state['sourceId'])->toBe($this->site->alone)
        ->and($state['sourceLanguage'])->toBe('en-GB')
        ->and($state['language'])->toBe('fr')
        ->and($state['sourceChangedAt'])->not->toBeNull()
        ->and(odState($this->group, $this->site->alone)['outOfDate'])->toBeFalse();
});

it('keeps it up to date while the source only saves drafts', function (): void {
    odSourceChanges($this->source, $this->site->editor, publish: false);

    expect(odState($this->group, $this->french->id)['outOfDate'])->toBeFalse();
});

it('keeps the mark through the version\'s own saves and restores', function (): void {
    $first = (string) $this->french->working_revision_id;
    odSourceChanges($this->source, $this->site->editor, publish: true);

    docSave($this->french, $this->site->editor, docWith(docText('Seul, réécrit')));
    expect(odState($this->group, $this->french->id)['outOfDate'])->toBeTrue();

    app(RestoreRevision::class)->execute(new RestoreRevisionInput($this->french->id, $first, (string) $this->site->editor->id));
    expect(odState($this->group, $this->french->id)['outOfDate'])->toBeTrue();
});

it('never marks a version whose source is gone or in the trash', function (): void {
    odSourceChanges($this->source, $this->site->editor, publish: true);
    pageTrash($this->source, $this->site->editor);

    $state = odState($this->group, $this->french->id);

    expect($state['outOfDate'])->toBeFalse()
        ->and($state['sourceId'])->toBeNull();
});

it('marks it up to date with a new revision, leaving the others as they were', function (): void {
    $spanish = app(TranslateContent::class)->execute(new TranslateContentInput($this->site->alone, 'es', (string) $this->site->editor->id))->content;
    odSourceChanges($this->source, $this->site->editor, publish: true);
    $before = (string) $this->french->refresh()->working_revision_id;

    $marked = odMark($this->site->editor, $this->french);
    $revision = ContentRevision::query()->findOrFail($marked->working_revision_id);

    expect($marked->working_revision_id)->not->toBe($before)
        ->and($revision->kind)->toBe('checkpoint')
        ->and($revision->source_revision_id)->toBe($this->source->refresh()->published_revision_id)
        ->and($revision->document)->toBe(ContentRevision::query()->findOrFail($before)->document)
        ->and(odState($this->group, $this->french->id)['outOfDate'])->toBeFalse()
        ->and(odState($this->group, $spanish->id)['outOfDate'])->toBeTrue();
});

it('reads a whole group\'s state in one statement', function (): void {
    app(SiteLanguages::class)->all();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $states = app(TranslationStatus::class)->forGroup($this->group);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and(array_column($states, 'language'))->toBe(['en-GB', 'fr'])
        ->and(app(TranslationStatus::class)->forItem(Content::query()->findOrFail($this->site->postFr)))->toHaveCount(3)
        ->and(app(TranslationStatus::class)->forItem(Content::query()->findOrFail(LanguageSite::version($this->site->editor, 'Lonely', null, null, [], publish: false))))->toBe([]);
});

it('refuses to mark a version made on an older revision', function (): void {
    odSourceChanges($this->source, $this->site->editor, publish: true);
    $old = (string) $this->french->refresh()->working_revision_id;
    docSave($this->french, $this->site->editor, docWith(docText('Newer')));
    $before = (string) $this->french->refresh()->working_revision_id;

    expect(fn () => odMark($this->site->editor, $this->french, $old))->toThrow(StaleRevisionException::class);
    expect((string) $this->french->refresh()->working_revision_id)->toBe($before);
});

it('refuses a version whose source is gone', function (): void {
    pageTrash($this->source, $this->site->editor);

    expect(fn () => odMark($this->site->editor, $this->french))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['source'])->toBe(['The version it was translated from is gone.']);
    });
});

it('writes nothing when the person may not edit the version', function (): void {
    $author = User::factory()->create()->assignRole('author');
    odSourceChanges($this->source, $this->site->editor, publish: true);
    $before = DB::table('content_revisions')->count();

    expect(fn () => odMark($author, $this->french))->toThrow(AuthorizationException::class);
    expect(DB::table('content_revisions')->count())->toBe($before)
        ->and(odState($this->group, $this->french->id)['outOfDate'])->toBeTrue();
});
