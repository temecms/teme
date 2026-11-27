<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\SchedulePublication;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Models\PluginRecord;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\BeforeSave;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\ItemChanges;
use Teme\Contracts\Content\NewItem;
use Teme\Contracts\Hooks\Veto;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->author = User::factory()->create()->assignRole('author');
    $this->draftInput = fn (string $title): CreateDraftInput => new CreateDraftInput(
        type: 'post',
        title: $title,
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        actorId: (string) $this->author->id,
    );
});

it('stops a new draft when a plugin refuses it, and shows its reason', function (): void {
    hkRecordFaults();
    hkSubscriber('acme/one')->on(BeforeSave::class, fn (BeforeSave $hook): Veto => new Veto('Every post needs a summary.'));

    expect(fn () => app(CreateDraft::class)->execute(($this->draftInput)('Refused')))
        ->toThrow(fn (ValidationException $failure) => expect($failure->errors()['title'])->toBe(['Every post needs a summary.']));

    expect(Content::query()->count())->toBe(0)
        ->and(hkFaults())->toBe([]);
});

it('stops a saved draft and a publish when a plugin refuses them, and writes nothing', function (): void {
    $content = app(CreateDraft::class)->execute(($this->draftInput)('Kept as it was'));
    hkSubscriber('acme/one')->on(BeforeSave::class, fn (BeforeSave $hook): Veto => new Veto('Not yet.'));

    expect(fn () => app(SaveDraft::class)->execute(new SaveDraftInput(
        contentId: $content->id,
        title: 'Changed',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        expectedRevisionId: $content->working_revision_id,
        actorId: (string) $this->author->id,
    )))->toThrow(fn (ValidationException $failure) => expect($failure->errors()['title'])->toBe(['Not yet.']));

    expect(fn () => app(PublishRevision::class)->execute(new PublishRevisionInput(
        contentId: $content->id,
        revisionId: $content->working_revision_id,
        actorId: (string) $this->author->id,
    )))->toThrow(fn (ValidationException $failure) => expect($failure->errors()['title'])->toBe(['Not yet.']));

    expect($content->refresh()->title)->toBe('Kept as it was')
        ->and($content->status)->toBe('draft');
});

it('lets a save through when a listener fails, and records the fault', function (): void {
    hkRecordFaults();
    hkSubscriber('acme/one')->on(BeforeSave::class, fn () => throw new RuntimeException('Spell check is down.'));

    $content = app(CreateDraft::class)->execute(($this->draftInput)('Goes ahead'));

    expect($content->title)->toBe('Goes ahead')
        ->and(array_column(hkFaults(), 'id'))->toBe(['acme/one#BeforeSave#1']);
});

it('lets a save through when a listener runs over its time, and records the fault', function (): void {
    hkRecordFaults();
    hkSubscriber('acme/one')->on(BeforeSave::class, function (): ?Veto {
        usleep(300_000);

        return new Veto('Too late to matter.');
    });

    $content = app(CreateDraft::class)->execute(($this->draftInput)('Slow check'));

    expect($content->title)->toBe('Slow check')
        ->and(array_column(hkFaults(), 'id'))->toBe(['acme/one#BeforeSave#1']);
});

it('asks plugins before the transaction opens', function (): void {
    // RefreshDatabase holds a transaction open around each test, so compare with the level the test starts at.
    $outside = DB::transactionLevel();
    $levels = [];
    hkSubscriber('acme/one')->on(BeforeSave::class, function (BeforeSave $hook) use (&$levels): ?Veto {
        $levels[] = DB::transactionLevel();

        return null;
    });

    app(CreateDraft::class)->execute(($this->draftInput)('Asked early'));

    expect($levels)->toBe([$outside]);
});

