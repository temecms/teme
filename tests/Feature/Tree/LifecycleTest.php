<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\Operations\ApproveReview;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\ReviewDecisionInput;
use App\Domain\Contents\Operations\Input\ScheduledPublicationInput;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Contents\Operations\Input\SubmitForReviewInput;
use App\Domain\Contents\Operations\Input\UpdateImportedContentInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Contents\Operations\RunScheduledPublication;
use App\Domain\Contents\Operations\SchedulePublication;
use App\Domain\Contents\Operations\SubmitForReview;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Contents\Operations\UpdateImportedContent;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Contents\Tree\SubtreeMoves;
use App\Domain\Contents\Tree\SubtreeOutcome;
use App\Domain\Contents\Visibility;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
    app()->forgetScopedInstances();
});

/**
 * Publishes the item's working revision as the actor.
 */
function cyclePublish(Content $item, User $actor): void
{
    $item = Content::withTrashed()->findOrFail($item->id);
    app(PublishRevision::class)->execute(new PublishRevisionInput($item->id, (string) $item->working_revision_id, (string) $actor->id));
}

function cycleVisibility(Content $item, string $visibility, User $actor): void
{
    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($item->id, $visibility, null, (string) $actor->id));
}

function cycleAct(string $operation, Content $item, User $actor): void
{
    app($operation)->execute(new ContentActionInput($item->id, (string) $actor->id));
}

/**
 * Every row of the item's addresses, current first.
 *
 * @return list<array{path: string, is_current: bool}>
 */
function cycleRows(Content $item): array
{
    return DB::table('paths')->where('target_type', 'content')->where('target_id', $item->id)->orderByDesc('is_current')->orderBy('path')->get(['path', 'is_current'])
        ->map(fn (object $row): array => ['path' => (string) $row->path, 'is_current' => (bool) $row->is_current])->all();
}

/**
 * What a refused change must leave as it was.
 *
 * @return array<string, mixed>
 */
function cycleState(): array
{
    return [
        'contents' => DB::table('contents')->orderBy('id')->get(['id', 'status', 'visibility', 'deleted_at', 'published_revision_id', 'updated_at'])->map(fn (object $row): array => (array) $row)->all(),
        'paths' => DB::table('paths')->orderBy('path')->get(['path', 'target_id', 'is_current'])->map(fn (object $row): array => (array) $row)->all(),
    ];
}

/**
 * The statements the work runs.
 *
 * @return list<string>
 */
function cycleStatements(Closure $work): array
{
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });
    $work();

    return $statements;
}

/**
 * A SubtreeAddresses that does nothing, to count what the tree costs.
 */
function cycleNothing(): void
{
    app()->instance(SubtreeAddresses::class, new class
    {
        public function lock(?string $type): bool
        {
            return false;
        }

        public function hasChildren(Content $item): bool
        {
            return false;
        }

        public function childChain(Content $item): string
        {
            return '';
        }

        public function clash(Content $item, string $chainAfter): ?string
        {
            return null;
        }

        public function follow(Content $item, string $chainBefore, ?string $actorId): SubtreeOutcome
        {
            return new SubtreeOutcome;
        }
    });
}

/**
 * The statements a publish of a new item of the type runs, the first time
 * or again.
 */
function cycleCost(User $editor, string $type, bool $again): int
{
    static $n = 0;
    $n++;
    $item = lifecycleDraft($editor, "Counted {$n}", $type);

    if ($again) {
        cyclePublish($item, $editor);
    }

    return count(cycleStatements(fn () => cyclePublish($item, $editor)));
}

it('moves the pages under a page when it is published, to under it', function (): void {
    cyclePublish($this->tree->services, $this->tree->editor);

    $response = addrGet('/web/');

    expect(addrCurrent('content', $this->tree->web->id))->toBe('services/web')
        ->and($response->getStatusCode())->toBe(301)
        ->and(addrLocation($response))->toBe('/services/web/');
});

