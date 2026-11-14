<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\ClaimDuePublications;
use App\Domain\Contents\Operations\Input\ClaimDuePublicationsInput;
use App\Domain\Contents\Operations\Input\SaveAutosaveInput;
use App\Domain\Contents\Operations\Input\ScheduledPublicationInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\RunScheduledPublication;
use App\Domain\Contents\Operations\SaveAutosave;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Users\Actors;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('publishes what is due and leaves what is not', function (): void {
    $later = lifecycleDraft($this->author, 'Night market');
    schedFor($later, $this->editor, now()->addDay()->toIso8601String());
    $due = lifecycleDraft($this->author, 'Harbour festival');
    docSave($due, $this->author, docWith(docText('Due now.')));
    schedDue($due, $this->editor);

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    expect($due->refresh()->status)->toBe('published')
        ->and($due->published_revision_id)->toBe($due->working_revision_id)
        ->and($due->scheduled_for)->toBeNull()
        ->and(schedRow($due)->completed_at)->not->toBeNull()
        ->and($later->refresh()->status)->toBe('scheduled')
        ->and(schedRow($later)->claimed_at)->toBeNull();
});

it('runs a due publication exactly once, even when two runners reach it', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('The scheduled text.')));
    $rowId = schedDue($content, $this->editor);
    $this->app->instance(Elevation::class, new OperatorElevation);

    // Runner one claims the row. Runner two, a moment later, finds nothing.
    $claimed = app(ClaimDuePublications::class)->execute(new ClaimDuePublicationsInput(50));
    $second = app(ClaimDuePublications::class)->execute(new ClaimDuePublicationsInput(50));

    // Suppose both still reach the Operation. The first publishes.
    $input = new ScheduledPublicationInput($rowId, (string) $this->editor->id);
    app(RunScheduledPublication::class)->execute($input);
    $completedAt = schedRow($content)->completed_at;

    // A newer revision is published by hand. A second run of the same row
    // would put the scheduled revision back; it must do nothing at all.
    docSave($content->refresh(), $this->author, docWith(docText('A later correction.')));
    listsPublish($content->refresh(), $this->editor);
    $manual = $content->refresh()->published_revision_id;
    $this->travel(1)->minutes();
    app(RunScheduledPublication::class)->execute($input);

    expect(array_column($claimed, 'id'))->toBe([$rowId])
        ->and($second)->toBe([])
        ->and(schedRow($content)->completed_at)->toBe($completedAt)
        ->and(schedRow($content)->attempts)->toBe(1)
        ->and($content->refresh()->published_revision_id)->toBe($manual);
});

it('reclaims a claim left for more than ten minutes, and not sooner', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $rowId = schedDue($content, $this->editor);
    $this->app->instance(Elevation::class, new OperatorElevation);
    $claim = fn (): array => app(ClaimDuePublications::class)->execute(new ClaimDuePublicationsInput(50));

    // A runner claims the row, then dies before running it.
    $claim();
    $this->travel(5)->minutes();
    $early = $claim();
    $this->travel(6)->minutes();
    $late = $claim();

    expect($early)->toBe([])
        ->and(array_column($late, 'id'))->toBe([$rowId])
        ->and(schedRow($content)->attempts)->toBe(2);
});

it('claims nothing outside the operator\'s console, and writes nothing', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    schedDue($content, $this->editor);
    $this->app->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(ClaimDuePublications::class)->execute(new ClaimDuePublicationsInput(50)))
        ->toThrow(AuthorizationException::class)
        ->and(schedRow($content)->claimed_at)->toBeNull()
        ->and(schedRow($content)->attempts)->toBe(0);
});

it('publishes a window that was missed, and records that it was late', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Late, but there.')));
    schedFor($content, $this->editor, now()->addMinute()->toIso8601String());

    // The server was down for two hours.
    $this->travel(2)->hours();

    $this->artisan('teme:content:run-due-publications')
        ->expectsOutputToContain('late')
        ->assertSuccessful();

    $row = schedRow($content);

    expect($content->refresh()->status)->toBe('published')
        ->and(CarbonImmutable::parse($row->run_at)->diffInMinutes(CarbonImmutable::parse($row->completed_at)))->toBeGreaterThanOrEqual(119);
});

it('holds the row when the person who scheduled it may no longer publish', function (): void {
    Log::spy();
    $content = lifecycleDraft($this->author, 'Harbour festival');
    schedFor($content, $this->editor, now()->addMinute()->toIso8601String());
    $this->editor->syncRoles(['contributor']);
    $this->travel(2)->minutes();

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();
    $this->travel(20)->minutes();
    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    $row = schedRow($content);

    expect($content->refresh()->status)->toBe('scheduled')
        ->and($content->published_revision_id)->toBeNull()
        ->and($row->held_reason)->toBe('scheduler_cannot_publish')
        ->and($row->claimed_at)->toBeNull()
        ->and($row->attempts)->toBe(1);

    Log::shouldHaveReceived('warning')->once();
});

