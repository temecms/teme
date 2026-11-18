<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Support\Security\Elevation;
use App\Domain\Updates\CheckForUpdates;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Fixtures\Backups\HealthyBackups;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (tests/Browser/UpdatesScreenTest.php).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    HealthyBackups::seed();
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    config([
        'teme.version' => '0.1.0-rc.14', 'teme.tagged' => true, 'teme.revision' => '102fb4f937d05d3bce9744d0f3bb00c590c45a7f',
        'teme.updates.channel' => null, 'teme.updates.check' => true,
    ]);
    updOperator();
    updFake(Http::response([updItem('0.1.0-rc.15'), updItem('0.1.0-rc.14'), updItem('0.1.0-rc.13')], 200));
    app(CheckForUpdates::class)->run();
    updDeployed('0.1.0-rc.14', 60);
    updDeployed('0.1.0-rc.13', 600);
    app()->forgetInstance(Elevation::class);
    Http::allowStrayRequests();

    // An update row, as the updater leaves it: the seven steps in these states.
    $this->row = function (string $status, array $states = [], array $overrides = []): void {
        $steps = $states === [] ? '[]' : json_encode(array_map(fn (string $step, string $state): array => [
            'step' => $step, 'state' => $state, 'startedAt' => $state === 'pending' ? null : now()->subMinutes(2)->toIso8601String(),
            'finishedAt' => in_array($state, ['done', 'failed', 'switched-back'], true) ? now()->subMinute()->toIso8601String() : null,
            'detail' => null, 'asks' => 1, 'runsAfter' => null, 'mark' => null,
        ], ['deploy', 'web', 'worker', 'worker-long', 'scheduler', 'check', 'updater'], $states), JSON_THROW_ON_ERROR);

        DB::table('teme_updates')->insert([
            'id' => strtolower((string) Str::ulid()), 'kind' => 'update', 'from_version' => '0.1.0-rc.14', 'to_version' => '0.1.0-rc.15',
            'status' => $status, 'steps' => $steps, 'requested_by' => $this->admin->id, 'requested_at' => now()->subMinutes(3),
            'started_at' => $status === 'requested' ? null : now()->subMinutes(2),
            'finished_at' => in_array($status, ['requested', 'running'], true) ? null : now()->subMinute(),
            'error' => $status === 'switched_back' || $status === 'failed' ? 'The new release didn’t answer.' : null,
            ...$overrides,
        ]);
    };
    $this->seen = fn ($page, string $text) => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text).')');

    // Warm the screen before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('tools.updates.index'))->assertOk();
});

