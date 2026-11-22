<?php

declare(strict_types=1);

use App\Domain\Inbox\InboxLink;
use App\Domain\Inbox\Operations\Input\MarkInboxReadInput;
use App\Domain\Inbox\Operations\Input\PruneInboxInput;
use App\Domain\Inbox\Operations\Input\SendToInboxInput;
use App\Domain\Inbox\Operations\MarkInboxRead;
use App\Domain\Inbox\Operations\PruneInbox;
use App\Domain\Inbox\Operations\SendToInbox;
use App\Domain\Inbox\Queries\InboxFor;
use App\Domain\Support\Queries\Cursor;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Jobs\JobsFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->ada = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->grace = User::factory()->create(['name' => 'Grace Editor'])->assignRole('editor');
});

it('sends an item to a person’s inbox, unread, its words and link kept as written (I-221)', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);

    $id = app(SendToInbox::class)->execute(new SendToInboxInput(
        recipientId: (string) $this->ada->id,
        kind: 'job.finished',
        title: 'Finished: Import from WordPress',
        text: '1,204 posts came in.',
        link: new InboxLink('tools.page-cache.show', [], 'page-cache.clear'),
    ));

    $row = DB::table('inbox_items')->where('id', $id)->first();

    expect($row->user_id)->toBe($this->ada->id)
        ->and($row->kind)->toBe('job.finished')
        ->and($row->owner)->toBe('core')
        ->and($row->read_at)->toBeNull()
        ->and(json_decode((string) $row->body, true))->toEqual([
            'title' => 'Finished: Import from WordPress',
            'text' => '1,204 posts came in.',
            'link' => ['route' => 'tools.page-cache.show', 'parameters' => [], 'ability' => 'page-cache.clear'],
        ]);
});