it('does not ask for an AI suggestion, a checkpoint, a plugin’s own write or an import run', function (): void {
    $content = app(CreateDraft::class)->execute(($this->draftInput)('Quiet'));
    $asked = 0;
    hkSubscriber('acme/one')->on(BeforeSave::class, function () use (&$asked): Veto {
        $asked++;

        return new Veto('Never shown.');
    });

    $save = fn (string $kind, ?string $via = null): SaveDraftInput => new SaveDraftInput(
        contentId: $content->id,
        title: 'Quiet',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        expectedRevisionId: $content->refresh()->working_revision_id,
        actorId: (string) $this->author->id,
        kind: $kind,
        via: $via,
    );

    app(SaveDraft::class)->execute($save('ai'));
    app(SaveDraft::class)->execute($save('checkpoint'));
    app(SaveDraft::class)->execute($save('save', 'acme/one'));
    $source = ImportSource::query()->create([
        'address' => 'https://old.example', 'api_root' => 'https://old.example/wp-json/', 'user_name' => 'ada',
        'credential' => 'abcd EFGH 1234 ijkl MNOP 5678', 'plugin_version' => '0.2.0', 'api_version' => 1, 'probed_at' => now(),
    ]);
    $run = ImportRun::query()->create([
        'source_id' => $source->id, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Running, 'phase' => 'users',
        'phase_token' => strtolower((string) Str::ulid()), 'high_water_at' => now(), 'started_at' => now(),
    ]);
    app(ImportMode::class)->during($run->id, fn () => app(CreateDraft::class)->execute(($this->draftInput)('Imported')));

    expect($asked)->toBe(0)
        ->and(app(HookDispatcher::class)->wants(BeforeSave::class))->toBeTrue();
});

it('stops a schedule when a plugin refuses it, and writes no schedule', function (): void {
    $content = app(CreateDraft::class)->execute(($this->draftInput)('Later'));
    hkSubscriber('acme/one')->on(BeforeSave::class, fn (BeforeSave $hook): Veto => new Veto('Not on a schedule yet.'));

    expect(fn () => app(SchedulePublication::class)->execute(new SchedulePublicationInput(
        contentId: $content->id,
        revisionId: $content->working_revision_id,
        action: 'publish',
        runAt: now()->addDay()->toAtomString(),
        actorId: (string) $this->author->id,
    )))->toThrow(fn (ValidationException $failure) => expect($failure->errors()['title'])->toBe(['Not on a schedule yet.']));

    expect(DB::table('scheduled_publications')->count())->toBe(0);
});

it('does not ask when a scheduled publish runs, because the schedule was asked when it was made', function (): void {
    $content = app(CreateDraft::class)->execute(($this->draftInput)('Scheduled'));
    hkSubscriber('acme/one')->on(BeforeSave::class, fn (BeforeSave $hook): Veto => new Veto('Never shown.'));

    $published = app(PublishRevision::class)->execute(new PublishRevisionInput(
        contentId: $content->id,
        revisionId: $content->working_revision_id,
        actorId: (string) $this->author->id,
        askBeforeSave: false,
    ));

    expect($published->status)->toBe('published');
});

describe('a plugin’s own writes', function (): void {
    beforeEach(function (): void {
        PluginFixtures::use('writer');
        PluginRecord::factory()->enabled()->create([
            'name' => 'tests/writer',
            'code' => 'writer',
            'version' => '1.0.0',
            'consent' => ['capabilities' => ['content.read', 'content.write'], 'hosts' => [], 'hooks' => []],
        ]);
    });

    afterEach(fn () => PluginFixtures::forget());

    it('are never asked, for a create, a change, a publish or a schedule', function (): void {
        Date::setTestNow('2026-11-15 12:00:00');
        $editor = User::factory()->create()->assignRole('editor');
        $this->actingAs($editor);
        $asked = 0;
        hkSubscriber('acme/one')->on(BeforeSave::class, function () use (&$asked): Veto {
            $asked++;

            return new Veto('Never shown.');
        });
        $writer = app(PluginDoors::class)->for('tests/writer', 'writer', ['content.write'], [])[ContentWriter::class];

        $item = $writer->create(new NewItem('post', 'First', ['type' => 'doc', 'content' => []]), (string) $editor->id);
        $writer->change($item, new ItemChanges(title: 'Second'), (string) $editor->id);
        $writer->publish($item, (string) $editor->id);
        $later = $writer->create(new NewItem('post', 'Later', ['type' => 'doc', 'content' => []]), (string) $editor->id);
        $writer->schedule($later, new DateTimeImmutable('2026-11-16 09:00:00'), (string) $editor->id);

        expect($asked)->toBe(0);

        Date::setTestNow();
    });
});