it('passes axe in the light and dark schemes, every target 24 pixels or more, in each of the five states', function (string $scheme, string $state): void {
    match ($state) {
        'requested' => ($this->row)('requested'),
        // The deploy step shows the runner's wait for a quiet moment once.
        'running' => ($this->row)('running', ['done', 'started', 'pending', 'pending', 'pending', 'pending', 'pending']),
        'done' => ($this->row)('done', array_fill(0, 7, 'done')),
        'switched back' => ($this->row)('switched_back', ['done', 'done', 'done', 'done', 'done', 'switched-back', 'pending']),
        'failed' => ($this->row)('failed', ['failed', 'pending', 'pending', 'pending', 'pending', 'pending', 'pending']),
    };
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->navigate('/admin/tools/updates');
    ($this->seen)($page, match ($state) {
        'requested' => 'Update to Teme 0.1.0-rc.15 requested by Ada Admin at',
        'running' => 'Updating to Teme 0.1.0-rc.15',
        'done' => 'Updated to Teme 0.1.0-rc.15 at',
        'switched back' => 'The update to Teme 0.1.0-rc.15 was switched back at',
        'failed' => 'The update to Teme 0.1.0-rc.15 stopped:',
    });

    $page->assertNoAccessibilityIssues(3);
    $screen = cmSmallTargets($page);

    expect($screen['small'])->toBe([])->and($screen['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark'])->with(['requested', 'running', 'done', 'switched back', 'failed']);

it('shows the wait for a quiet moment under the deploy step while the update is running', function (): void {
    ($this->row)('running', ['pending', 'pending', 'pending', 'pending', 'pending', 'pending', 'pending']);
    DB::table('teme_updates')->update(['steps' => json_encode(array_map(fn (string $step): array => [
        'step' => $step, 'state' => 'pending', 'startedAt' => null, 'finishedAt' => null,
        'detail' => $step === 'deploy' ? 'Waiting for a backup or a job to finish first.' : null,
        'asks' => 0, 'runsAfter' => null, 'mark' => null,
    ], ['deploy', 'web', 'worker', 'worker-long', 'scheduler', 'check', 'updater']), JSON_THROW_ON_ERROR)]);

    $page = cmSignIn($this->admin)->navigate('/admin/tools/updates');
    ($this->seen)($page, 'Waiting for a backup or a job to finish first.');
    $page->assertSee('0 of 7 steps');
});

it('never scrolls sideways at 375 pixels in the running state', function (): void {
    ($this->row)('running', ['done', 'started', 'pending', 'pending', 'pending', 'pending', 'pending']);

    $page = cmSignIn($this->admin)->resize(375, 812)->navigate('/admin/tools/updates');
    ($this->seen)($page, 'Updating to Teme 0.1.0-rc.15');
    $page->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true);
});

it('asks for the password on the password screen, then carries on by itself', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/tools/updates');
    ($this->seen)($page, 'Update to 0.1.0-rc.15');
    $page->click('[data-test="update-now"]');
    ($this->seen)($page, 'Confirm password');
    $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
    // Back on Tools › Updates, the request is already made: the button is not pressed again.
    ($this->seen)($page, 'Update to Teme 0.1.0-rc.15 requested by Ada Admin at');
    ($this->seen)($page, 'The updater starts it within a minute.');
    $page->assertScript('location.pathname', '/admin/tools/updates');
    $page->assertScript('sessionStorage.getItem("teme.updates.pending") === null', true);

    expect(DB::table('teme_updates')->count())->toBe(1);
});

it('does nothing when the person comes back without the password', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/tools/updates');
    ($this->seen)($page, 'Update to 0.1.0-rc.15');
    $page->click('[data-test="update-now"]');
    ($this->seen)($page, 'Confirm password');
    $page->navigate('/admin/tools/updates');
    ($this->seen)($page, 'Update to 0.1.0-rc.15');
    cmWaitUntil($page, 'sessionStorage.getItem("teme.updates.pending") === null');
    // Time for the one request the page sends on its own to be answered with 423.
    $page->wait(2);

    $page->assertScript('location.pathname', '/admin/tools/updates')
        ->assertScript('document.querySelector(\'[data-test="update-now"]\').disabled', false);
    expect(DB::table('teme_updates')->count())->toBe(0);
});

it('rolls back the same way, from the done state', function (): void {
    ($this->row)('done', array_fill(0, 7, 'done'));

    $page = cmSignIn($this->admin)->navigate('/admin/tools/updates');
    ($this->seen)($page, 'Roll back to 0.1.0-rc.13');
    $page->click('[data-test="update-rollback"]');
    ($this->seen)($page, 'Confirm password');
    $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
    ($this->seen)($page, 'Roll back to Teme 0.1.0-rc.13 requested by Ada Admin at');
    $page->assertScript('location.pathname', '/admin/tools/updates');

    expect(DB::table('teme_updates')->where('kind', 'rollback')->count())->toBe(1);
});

it('goes straight through with a recent password check, one click', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/tools/updates');
    ($this->seen)($page, 'Update to 0.1.0-rc.15');
    $page->click('[data-test="update-now"]');
    ($this->seen)($page, 'Confirm password');
    $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
    ($this->seen)($page, 'Update to Teme 0.1.0-rc.15 requested by Ada Admin at');
    $page->click('[data-test="update-cancel"]');
    ($this->seen)($page, 'The update was cancelled.');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="update-now"]\') !== null');

    $page->click('[data-test="update-now"]');
    ($this->seen)($page, 'Update to Teme 0.1.0-rc.15 requested by Ada Admin at');
    $page->assertScript('location.pathname', '/admin/tools/updates')
        ->assertScript('sessionStorage.getItem("teme.updates.pending") === null', true);

    expect(DB::table('teme_updates')->where('status', 'requested')->count())->toBe(1);
});

