<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Jobs\JobsFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->opened = JobsFixtures::inboxItem($this->admin->id, [
        'kind' => 'job.finished',
        'body' => [
            'title' => 'Finished: Import from WordPress',
            'text' => '1,204 posts came in.',
            'link' => ['route' => 'tools.redirects.index', 'parameters' => [], 'ability' => 'redirects.manage'],
        ],
    ]);
    $this->failed = JobsFixtures::inboxItem($this->admin->id, [
        'kind' => 'job.failed',
        'created_at' => now()->subHour(),
        'body' => [
            'title' => 'Rebuild the link index',
            'text' => 'The old site stopped answering. Retrying carries on from where it stopped.',
            'link' => ['route' => 'tools.backups.index', 'parameters' => [], 'ability' => 'backups.manage'],
        ],
    ]);
    JobsFixtures::inboxItem($this->admin->id, ['created_at' => now()->subDay(), 'read_at' => now()]);

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->admin)->get(route('inbox.index'))->assertOk();
});

it('passes axe on the inbox, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)
        ->navigate('/admin/inbox')
        ->assertSee('Results of background work you started, such as imports, and notices from plugins. Each is kept for 90 days.')
        ->assertSee('2 unread')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('opens an item where it leads and marks it read, then marks the rest read', function (): void {
    cmSignIn($this->admin)
        ->navigate('/admin/inbox')
        ->click('[data-test="inbox-item"] button >> text="Finished: Import from WordPress"')
        ->assertPathIs('/admin/tools/redirects')
        ->navigate('/admin/inbox')
        ->assertSee('1 unread')
        ->click('[data-test="inbox-read-all"]')
        ->assertNotPresent('[data-test="inbox-unread"]');

    expect(DB::table('inbox_items')->where('id', $this->opened)->value('read_at'))->not->toBeNull()
        ->and(DB::table('inbox_items')->whereNull('read_at')->count())->toBe(0);
});

it('gives each item\'s title a 44-pixel target at 375 pixels, with nothing scrolling sideways', function (): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize(375, 812), '/admin/inbox');

    cmWaitUntil($page, "document.querySelector('[data-test=\"inbox-item\"] button') !== null");

    $sizes = $page->script(<<<'JS'
        () => ({
            page: Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
            titles: [...document.querySelectorAll('[data-test="inbox-item"] button:not([data-test="inbox-mark-read"])')].map((button) => Math.round(button.getBoundingClientRect().height)),
        })
        JS);

    expect($sizes['page'])->toBe(0)
        ->and($sizes['titles'])->not->toBe([])
        ->and(min($sizes['titles']))->toBeGreaterThanOrEqual(44);
});

it('draws kind and Failed from the server result, shows the place in the meta line, and Mark as read stays on the inbox', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/inbox');
    cmWaitUntil($page, "document.querySelector('[data-test=\"inbox-item\"][data-result=\"failed\"]') !== null");

    $page->assertSeeIn('[data-test="inbox-item"][data-result="failed"]', 'Failed')
        ->assertSeeIn('[data-test="inbox-item"][data-result="failed"] [data-test="inbox-meta"]', 'Tools › Backups')
        ->assertSeeIn('[data-test="inbox-item"][data-result="finished"] [data-test="inbox-meta"]', 'Tools › Redirects');

    $page->click('[data-test="inbox-item"][data-result="failed"] [data-test="inbox-mark-read"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"inbox-item\"][data-result=\"failed\"] [data-test=\"inbox-mark-read\"]') === null");

    expect($page->script('() => location.pathname'))->toBe('/admin/inbox')
        ->and(DB::table('inbox_items')->where('id', $this->failed)->value('read_at'))->not->toBeNull()
        ->and(DB::table('inbox_items')->where('id', $this->opened)->value('read_at'))->toBeNull();
});