it('holds an item whose scheduler was removed, and says so on the Scheduled tab', function (): void {
    Log::spy();
    $content = lifecycleDraft($this->author, 'Harbour festival');
    schedFor($content, $this->editor, now()->addMinute()->toIso8601String());

    // What DeleteUser leaves behind: the foreign key sets created_by to null.
    $this->editor->delete();
    $this->travel(2)->minutes();

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();
    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    expect($content->refresh()->status)->toBe('scheduled')
        ->and($content->published_revision_id)->toBeNull()
        ->and(schedRow($content)->held_reason)->toBe('scheduler_removed');

    Log::shouldHaveReceived('warning')->once();

    $this->actingAs($this->admin)->get('/admin/posts?tab=scheduled')
        ->assertInertia(fn ($page) => $page->where('scheduleWarning', ["1 scheduled item can't publish: the account of the person who scheduled them was removed. Reschedule it."]));
});

it('records a failure with its reason and does not retry it', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    schedDue($content, $this->editor);
    $this->app->bind(PublishRevision::class, fn () => throw new RuntimeException('The search index is unavailable.'));

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();
    // Past the ten-minute reclaim: a failed row is still not picked up.
    $this->travel(20)->minutes();
    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    $row = schedRow($content);

    expect($row->failed_at)->not->toBeNull()
        ->and($row->failure_reason)->toBe('The search index is unavailable.')
        ->and($row->completed_at)->toBeNull()
        ->and($row->attempts)->toBe(1)
        ->and($content->refresh()->status)->toBe('scheduled');
});

it('publishes as the scheduler, never as the operator or anyone else', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $rowId = schedDue($content, $this->editor);
    $this->app->instance(Elevation::class, new OperatorElevation);

    // The operator's console is nobody: it cannot publish on its own authority.
    expect(fn () => app(RunScheduledPublication::class)->execute(new ScheduledPublicationInput($rowId, null)))
        ->toThrow(AuthorizationException::class);
    // Nor can anyone other than the scheduler, however senior.
    expect(fn () => app(RunScheduledPublication::class)->execute(new ScheduledPublicationInput($rowId, (string) $this->admin->id)))
        ->toThrow(AuthorizationException::class);

    expect(schedRow($content)->completed_at)->toBeNull()
        ->and($content->refresh()->published_revision_id)->toBeNull();

    app(RunScheduledPublication::class)->execute(new ScheduledPublicationInput($rowId, (string) $this->editor->id));

    expect($content->refresh()->status)->toBe('published');
});

it('fails the row for good when the article was trashed without cancelling it', function (): void {
    // TrashContent cancels the schedule (SchedulingTest). A row can still meet a
    // trashed item when the trash came from elsewhere, as a restored backup can.
    $content = lifecycleDraft($this->author, 'Harbour festival');
    schedDue($content, $this->editor);
    DB::table('contents')->where('id', $content->id)->update(['deleted_at' => now()]);

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();
    $this->travel(20)->minutes();
    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    $row = schedRow($content);

    expect($row->failed_at)->not->toBeNull()
        ->and($row->failure_reason)->toBe('trashed')
        ->and($row->attempts)->toBe(1)
        ->and(DB::table('contents')->where('id', $content->id)->value('published_revision_id'))->toBeNull();
});

it('keeps an autosave typed after scheduling when the schedule runs', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('The scheduled text.')));
    schedFor($content, $this->editor, now()->addMinute()->toIso8601String());
    $scheduled = $content->refresh()->working_revision_id;

    // The scheduler keeps writing: an autosave based on the scheduled revision,
    // which a publish that clears autosaves would delete.
    app(SaveAutosave::class)->execute(new SaveAutosaveInput(
        $content->id, 'Harbour festival', docWith(docText('Still writing.')), [], [], $scheduled, (string) $this->editor->id,
    ));
    $this->travel(2)->minutes();

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    expect($content->refresh()->published_revision_id)->toBe($scheduled)
        ->and(DB::table('content_autosaves')->where('content_id', $content->id)->where('user_id', $this->editor->id)->count())->toBe(1);
});

it('loads each scheduler, with their roles and permissions, once a pass (B-200)', function (): void {
    foreach (['Tide tables', 'Ferry times', 'Harbour dues'] as $title) {
        $item = lifecycleDraft($this->author, $title);
        docSave($item, $this->author, docWith(docText('Due now.')));
        schedFor($item, $this->editor, now()->addMinute()->toIso8601String());
    }

    $this->travel(2)->minutes();
    DB::enableQueryLog();

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    $roleLoads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'inner join "model_has_roles"'));

    expect(DB::table('contents')->where('status', 'published')->count())->toBe(3)
        ->and($roleLoads)->toHaveCount(1);
});

it('keeps no one once the pass is done (B-200)', function (): void {
    $item = lifecycleDraft($this->author, 'Tide tables');
    docSave($item, $this->author, docWith(docText('Due now.')));
    schedDue($item, $this->editor);

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();
    DB::enableQueryLog();

    app(Actors::class)->find((string) $this->editor->id);

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "users"')))->toHaveCount(1);
});

it('applies a permission revoked after a pass from the next pass (B-200)', function (): void {
    $first = lifecycleDraft($this->author, 'First tide');
    docSave($first, $this->author, docWith(docText('Due now.')));
    schedDue($first, $this->editor);

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    $second = lifecycleDraft($this->author, 'Second tide');
    docSave($second, $this->author, docWith(docText('Due now.')));
    schedDue($second, $this->editor);
    $this->editor->syncRoles(['contributor']);

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    expect($first->refresh()->status)->toBe('published')
        ->and($second->refresh()->status)->toBe('scheduled')
        ->and(schedRow($second)->held_reason)->toBe('scheduler_cannot_publish');
});
