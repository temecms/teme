<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Jobs\HeaderFixtures;
use Tests\Fixtures\Jobs\JobsFixtures;
use Tests\Fixtures\Jobs\RecordingJobKind;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (JobsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    RecordingJobKind::register();
    $this->author = User::factory()->create(['name' => 'Ada Author'])->assignRole('author');

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->author)->get(route('dashboard'))->assertOk();
});

it('opens the bell’s latest items, marks them all read, and passes axe with the panel open, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->author, ['appearance.mode' => $scheme]);
    JobsFixtures::inboxItem($this->author->id, ['body' => ['title' => 'Finished: Move all posts to the trash', 'text' => 'Moved 40 posts to the trash.', 'link' => null]]);
    JobsFixtures::inboxItem($this->author->id, ['created_at' => now()->subHour()]);

    $page = cmSignIn($this->author);
    cmWaitUntil($page, <<<'JS'
        document.querySelector('[data-test="inbox-bell"]')?.getAttribute('aria-label') === 'Inbox, 2 unread'
        JS);

    $page->click('[data-test="inbox-bell"]');
    cmWaitUntil($page, <<<'JS'
        document.querySelectorAll('[data-test="inbox-panel"][role="dialog"] [data-test="inbox-item"]').length === 2
            && document.querySelector('[data-test="inbox-panel"]').textContent.includes('Moved 40 posts to the trash.')
        JS);
    // The panel settles, and so do the page's colours: signing in puts the saved scheme in place, and a
    // button's text fading from one scheme's colour to the other's fails contrast halfway (#264).
    shellSettled($page, '[data-test="inbox-panel"]');

    expect(cmAxeViolations($page))->toBe([]);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);

    $page->click('[data-test="inbox-mark-all"]');
    cmWaitUntil($page, <<<'JS'
        document.querySelector('[data-test="inbox-bell"]')?.getAttribute('aria-label') === 'Inbox'
        JS);

    expect(DB::table('inbox_items')->whereNull('read_at')->count())->toBe(0);
})->with(['light', 'dark']);

it('shows a person’s running job on the jobs button, and cancels it from the panel', function (): void {
    $job = HeaderFixtures::jobOf($this->author, ['label' => 'Move all posts to the trash']);

    $page = cmSignIn($this->author);
    cmWaitUntil($page, <<<'JS'
        document.querySelector('[data-test="jobs-button"]')?.getAttribute('aria-label') === 'Jobs, 1 running'
        JS);

    $page->click('[data-test="jobs-button"]');
    cmWaitUntil($page, <<<'JS'
        document.querySelector('[data-test="jobs-panel"][role="dialog"] [role="progressbar"][aria-label="Progress of Move all posts to the trash"]') !== null
        JS);
    $page->assertNoAccessibilityIssues(3)
        ->click('[aria-label="Cancel Move all posts to the trash"]');
    cmWaitUntil($page, <<<'JS'
        document.body.textContent.includes('Cancelled Move all posts to the trash. What it did stays done.')
            && document.querySelector('[data-test="jobs-button"]')?.getAttribute('aria-label') === 'No jobs running'
        JS);

    expect(DB::table('tracked_jobs')->where('id', $job)->value('state'))->toBe('cancelled');
});