it('cancels the request that waits', function (): void {
    ($this->row)('requested');
    app(Heartbeats::class)->beat(Heartbeats::UPDATER);

    $page = cmSignIn($this->admin)->navigate('/admin/tools/updates');
    ($this->seen)($page, 'Update to Teme 0.1.0-rc.15 requested by Ada Admin at');
    $page->click('[data-test="update-cancel"]');
    ($this->seen)($page, 'The update was cancelled.');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="update-block"]\') === null');
});

// How a poll is made to fail: Pest's in-process server cannot drop a connection or answer as the
// platform's proxy does while the Compose service restarts, so the page's XMLHttpRequest is
// replaced by a stand-in. The next poll (the one asking for partial props) is answered with a
// plain-text 404 or fails to connect, and window.__failed counts those; every other request goes
// to the server as usual.
beforeEach(function (): void {
    $this->failNextPoll = fn (string $how): string => str_replace('__HOW__', json_encode($how, JSON_THROW_ON_ERROR), <<<'JS'
    (() => {
        const Real = window.XMLHttpRequest;
        window.__failed = 0;
        window.__failNext = __HOW__;

        class StandIn extends Real {
            setRequestHeader(name, value) {
                if (name.toLowerCase() === 'x-inertia-partial-data') {
                    this.isPoll = true;
                }

                super.setRequestHeader(name, value);
            }

            send(body) {
                if (!this.isPoll || window.__failNext === null) {
                    super.send(body);

                    return;
                }

                const how = window.__failNext;
                window.__failNext = null;
                window.setTimeout(() => {
                    window.__failed++;

                    if (how === 'network') {
                        this.dispatchEvent(new ProgressEvent('error'));

                        return;
                    }

                    Object.defineProperty(this, 'status', { value: 404 });
                    Object.defineProperty(this, 'responseText', { value: '404 page not found' });
                    this.getAllResponseHeaders = () => 'content-type: text/plain; charset=utf-8\r\n';
                    this.dispatchEvent(new ProgressEvent('load'));
                }, 50);
            }
        }

        window.XMLHttpRequest = StandIn;

        return true;
    })()
    JS);
});

it('keeps its last block and goes on polling when one poll gets a 404 or loses its connection', function (string $how): void {
    ($this->row)('running', ['done', 'started', 'pending', 'pending', 'pending', 'pending', 'pending']);

    $page = cmSignIn($this->admin)->navigate('/admin/tools/updates');
    ($this->seen)($page, 'Updating to Teme 0.1.0-rc.15');
    $page->script(($this->failNextPoll)($how));
    cmWaitUntil($page, 'window.__failed === 1');
    // Time for Inertia to show the answer, if it were going to.
    $page->wait(1);

    // No box over the admin, and the block as it was.
    $page->assertScript('document.querySelector("iframe") === null', true)
        ->assertScript('document.body.innerText.includes("404 page not found")', false)
        ->assertSee('Updating to Teme 0.1.0-rc.15');

    // The update finishes while the site is away; the next poll that gets through shows it.
    DB::table('teme_updates')->update([
        'status' => 'done', 'finished_at' => now(),
        'steps' => json_encode(array_map(fn (string $step): array => [
            'step' => $step, 'state' => 'done', 'startedAt' => now()->subMinute()->toIso8601String(),
            'finishedAt' => now()->toIso8601String(), 'detail' => null, 'asks' => 1, 'runsAfter' => null, 'mark' => null,
        ], ['deploy', 'web', 'worker', 'worker-long', 'scheduler', 'check', 'updater']), JSON_THROW_ON_ERROR),
    ]);
    ($this->seen)($page, 'Updated to Teme 0.1.0-rc.15 at');
})->with(['a plain-text 404' => 'plain-404', 'a lost connection' => 'network']);

it('links Settings › Updates where a stopped update says to paste the Dokploy key there (#534)', function (): void {
    ($this->row)('failed', ['failed', 'pending', 'pending', 'pending', 'pending', 'pending', 'pending'], ['error' => 'Teme has no Dokploy API key. Paste one in Settings › Updates.']);

    $page = cmSignIn($this->admin)->navigate('/admin/tools/updates');
    ($this->seen)($page, 'The update to Teme 0.1.0-rc.15 stopped: Teme has no Dokploy API key. Paste one in Settings › Updates.');

    $page->assertScript("document.querySelector('#update-lead a')?.textContent", 'Settings › Updates')
        ->assertScript("new URL(document.querySelector('#update-lead a').href).pathname", '/admin/settings/updates');
});