it('sends nothing for no one, and writes nothing when authorization fails', function (string $case): void {
    $actor = match ($case) {
        'no actor, and no operator' => null,
        'an actor who isn’t a person' => '999999',
    };

    expect(fn () => app(SendToInbox::class)->execute(new SendToInboxInput((string) $this->ada->id, 'job.finished', 'Finished: Import from WordPress', actorId: $actor)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('inbox_items')->count())->toBe(0);
})->with(['no actor, and no operator', 'an actor who isn’t a person']);

it('refuses a link to a route that doesn’t exist, so a mistyped name fails where it’s written', function (): void {
    expect(fn () => app(SendToInbox::class)->execute(new SendToInboxInput(
        recipientId: (string) $this->ada->id,
        kind: 'job.finished',
        title: 'Finished: Import from WordPress',
        link: new InboxLink('tools.pagecache.show'),
        actorId: (string) $this->grace->id,
    )))->toThrow(ValidationException::class)
        ->and(DB::table('inbox_items')->count())->toBe(0);
});

it('marks one item read, and only the reader’s own', function (): void {
    $first = JobsFixtures::inboxItem($this->ada->id);
    $second = JobsFixtures::inboxItem($this->ada->id);

    $marked = app(MarkInboxRead::class)->execute(new MarkInboxReadInput((string) $this->ada->id, $first));

    expect($marked)->toBe(1)
        ->and(DB::table('inbox_items')->where('id', $first)->value('read_at'))->not->toBeNull()
        ->and(DB::table('inbox_items')->where('id', $second)->value('read_at'))->toBeNull();
});

it('refuses to mark someone else’s item read, and writes nothing', function (): void {
    $adas = JobsFixtures::inboxItem($this->ada->id);

    expect(fn () => app(MarkInboxRead::class)->execute(new MarkInboxReadInput((string) $this->grace->id, $adas)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('inbox_items')->where('id', $adas)->value('read_at'))->toBeNull();
});

it('marks all of the reader’s items read, and no one else’s', function (): void {
    JobsFixtures::inboxItem($this->ada->id);
    JobsFixtures::inboxItem($this->ada->id);
    JobsFixtures::inboxItem($this->ada->id, ['read_at' => now()->subDay()]);
    $graces = JobsFixtures::inboxItem($this->grace->id);

    expect(app(MarkInboxRead::class)->execute(new MarkInboxReadInput((string) $this->ada->id)))->toBe(2)
        ->and(DB::table('inbox_items')->where('user_id', $this->ada->id)->whereNull('read_at')->count())->toBe(0)
        ->and(DB::table('inbox_items')->where('id', $graces)->value('read_at'))->toBeNull();
});

it('pages a person’s inbox newest first by keyset, and shows none of anyone else’s (I-140)', function (): void {
    $ids = [];

    foreach (range(1, 5) as $hoursAgo) {
        $ids[] = JobsFixtures::inboxItem($this->ada->id, ['created_at' => now()->subHours($hoursAgo), 'body' => ['title' => "Item {$hoursAgo}", 'text' => null, 'link' => null]]);
    }

    JobsFixtures::inboxItem($this->grace->id, ['created_at' => now()->subMinutes(30)]);
    $inbox = app(InboxFor::class);

    $first = $inbox->page($this->ada, null, null, 2);
    $second = $inbox->page($this->ada, $first['older'], null, 2);
    $back = $inbox->page($this->ada, null, $second['newer'], 2);
    $last = $inbox->page($this->ada, $second['older'], null, 2);

    expect(array_column($first['rows'], 'title'))->toBe(['Item 1', 'Item 2'])
        ->and($first['newer'])->toBeNull()
        ->and(array_column($second['rows'], 'id'))->toBe([$ids[2], $ids[3]])
        ->and(array_column($back['rows'], 'title'))->toBe(['Item 1', 'Item 2'])
        ->and(array_column($last['rows'], 'title'))->toBe(['Item 5'])
        ->and($last['older'])->toBeNull()
        ->and($first['rows'][0])->toMatchArray(['text' => null, 'href' => null, 'read' => false, 'owner' => 'core']);
});

it('shows the first page for a cursor someone edited', function (string $case): void {
    JobsFixtures::inboxItem($this->ada->id);

    $cursor = match ($case) {
        'not a cursor' => 'not-a-cursor',
        'a cursor with no moment' => Cursor::encode(['yesterday', '01k9zq5s1d4x2m7n8p0r3t6v9w']),
        'a cursor with no ID' => Cursor::encode(['2026-11-24 10:00:00+00', 'DROP TABLE inbox_items']),
    };

    expect(app(InboxFor::class)->page($this->ada, $cursor, null, 50)['rows'])->toHaveCount(1)
        ->and(app(InboxFor::class)->page($this->ada, null, $cursor, 50)['rows'])->toHaveCount(1);
})->with(['not a cursor', 'a cursor with no moment', 'a cursor with no ID']);

it('draws a link the reader can’t follow as plain text', function (): void {
    $link = fn (string $route, array $parameters = [], ?string $ability = null): array => ['title' => $route, 'text' => null, 'link' => ['route' => $route, 'parameters' => $parameters, 'ability' => $ability]];

    foreach ([
        $link('tools.page-cache.show', [], 'page-cache.clear'),
        $link('tools.redirects.index', [], 'redirects.manage'),
        $link('tools.gone.show'),
        $link('tools.redirects.update'),
    ] as $minutes => $body) {
        JobsFixtures::inboxItem($this->ada->id, ['body' => $body, 'created_at' => now()->subMinutes($minutes)]);
        JobsFixtures::inboxItem($this->grace->id, ['body' => $body, 'created_at' => now()->subMinutes($minutes)]);
    }

    $hrefs = fn (User $person): array => array_column(app(InboxFor::class)->page($person, null, null, 50)['rows'], 'href', 'title');

    expect($hrefs($this->ada))->toBe([
        'tools.page-cache.show' => '/admin/tools/page-cache',
        'tools.redirects.index' => '/admin/tools/redirects',
        'tools.gone.show' => null,
        'tools.redirects.update' => null,
    ])->and($hrefs($this->grace))->toBe([
        'tools.page-cache.show' => null,
        'tools.redirects.index' => '/admin/tools/redirects',
        'tools.gone.show' => null,
        'tools.redirects.update' => null,
    ]);
});

it('counts a person’s unread items up to 100, so the count costs the same however many there are', function (): void {
    DB::insert(
        "INSERT INTO inbox_items (id, user_id, kind, owner, body, created_at) SELECT lpad(g::text, 26, '0'), ?, 'job.finished', 'core', ?::jsonb, now() - g * interval '1 minute' FROM generate_series(1, 120) g",
        [$this->ada->id, json_encode(['title' => 'Finished: Import from WordPress'])],
    );
    JobsFixtures::inboxItem($this->grace->id);
    JobsFixtures::inboxItem($this->grace->id, ['read_at' => now()]);

    expect(app(InboxFor::class)->unread($this->ada))->toBe(100)
        ->and(app(InboxFor::class)->unread($this->grace))->toBe(1);
});

it('can page a person’s inbox and count their unread items through their own indexes', function (): void {
    // Two people's items between each other's, nine in ten read, as an inbox kept 90 days looks.
    DB::insert(
        "INSERT INTO inbox_items (id, user_id, kind, owner, body, read_at, created_at) SELECT lpad(g::text, 26, '0'), CASE WHEN g % 2 = 0 THEN ?::bigint ELSE ?::bigint END, 'job.finished', 'core', ?::jsonb, CASE WHEN g % 10 = 0 THEN NULL ELSE now() END, now() - g * interval '1 minute' FROM generate_series(1, 3000) g",
        [$this->ada->id, $this->grace->id, json_encode(['title' => 'Finished: Import from WordPress'])],
    );
    DB::statement('ANALYZE inbox_items');
    // The plans that read every row, or sort what a bitmap found, are ruled out, so the plan left shows the indexes can serve both.
    DB::statement('SET LOCAL enable_bitmapscan = off');
    DB::statement('SET LOCAL enable_sort = off');
    DB::statement('SET LOCAL enable_incremental_sort = off');

    $page = planOf(fn () => app(InboxFor::class)->page($this->ada, null, null, 50), 'inbox_items');
    $unread = genericPlanOf(fn () => app(InboxFor::class)->unread($this->ada), 'read_at IS NULL');

    expect($page)->toContain('inbox_items_user_index')->not->toContain('Seq Scan on inbox_items')->not->toContain('Sort')
        ->and($unread)->toContain('inbox_items_unread_index')->not->toContain('Seq Scan on inbox_items');
});

it('deletes items 90 days after they arrive, read or not, and keeps the rest (I-1714)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00', 'UTC'));
    JobsFixtures::inboxItem($this->ada->id, ['created_at' => now()->subDays(91)]);
    JobsFixtures::inboxItem($this->ada->id, ['created_at' => now()->subDays(91), 'read_at' => now()->subDays(90)]);
    $kept = JobsFixtures::inboxItem($this->ada->id, ['created_at' => now()->subDays(89)]);

    $this->artisan('teme:inbox:prune')->expectsOutput('Deleted 2 inbox items.')->assertSuccessful();

    expect(DB::table('inbox_items')->pluck('id')->all())->toBe([$kept]);
});

it('prunes only as the operator, and writes nothing otherwise', function (): void {
    JobsFixtures::inboxItem($this->ada->id, ['created_at' => now()->subDays(91)]);

    expect(fn () => app(PruneInbox::class)->execute(new PruneInboxInput(CarbonImmutable::now()->subDays(90), 1000, (string) $this->ada->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('inbox_items')->count())->toBe(1);
});

it('is on the schedule', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('teme:inbox:prune')->assertSuccessful();
});

it('files an item under a plugin whose code starts with a digit, not under core', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);

    $id = app(SendToInbox::class)->execute(new SendToInboxInput(
        recipientId: (string) $this->ada->id,
        kind: 'plugin-9-writer.job-failed',
        title: 'It failed',
        owner: '9-writer',
    ));

    expect(DB::table('inbox_items')->where('id', $id)->value('owner'))->toBe('9-writer')
        ->and(preg_match(SendToInbox::OWNER, '9-writer'))->toBe(1)
        ->and(preg_match(SendToInbox::OWNER, '-writer'))->toBe(0);
});
