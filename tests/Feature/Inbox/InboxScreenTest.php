<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Jobs\JobsFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->ada = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->grace = User::factory()->create(['name' => 'Grace Editor'])->assignRole('editor');
    $this->link = fn (string $route, ?string $ability = null): array => ['title' => 'Finished: Import from WordPress', 'text' => null, 'link' => ['route' => $route, 'parameters' => [], 'ability' => $ability]];
});

it('shows a person their own inbox, newest first, with how many are unread (I-221)', function (): void {
    JobsFixtures::inboxItem($this->ada->id, ['created_at' => now()->subHour(), 'read_at' => now()]);
    JobsFixtures::inboxItem($this->ada->id, ['body' => ['title' => 'Failed: Rebuild the link index', 'text' => 'The old site stopped answering. Retrying carries on from where it stopped.', 'link' => null]]);
    JobsFixtures::inboxItem($this->grace->id);

    $this->actingAs($this->ada)->get('/admin/inbox')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inbox/index')
            ->has('rows', 2)
            ->where('rows.0.title', 'Failed: Rebuild the link index')
            ->where('rows.0.read', false)
            ->where('rows.0.kind', 'job.finished')
            ->where('rows.0.result', 'finished')
            ->where('rows.1.read', true)
            ->where('unread', 1)
            ->where('newer', null)
            ->where('older', null)
            ->has('siteTimezone'));
});

it('sends kind, result and place from the server, not from the title’s English words', function (): void {
    JobsFixtures::inboxItem($this->ada->id, [
        'kind' => 'job.failed',
        'body' => [
            'title' => 'Something went wrong',
            'text' => null,
            'link' => ['route' => 'tools.backups.index', 'parameters' => [], 'ability' => 'backups.manage'],
        ],
    ]);

    $this->actingAs($this->ada)->get('/admin/inbox')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.kind', 'job.failed')
            ->where('rows.0.result', 'failed')
            ->where('rows.0.place', 'Tools › Backups'));
});

it('pages the inbox by the reader’s page size, with addresses for the pages either side (I-140)', function (): void {
    foreach (range(1, 30) as $minutes) {
        JobsFixtures::inboxItem($this->ada->id, ['created_at' => now()->subMinutes($minutes)]);
    }

    lscrPrefer($this->ada, ['lists.per_page' => 25]);

    $first = $this->actingAs($this->ada)->get('/admin/inbox')->assertOk()->viewData('page')['props'];

    expect($first['rows'])->toHaveCount(25)
        ->and($first['newer'])->toBeNull()
        ->and($first['older'])->toStartWith('/admin/inbox?after=');

    $second = $this->actingAs($this->ada)->get($first['older'])->assertOk()->viewData('page')['props'];

    expect($second['rows'])->toHaveCount(5)
        ->and($second['older'])->toBeNull()
        ->and($second['newer'])->toStartWith('/admin/inbox?before=');
});

it('marks an item read and follows its link when it’s opened', function (): void {
    $item = JobsFixtures::inboxItem($this->grace->id, ['body' => ($this->link)('tools.redirects.index', 'redirects.manage')]);

    $this->actingAs($this->grace)->from('/admin/inbox')->post("/admin/inbox/{$item}/open")
        ->assertRedirect('/admin/tools/redirects');

    expect(DB::table('inbox_items')->where('id', $item)->value('read_at'))->not->toBeNull();
});

it('marks an item read and stays in the inbox when its link can’t be followed', function (): void {
    $item = JobsFixtures::inboxItem($this->grace->id, ['body' => ($this->link)('tools.page-cache.show', 'page-cache.clear')]);

    $this->actingAs($this->grace)->from('/admin/inbox')->post("/admin/inbox/{$item}/open")
        ->assertRedirect('/admin/inbox');

    expect(DB::table('inbox_items')->where('id', $item)->value('read_at'))->not->toBeNull();
});

it('answers someone else’s item as one that doesn’t exist, and marks nothing', function (): void {
    $adas = JobsFixtures::inboxItem($this->ada->id);

    $this->actingAs($this->grace)->post("/admin/inbox/{$adas}/open")->assertNotFound();
    // No route takes an address that isn't an item's; the public site's fallback answers GET only.
    $this->actingAs($this->grace)->post('/admin/inbox/NOT-AN-ITEM/open')->assertMethodNotAllowed();

    expect(DB::table('inbox_items')->where('id', $adas)->value('read_at'))->toBeNull();
});

it('marks all of the reader’s items read and goes back to the inbox', function (): void {
    JobsFixtures::inboxItem($this->ada->id);
    JobsFixtures::inboxItem($this->ada->id);
    $graces = JobsFixtures::inboxItem($this->grace->id);

    $this->actingAs($this->ada)->from('/admin/inbox')->post('/admin/inbox/read')->assertRedirect('/admin/inbox');

    expect(DB::table('inbox_items')->where('user_id', $this->ada->id)->whereNull('read_at')->count())->toBe(0)
        ->and(DB::table('inbox_items')->where('id', $graces)->value('read_at'))->toBeNull();
});

it('marks one item read and stays on the inbox, without following its link', function (): void {
    $item = JobsFixtures::inboxItem($this->grace->id, ['body' => ($this->link)('tools.redirects.index', 'redirects.manage')]);

    $this->actingAs($this->grace)->from('/admin/inbox')->post("/admin/inbox/{$item}/read")
        ->assertRedirect('/admin/inbox');

    expect(DB::table('inbox_items')->where('id', $item)->value('read_at'))->not->toBeNull();
});

it('marks nothing for someone else’s item on the read route', function (): void {
    $adas = JobsFixtures::inboxItem($this->ada->id);

    $this->actingAs($this->grace)->from('/admin/inbox')->post("/admin/inbox/{$adas}/read")->assertNotFound();

    expect(DB::table('inbox_items')->where('id', $adas)->value('read_at'))->toBeNull();
});
