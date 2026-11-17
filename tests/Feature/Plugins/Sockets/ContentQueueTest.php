<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\QueuedItem;
use Teme\Contracts\Content\QueueQuery;
use Teme\Contracts\Content\ReadRefused;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('librarian');

    $this->reader = fn (): ContentReader => app(PluginDoors::class)
        ->for('tests/librarian', 'librarian', ['content.read'], [])[ContentReader::class];
});

afterEach(function (): void {
    PluginFixtures::forget();
    app(CurrentJobActor::class)->clear();
});

/**
 * Every title the queue lists for this query and person, page after page.
 *
 * @return array{titles: list<string>, pages: int}
 */
function cqAll(ContentReader $reader, QueueQuery $query, string $personId): array
{
    $titles = [];
    $pages = 0;
    $after = null;

    do {
        $page = $reader->queue(new QueueQuery($query->statuses, $query->types, $after, $query->limit), $personId);
        $titles = [...$titles, ...array_map(fn (QueuedItem $queued): string => $queued->item->title, $page->items)];
        $after = $page->next;
        $pages++;
    } while ($after !== null && $pages < 20);

    return ['titles' => $titles, 'pages' => $pages];
}

it('gives a draft’s status and when it is scheduled for', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $item = lifecycleDraft($editor, 'Harbour');
    $when = CarbonImmutable::now()->addDays(2)->startOfMinute();
    schedFor($item, $editor, $when->toIso8601String());
    app(CurrentJobActor::class)->set(JobActor::person($editor->id));

    $draft = ($this->reader)()->draft($item->id, (string) $editor->id);

    expect($draft->item->status)->toBe('scheduled')
        ->and($draft->scheduledFor?->format(DATE_ATOM))->toBe($when->utc()->format(DATE_ATOM))
        ->and($draft->scheduledFor?->getTimezone()->getName())->toBe('UTC');
});

it('lists the unpublished items a person may edit, by status, in pages whose cursors chain to the end', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $author = User::factory()->create()->assignRole('author');
    $when = CarbonImmutable::now()->addDays(3)->startOfMinute();

    lifecycleDraft($author, 'Author draft');
    schedSubmit(lifecycleDraft($author, 'Author review'), $author);
    lifecycleDraft($editor, 'Editor draft');
    schedFor(lifecycleDraft($editor, 'Editor scheduled'), $editor, $when->toIso8601String());
    addrPublished($editor, 'Editor published');
    app(TrashContent::class)->execute(new ContentActionInput(lifecycleDraft($editor, 'Editor trashed')->id, (string) $editor->id));

    app(CurrentJobActor::class)->set(JobActor::person($editor->id));
    $all = cqAll(($this->reader)(), new QueueQuery(limit: 2), (string) $editor->id);
    $scheduled = ($this->reader)()->queue(new QueueQuery(['scheduled']), (string) $editor->id);

    app(CurrentJobActor::class)->set(JobActor::person($author->id));
    $own = cqAll(($this->reader)(), new QueueQuery, (string) $author->id);
    $review = cqAll(($this->reader)(), new QueueQuery(['review']), (string) $author->id);

    expect($all)->toBe(['titles' => ['Author draft', 'Author review', 'Editor draft', 'Editor scheduled'], 'pages' => 2])
        ->and(array_map(fn (QueuedItem $queued): array => [$queued->item->title, $queued->item->status, $queued->scheduledFor?->format(DATE_ATOM)], $scheduled->items))
        ->toBe([['Editor scheduled', 'scheduled', $when->utc()->format(DATE_ATOM)]])
        ->and($scheduled->next)->toBeNull()
        ->and($own['titles'])->toBe(['Author draft', 'Author review'])
        ->and($review['titles'])->toBe(['Author review']);
});

it('refuses the queue for another person or the operator', function (Closure $actor): void {
    $editor = User::factory()->create()->assignRole('editor');
    $author = User::factory()->create()->assignRole('author');
    app(CurrentJobActor::class)->set($actor($editor));

    expect(fn () => ($this->reader)()->queue(new QueueQuery, (string) $author->id))
        ->toThrow(ReadRefused::class, 'A plugin writes only for the person who started this work.');
})->with([
    'another person' => [fn (User $editor): JobActor => JobActor::person($editor->id)],
    'the operator' => [function (): JobActor {
        app()->instance(Elevation::class, new OperatorElevation);
        $actor = JobActor::operator();
        app()->forgetInstance(Elevation::class);

        return $actor;
    }],
]);

it('refuses a status, cursor or limit outside the queue’s bounds', function (QueueQuery $query): void {
    $editor = User::factory()->create()->assignRole('editor');
    app(CurrentJobActor::class)->set(JobActor::person($editor->id));

    expect(fn () => ($this->reader)()->queue($query, (string) $editor->id))->toThrow(InvalidArgumentException::class);
})->with([
    'a published status' => [new QueueQuery(['published'])],
    'no status' => [new QueueQuery([])],
    'a cursor it didn’t give' => [new QueueQuery(after: 'bm90LWEtY3Vyc29y')],
    'a limit of 0' => [new QueueQuery(limit: 0)],
    'a limit of 201' => [new QueueQuery(limit: 201)],
    'eleven types' => [new QueueQuery(types: array_fill(0, 11, 'post'))],
]);
