<?php

declare(strict_types=1);

use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\Jobs\RecountContentMonthsJob;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\RecountContentMonthsInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\RecountContentMonths;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Users\Operations\DeleteUser;
use App\Domain\Users\Operations\Input\DeleteUserInput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->contributor = User::factory()->create()->assignRole('contributor');

    // The maintained month rows that hold anything, as "bucket|author|count".
    $this->months = fn (): array => DB::table('content_counts')
        ->where('type', 'post')
        ->where('bucket', 'like', '%:%')
        ->where('count', '<>', 0)
        ->get()
        ->map(fn (object $row): string => "{$row->bucket}|{$row->author_id}|{$row->count}")
        ->all();

    // The same rows worked out here from the items themselves, in the site's zone (I-3131).
    $this->truth = function (string $zone = 'UTC'): array {
        $month = fn (string $at): string => CarbonImmutable::parse($at)->setTimezone($zone)->format('Y-m');
        $rows = [];

        foreach (DB::table('contents')->where('type', 'post')->get() as $item) {
            $state = match (true) {
                $item->deleted_at !== null => 'trash',
                $item->status === 'published' && $item->visibility === 'private' => 'private',
                default => (string) $item->status,
            };
            $updated = $month((string) $item->updated_at);
            $keys = ["u:{$state}:{$updated}|0"];

            if ($item->author_id !== null) {
                $keys[] = "u:{$state}:{$updated}|{$item->author_id}";
            }

            if ($state === 'published' && $item->published_at !== null) {
                $published = $month((string) $item->published_at);
                $keys[] = "p:{$published}|0";
            }

            foreach ($keys as $key) {
                $rows[$key] = ($rows[$key] ?? 0) + 1;
            }
        }

        return array_map(fn (string $key, int $count): string => "{$key}|{$count}", array_keys($rows), array_values($rows));
    };

    $this->at = fn (string $instant): mixed => $this->travelTo(CarbonImmutable::parse($instant, 'UTC'));
});

it('counts a new draft under the month it was last changed, for everyone and for its author (I-3131)', function (): void {
    ($this->at)('2026-09-15 12:00:00');
    lifecycleDraft($this->author, 'Harbour news');

    expect(($this->months)())->toEqualCanonicalizing(['u:draft:2026-09|0|1', "u:draft:2026-09|{$this->author->id}|1"]);
});

it('moves a draft to the month of its latest save', function (): void {
    ($this->at)('2026-09-30 23:00:00');
    $draft = lifecycleDraft($this->author, 'Harbour news');
    ($this->at)('2026-10-01 08:00:00');
    docSave($draft, $this->author, docWith(docText('The tide tables for October.')));

    expect(($this->months)())->toEqualCanonicalizing(['u:draft:2026-10|0|1', "u:draft:2026-10|{$this->author->id}|1"]);
});

it('adds the month a live item was published, and takes it away when the item goes private', function (): void {
    ($this->at)('2026-09-15 12:00:00');
    $post = lifecycleDraft($this->editor, 'Harbour news');
    ($this->at)('2026-10-02 09:00:00');
    listsPublish($post, $this->editor);

    expect(($this->months)())->toEqualCanonicalizing(['p:2026-10|0|1', 'u:published:2026-10|0|1', "u:published:2026-10|{$this->editor->id}|1"]);

    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($post->id, 'private', null, (string) $this->editor->id));

    expect(($this->months)())->toEqualCanonicalizing(['u:private:2026-10|0|1', "u:private:2026-10|{$this->editor->id}|1"]);
});