it('moves them out from under it when it is unpublished, trashed or made private, and back when it is restored or made public', function (Closure $off, Closure $on): void {
    $off($this->tree);

    $response = addrGet('/about/history/');

    expect(addrCurrent('content', $this->tree->history->id))->toBe('history')
        ->and($response->getStatusCode())->toBe(301)
        ->and(addrLocation($response))->toBe('/history/');

    $on($this->tree);

    $response = addrGet('/history/');

    expect(addrCurrent('content', $this->tree->history->id))->toBe('about/history')
        ->and($response->getStatusCode())->toBe(301)
        ->and(addrLocation($response))->toBe('/about/history/')
        ->and(cycleRows($this->tree->history))->toBe([['path' => 'about/history', 'is_current' => true], ['path' => 'history', 'is_current' => false]]);
})->with([
    'unpublished, published' => [
        fn (PageTree $tree) => cycleAct(UnpublishContent::class, $tree->about, $tree->editor),
        fn (PageTree $tree) => cyclePublish($tree->about, $tree->editor),
    ],
    'trashed, restored' => [
        fn (PageTree $tree) => cycleAct(TrashContent::class, $tree->about, $tree->editor),
        fn (PageTree $tree) => cycleAct(RestoreContent::class, $tree->about, $tree->editor),
    ],
    'private, public' => [
        fn (PageTree $tree) => cycleVisibility($tree->about, 'private', $tree->editor),
        fn (PageTree $tree) => cycleVisibility($tree->about, 'public', $tree->editor),
    ],
]);

it('refuses a publish or making it public that would put a page under it where something else is, and writes nothing', function (Closure $case, string $field, string $words): void {
    $change = $case($this->tree);
    $before = cycleState();

    expect($change)->toThrow(fn (ValidationException $exception) => expect($exception->errors()[$field])->toBe([$words]));

    expect(cycleState())->toBe($before);
})->with([
    'a publish' => [function (PageTree $tree): Closure {
        DB::table('paths')->where('target_id', $tree->teamPost->id)->where('is_current', true)->update(['path' => 'services/web']);

        return fn () => cyclePublish($tree->services, $tree->editor);
    }, 'slug', 'Its page “Web” would move to /services/web/, which is used by the post "Team".'],
    'making it public' => [function (PageTree $tree): Closure {
        DB::table('paths')->where('target_id', $tree->teamPost->id)->where('is_current', true)->update(['path' => 'about/secret/secret-child']);

        return fn () => cycleVisibility($tree->secret, 'public', $tree->editor);
    }, 'visibility', 'Its page “Secret child” would move to /about/secret/secret-child/, which is used by the post "Team".'],
]);

it('never refuses a trash, an unpublish or making it private on account of the pages under it', function (Closure $off): void {
    // The post "Team" is at team, where the page Team would go.
    $off($this->tree);

    expect(addrCurrent('content', $this->tree->team->id))->toBe('about/team')
        ->and(addrCurrent('content', $this->tree->history->id))->toBe('history')
        ->and(array_column(app(SubtreeMoves::class)->kept(), 'title'))->toBe(['Team']);
})->with([
    'trash' => [fn (PageTree $tree) => cycleAct(TrashContent::class, $tree->about, $tree->editor)],
    'unpublish' => [fn (PageTree $tree) => cycleAct(UnpublishContent::class, $tree->about, $tree->editor)],
    'private' => [fn (PageTree $tree) => cycleVisibility($tree->about, 'private', $tree->editor)],
]);

it('takes the tree lock only for a hierarchical type, costing a post nothing', function (): void {
    $real = [cycleCost($this->tree->editor, 'post', false), cycleCost($this->tree->editor, 'post', true)];
    cycleNothing();

    expect($real)->toBe([cycleCost($this->tree->editor, 'post', false), cycleCost($this->tree->editor, 'post', true)]);
});

