<?php

declare(strict_types=1);

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

    // Backups that are fine, so their own banner never outranks the update's.
    HealthyBackups::seed();
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    config([
        'teme.version' => '0.1.0-rc.13', 'teme.tagged' => true, 'teme.revision' => '102fb4f937d05d3bce9744d0f3bb00c590c45a7f',
        'teme.updates.channel' => null, 'teme.updates.check' => true,
    ]);
    updOperator();
    updFake(Http::response([
        updItem('0.1.0-rc.15', ['body' => "Two.\n\n### Security\n\n- Sign-in says less\n\n<!-- teme-release {\"format\":1,\"security\":true,\"maintenance\":false} -->"]),
        updItem('0.1.0-rc.14'),
        updItem('0.1.0-rc.13'),
    ], 200));
    app(CheckForUpdates::class)->run();
    app()->forgetInstance(Elevation::class);
    Http::allowStrayRequests();

    // Warm the screen before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('tools.updates.index'))->assertOk();
});

it('passes axe on Tools › Updates, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)
        ->navigate('/admin/tools/updates')
        ->assertSee('Teme 0.1.0-rc.15 is available')
        ->assertNoAccessibilityIssues(3);

    $screen = cmSmallTargets($page);

    expect($screen['small'])->toBe([])
        ->and($screen['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('never scrolls sideways at 375 pixels', function (): void {
    cmSignIn($this->admin)
        ->resize(375, 812)
        ->navigate('/admin/tools/updates')
        ->assertSee('Teme 0.1.0-rc.15 is available')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true);
});

it('says so when an older release carries security fixes and the newest does not', function (): void {
    DB::table('teme_releases')->where('version', '0.1.0-rc.15')->update(['security' => false]);
    DB::table('teme_releases')->where('version', '0.1.0-rc.14')->update(['security' => true]);

    cmSignIn($this->admin)
        ->navigate('/admin/tools/updates')
        ->assertSee('0.1.0-rc.14 carries security fixes. Updating to 0.1.0-rc.15 brings them too.');
});

it('words the screen for a site that has never checked, and for checks that are off', function (): void {
    DB::table('update_checks')->delete();
    DB::table('teme_releases')->delete();

    cmSignIn($this->admin)
        ->navigate('/admin/tools/updates')
        ->assertSee('Not checked yet. It runs within the hour')
        ->assertSee('No release has been found yet.');

    config(['teme.updates.check' => false]);

    cmSignIn($this->admin)
        ->navigate('/admin/tools/updates')
        ->assertSee('Update checks are off on this server')
        ->assertSee('Update checks are off, so this list isn’t kept up to date.');
});

it('names the commands and settings in full inside their sentences', function (): void {
    DB::table('update_checks')->delete();

    $page = cmSignIn($this->admin)->navigate('/admin/tools/updates')
        ->click('How to update by hand');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="updates-how"] li\') !== null');
    $line = fn (string $selector): string => (string) $page->script('() => document.querySelector('.json_encode($selector, JSON_THROW_ON_ERROR).').innerText');
    $dokploy = $page->script('() => { const item = document.querySelector(\'[data-test="updates-how"] li\').cloneNode(true); item.querySelector("strong").remove(); return item.innerText; }');

    expect($dokploy)->toContain('TEME_UPDATER_IMAGE')->not->toContain(':')
        ->and($line('[aria-labelledby="updates-check"] [data-slot="panel-body"] p:last-child'))->toContain('php artisan teme:updates:check')
        ->not->toContain('CHECKNow');
});

it('turns the update button on, and off with the reason when the newest needs maintenance mode', function (): void {
    cmSignIn($this->admin)->navigate('/admin/tools/updates')
        ->assertSee('Update to 0.1.0-rc.15')
        ->assertDontSee('Comes with the updater')
        ->assertScript('document.querySelector(\'[data-test="update-now"]\').disabled', false);

    DB::table('teme_releases')->where('version', '0.1.0-rc.15')->update(['maintenance' => true]);

    cmSignIn($this->admin)->navigate('/admin/tools/updates')
        ->assertSee('Teme 0.1.0-rc.15 needs maintenance mode while it deploys, so this update is done by hand: see How to update.')
        ->assertScript('document.querySelector(\'[data-test="update-now"]\').disabled', true);
});

it('says when the list is old, labels the folded releases, and draws the deploys as a list', function (): void {
    DB::table('update_checks')->where('id', 1)->update(['error' => 'Teme couldn’t reach GitHub. It asks again within the hour.', 'succeeded_at' => now()->subDays(2)]);
    DB::table('deploy_runs')->insert([
        'id' => strtolower((string) Str::ulid()), 'version' => '0.1.0-rc.13', 'revision' => null,
        'outcome' => 'deployed', 'exit_code' => 0, 'sentence' => 'Deployed.', 'backup_run_id' => 'run-1',
        'report' => json_encode(['migrations' => ['ran' => ['a', 'b', 'c']]]),
        'started_at' => now()->subMinutes(4), 'finished_at' => now()->subSeconds(20),
    ]);
    DB::table('deploy_runs')->insert([
        'id' => strtolower((string) Str::ulid()), 'version' => '0.1.0-rc.12', 'revision' => null,
        'outcome' => 'busy', 'exit_code' => 1, 'sentence' => 'Another deploy was running, so this one changed nothing.', 'backup_run_id' => null,
        'report' => '{}', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()->addSecond(),
    ]);

    cmSignIn($this->admin)
        ->navigate('/admin/tools/updates')
        ->assertSee('the last time Teme could read it.')
        ->assertSee('Also after yours')
        ->assertSee('Updated to Teme 0.1.0-rc.13. 3 migrations ran, after a backup.')
        ->assertSee('took 3 min 40 s')
        ->assertSee('Another deploy was running, so this one changed nothing.')
        ->assertSee('took 1 s');
});

it('names the newest release when the site is up to date', function (): void {
    config(['teme.version' => '0.1.0-rc.15']);

    cmSignIn($this->admin)->navigate('/admin/tools/updates')
        ->assertSee('This site runs the newest release it gets.')
        ->assertSee('Teme 0.1.0-rc.15, published');
});

it('names the newest release when the build is untagged', function (): void {
    // Its own test: a second sign-in in one test landed on the login page.
    config(['teme.tagged' => false, 'teme.version' => '0.1.0']);

    cmSignIn($this->admin)->navigate('/admin/tools/updates')
        ->assertSee('The newest release is Teme 0.1.0-rc.15, published');
});