it('keeps every month row equal to the items through each Operation that moves one, a month apart', function (): void {
    $at = CarbonImmutable::parse('2026-01-10 12:00:00', 'UTC');
    $step = function (Closure $change) use (&$at): void {
        $at = $at->addMonthNoOverflow();
        $this->travelTo($at);
        $change();

        expect(($this->months)())->toEqualCanonicalizing(($this->truth)());
    };

    $post = lifecycleDraft($this->contributor, 'Harbour news');
    $review = '';

    $step(fn () => docSave($post, $this->contributor, docWith(docText('A first paragraph.'))));
    $step(function () use ($post, &$review): void {
        $review = schedSubmit($post, $this->contributor);
    });
    $step(function () use (&$review): void {
        schedReturn($review, $this->editor);
    });
    $step(fn () => listsPublish($post, $this->editor));
    $step(fn () => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
        $post->id,
        (string) $post->refresh()->working_revision_id,
        (string) $this->editor->id,
        publishedAt: '2025-12-05T10:00',
    )));
    $step(fn () => app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($post->id, 'private', null, (string) $this->editor->id)));
    $step(fn () => app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($post->id, 'public', null, (string) $this->editor->id)));
    $step(fn () => app(UnpublishContent::class)->execute(new ContentActionInput($post->id, (string) $this->editor->id)));
    $step(fn () => schedFor($post, $this->editor, now()->addDays(3)->toIso8601String()));
    $step(fn () => app(TrashContent::class)->execute(new ContentActionInput($post->id, (string) $this->editor->id)));
    $step(fn () => app(RestoreContent::class)->execute(new ContentActionInput($post->id, (string) $this->editor->id)));
    $step(fn () => app(TrashContent::class)->execute(new ContentActionInput($post->id, (string) $this->editor->id)));
    $step(fn () => app(DeleteContentPermanently::class)->execute(new ContentActionInput($post->id, (string) $this->editor->id)));

    expect(($this->months)())->toBe([]);
});

it('keeps the status counts free of month rows', function (): void {
    ($this->at)('2026-09-15 12:00:00');
    $post = lifecycleDraft($this->editor, 'Harbour news');
    listsPublish($post, $this->editor);

    expect(ContentCounts::for('post'))->toBe(['published' => 1])
        ->and(ContentCounts::for('post', $this->editor->id))->toBe(['published' => 1]);
});

it('gives a deleted author\'s months to whoever receives their work, and leaves each item\'s last change where it was', function (): void {
    $this->withElevation();
    $admin = User::factory()->create()->assignRole('administrator');
    ($this->at)('2026-09-15 12:00:00');
    $draft = lifecycleDraft($this->author, 'Harbour news');
    ($this->at)('2026-10-20 12:00:00');

    app(DeleteUser::class)->execute(new DeleteUserInput((string) $this->author->id, (string) $admin->id, (string) $admin->id));

    expect((int) DB::table('contents')->where('id', $draft->id)->value('author_id'))->toBe($admin->id)
        ->and(CarbonImmutable::parse((string) DB::table('contents')->where('id', $draft->id)->value('updated_at'))->format('Y-m-d'))->toBe('2026-09-15')
        ->and(($this->months)())->toEqualCanonicalizing(['u:draft:2026-09|0|1', "u:draft:2026-09|{$admin->id}|1"]);
});

it('finds and fixes drift in the month rows, as teme:counts:rebuild does for the rest (I-141)', function (): void {
    ($this->at)('2026-09-15 12:00:00');
    lifecycleDraft($this->author, 'Harbour news');
    DB::table('content_counts')->where('bucket', 'like', 'u:%')->update(['count' => 7]);
    DB::table('content_counts')->insert(['type' => 'post', 'bucket' => 'p:2020-01', 'author_id' => 0, 'count' => 3]);

    $this->artisan('teme:counts:rebuild', ['--check' => true])->assertFailed();
    $this->artisan('teme:counts:rebuild')->assertSuccessful();

    expect(($this->months)())->toEqualCanonicalizing(($this->truth)());
});

it('takes a month bucket only in its two shapes', function (string $bucket, bool $taken): void {
    $insert = fn () => DB::table('content_counts')->insert(['type' => 'post', 'bucket' => $bucket, 'author_id' => 0, 'count' => 1]);

    if ($taken) {
        expect($insert)->not->toThrow(Throwable::class);
    } else {
        expect($insert)->toThrow(QueryException::class);
    }
})->with([
    'a month of a state' => ['u:review:2026-01', true],
    'a month published' => ['p:2026-12', true],
    'month 13' => ['u:draft:2026-13', false],
    'an unknown state' => ['u:pending:2026-01', false],
    'a published month with a state' => ['p:draft:2026-01', false],
    'another shape' => ['month:2026-01', false],
]);

it('fills the month rows for the items already there when the migration runs', function (): void {
    ($this->at)('2026-09-15 12:00:00');
    $post = lifecycleDraft($this->editor, 'Harbour news');
    listsPublish($post, $this->editor);
    ($this->at)('2026-10-03 12:00:00');
    lifecycleDraft($this->author, 'Ferry fares');

    $migration = require database_path('migrations/2027_01_25_100100_fill_month_counts.php');
    $migration->down();

    expect(DB::table('content_counts')->where('bucket', 'like', '%:%')->exists())->toBeFalse();

    $migration->up();

    expect(($this->months)())->toEqualCanonicalizing(($this->truth)());
});