it('costs a page with no children two statements more', function (): void {
    $real = [cycleCost($this->tree->editor, 'page', false), cycleCost($this->tree->editor, 'page', true)];
    cycleNothing();
    $without = [cycleCost($this->tree->editor, 'page', false), cycleCost($this->tree->editor, 'page', true)];

    // Published again: the lock and the look for children. The first time,
    // validate() also looks, for pages the publish would move onto another's address.
    expect([$real[0] - $without[0], $real[1] - $without[1]])->toBe([3, 2]);
});

it('keeps, never fails, a scheduled publish whose pages under it would clash', function (): void {
    DB::table('paths')->where('target_id', $this->tree->teamPost->id)->where('is_current', true)->update(['path' => 'services/web']);
    app(SchedulePublication::class)->execute(new SchedulePublicationInput($this->tree->services->id, (string) $this->tree->services->working_revision_id, 'publish', now()->addHour()->toIso8601String(), (string) $this->tree->editor->id));
    $schedule = ScheduledPublication::query()->where('content_id', $this->tree->services->id)->firstOrFail();

    $result = app(RunScheduledPublication::class)->execute(new ScheduledPublicationInput($schedule->id, (string) $this->tree->editor->id));

    expect($result['published'])->toBeTrue()
        ->and($this->tree->services->refresh()->status)->toBe('published')
        ->and(addrCurrent('content', $this->tree->web->id))->toBe('web')
        ->and(array_column(app(SubtreeMoves::class)->kept(), 'title'))->toBe(['Web']);
});

it('takes the tree lock before the row in a scheduled publish and an approval', function (Closure $case): void {
    $statements = cycleStatements($case($this->tree));
    $lock = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock'));
    $row = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'for update'));

    expect($lock)->toBeInt()
        ->and($row)->toBeInt()
        ->and($lock)->toBeLessThan($row);
})->with([
    'a scheduled publish' => [function (PageTree $tree): Closure {
        app(SchedulePublication::class)->execute(new SchedulePublicationInput($tree->services->id, (string) $tree->services->working_revision_id, 'publish', now()->addHour()->toIso8601String(), (string) $tree->editor->id));
        $schedule = ScheduledPublication::query()->where('content_id', $tree->services->id)->firstOrFail();

        return fn () => app(RunScheduledPublication::class)->execute(new ScheduledPublicationInput($schedule->id, (string) $tree->editor->id));
    }],
    'an approval' => [function (PageTree $tree): Closure {
        app(SubmitForReview::class)->execute(new SubmitForReviewInput($tree->services->id, (string) $tree->services->working_revision_id, (string) $tree->editor->id));
        $review = DB::table('content_reviews')->where('content_id', $tree->services->id)->value('id');

        return fn () => app(ApproveReview::class)->execute(new ReviewDecisionInput((string) $review, (string) $tree->admin->id));
    }],
]);

it('moves the pages under a page a catch-up import makes private, trashes or renames', function (array $changes, ?string $people): void {
    $imported = ContentImports::import(ContentImports::item($this->tree->editor, ['type' => 'page', 'title' => 'Imported', 'slug' => 'imported', 'wordpressPath' => 'imported']));
    $parent = Content::withTrashed()->findOrFail($imported['contentId']);
    PageTree::place($this->tree->people, $parent);

    expect(addrCurrent('content', $this->tree->people->id))->toBe('imported/people');

    $item = ContentImports::item($this->tree->editor, ['type' => 'page', 'title' => 'Imported', 'slug' => 'imported', 'wordpressPath' => 'imported', 'modifiedAt' => CarbonImmutable::parse('2019-06-01 10:00:00', 'UTC'), ...$changes]);
    scElevated(fn () => app(UpdateImportedContent::class)->execute(new UpdateImportedContentInput($parent->id, $imported['written'], $item, null)));

    expect(addrCurrent('content', $this->tree->people->id))->toBe($people);
})->with([
    'made private' => [['visibility' => Visibility::Private], 'people'],
    'trashed' => [['trashedAt' => CarbonImmutable::parse('2019-06-01 10:00:00', 'UTC')], 'people'],
    'renamed' => [['slug' => 'brought-in'], 'brought-in/people'],
]);