it('reads a tab\'s months through their own index, among many authors\' and another type\'s rows', function (): void {
    foreach (range(1, 300) as $authorId) {
        foreach (['u:draft:2026-09', 'u:published:2026-10'] as $bucket) {
            DB::table('content_counts')->insert(['type' => 'post', 'bucket' => $bucket, 'author_id' => $authorId, 'count' => 1]);
            DB::table('content_counts')->insert(['type' => 'page', 'bucket' => $bucket, 'author_id' => $authorId, 'count' => 1]);
        }
    }
    DB::table('content_counts')->insert(['type' => 'post', 'bucket' => 'u:draft:2026-09', 'author_id' => 0, 'count' => 300]);
    DB::statement('ANALYZE content_counts');

    $plan = planOf(fn () => ContentCounts::months('post', 'drafts'), 'right(bucket, 7)');

    expect(planReadsIndex($plan, 'content_counts_months', 'content_counts'))->toBeTrue($plan);
});

it('counts each item in the month its site zone gives it (I-3131)', function (): void {
    lscrTimezone('Africa/Lagos');
    ($this->at)('2026-09-30 23:30:00');
    lifecycleDraft($this->author, 'Harbour news');

    expect(($this->months)())->toEqualCanonicalizing(['u:draft:2026-10|0|1', "u:draft:2026-10|{$this->author->id}|1"]);
});

it('counts every item again in the new zone when the time zone changes', function (): void {
    ($this->at)('2026-09-30 23:30:00');
    lifecycleDraft($this->author, 'Harbour news');

    expect(($this->months)())->toEqualCanonicalizing(['u:draft:2026-09|0|1', "u:draft:2026-09|{$this->author->id}|1"]);

    lscrTimezone('Africa/Lagos'); // the queue is synchronous in tests

    expect(($this->months)())->toEqualCanonicalizing(['u:draft:2026-10|0|1', "u:draft:2026-10|{$this->author->id}|1"])
        ->and(($this->months)())->toEqualCanonicalizing(($this->truth)('Africa/Lagos'));
});

it('queues the recount as whoever changed the time zone', function (): void {
    Queue::fake();

    lscrTimezone('Africa/Lagos'); // as an administrator it makes

    Queue::assertPushed(RecountContentMonthsJob::class, fn (RecountContentMonthsJob $job): bool => $job->actor->userId !== null
        && (bool) User::query()->find($job->actor->userId)?->hasRole('administrator'));
});

it('queues no recount when another General setting changes', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    Queue::fake();

    app()->instance(Elevation::class, new OperatorElevation);
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['site_title' => 'Harbour Weekly'], [], [], 0, (string) $admin->id));

    Queue::assertNotPushed(RecountContentMonthsJob::class);
});

it('recounts in a worker without elevation, as the person the job names', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    ($this->at)('2026-09-15 12:00:00');
    lifecycleDraft($this->author, 'Harbour news');
    DB::table('content_counts')->where('bucket', 'like', 'u:%')->update(['count' => 7]);
    $this->app->instance(Elevation::class, new DeniedElevation);

    (new RecountContentMonthsJob(JobActor::person((string) $admin->id)))->handle();

    expect(($this->months)())->toEqualCanonicalizing(($this->truth)());
});

it('lets the operator recount', function (): void {
    ($this->at)('2026-09-15 12:00:00');
    lifecycleDraft($this->author, 'Harbour news');
    DB::table('content_counts')->where('bucket', 'like', 'u:%')->delete();
    $this->withElevation();

    expect(app(RecountContentMonths::class)->execute(new RecountContentMonthsInput(null)))->toBe(['months' => 2])
        ->and(($this->months)())->toEqualCanonicalizing(($this->truth)());
});

it('writes nothing when someone without the General settings ability asks for a recount', function (): void {
    ($this->at)('2026-09-15 12:00:00');
    lifecycleDraft($this->author, 'Harbour news');
    DB::table('content_counts')->where('bucket', 'like', 'u:%')->update(['count' => 7]);

    expect(fn () => app(RecountContentMonths::class)->execute(new RecountContentMonthsInput((string) $this->editor->id)))
        ->toThrow(AuthorizationException::class)
        ->and(($this->months)())->toEqualCanonicalizing(['u:draft:2026-09|0|7', "u:draft:2026-09|{$this->author->id}|7"]);
});
