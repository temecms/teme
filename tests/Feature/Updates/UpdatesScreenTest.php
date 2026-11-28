<?php

declare(strict_types=1);

use App\Domain\Support\Security\Elevation;
use App\Domain\Updates\CheckForUpdates;
use App\Domain\Updates\ReleaseNotes;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Backups\HealthyBackups;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    config([
        'teme.version' => '0.1.0-rc.13', 'teme.tagged' => true, 'teme.revision' => '102fb4f937d05d3bce9744d0f3bb00c590c45a7f',
        'teme.updates.channel' => null, 'teme.updates.check' => true, 'teme.updates.token' => 'github_pat_read_only',
    ]);
    updOperator();
    updFake(Http::response([
        updItem('0.1.0-rc.15', ['body' => "Two.\n\n### Security\n\n- **Sign-in** says less\n\n<!-- teme-release {\"format\":1,\"security\":true,\"maintenance\":false} -->"]),
        updItem('0.1.0-rc.14'),
        updItem('0.1.0-rc.13'),
    ], 200));
    app(CheckForUpdates::class)->run();
    app()->forgetInstance(Elevation::class);
});

it('shows administrators the version running, every newer release with its notes, the check and the deploys', function (): void {
    DB::table('deploy_runs')->insert([
        'id' => strtolower((string) Str::ulid()), 'version' => '0.1.0-rc.13', 'revision' => null,
        'outcome' => 'deployed', 'exit_code' => 0, 'sentence' => 'Deployed: no migration was waiting.', 'backup_run_id' => null,
        'report' => '{}', 'started_at' => now()->subMinute(), 'finished_at' => now(),
    ]);

    $this->actingAs($this->admin)->get('/admin/tools/updates')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tools/updates')
            ->where('running', ['version' => '0.1.0-rc.13', 'tagged' => true, 'revision' => '102fb4f937d0', 'channel' => 'prerelease', 'channelSet' => false])
            ->where('newer.0.version', '0.1.0-rc.15')
            ->where('newer.0.security', true)
            ->where('newer.0.notes', [
                ['heading' => null, 'paragraphs' => ['Two.'], 'items' => []],
                ['heading' => 'Security', 'paragraphs' => [], 'items' => ['Sign-in says less']],
            ])
            ->where('newer.1.version', '0.1.0-rc.14')
            ->has('newer', 2)
            ->where('newest.version', '0.1.0-rc.15')
            ->where('check.enabled', true)
            ->where('check.error', null)
            ->where('check.tokenSet', true)
            ->has('check.checkedAt')
            ->where('deploys.0.version', '0.1.0-rc.13')
            ->where('deploys.0.outcome', 'deployed')
            ->where('deploys.0.sentence', 'Deployed: no migration was waiting.')
            ->has('deploys.0.startedAt')
            ->has('deploys.0.migrations'));
});

it('refuses everyone without updates.manage', function (string $role): void {
    $this->actingAs(User::factory()->create()->assignRole($role))->get('/admin/tools/updates')->assertForbidden();
})->with(['editor', 'author', 'contributor']);

it('lists Updates on the Tools hub, after Jobs, for administrators', function (): void {
    $this->actingAs($this->admin)->get('/admin/tools')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tools.1', [
            'key' => 'updates',
            'label' => 'Updates',
            'icon' => 'download',
            'description' => 'The Teme this site runs, what is new, and updating from here.',
            'href' => '/admin/tools/updates',
            'external' => false,
        ]));
});

it('turns a release’s notes into plain text in sections, whatever markup they hold', function (): void {
    expect(ReleaseNotes::outline("Intro line one\nand two.\n\n## [x]\n\n### Added (1.13z)\n\n- `teme:x`, **bold** and [a link](https://example.org)\n* <script>alert(1)</script>\n\nAfter."))->toBe([
        ['heading' => null, 'paragraphs' => ['Intro line one and two.'], 'items' => []],
        ['heading' => '[x]', 'paragraphs' => [], 'items' => []],
        ['heading' => 'Added (1.13z)', 'paragraphs' => ['After.'], 'items' => ['teme:x, bold and a link', '<script>alert(1)</script>']],
    ])
        ->and(ReleaseNotes::outline(''))->toBe([]);
});

it('loads before the first check, with nothing known', function (): void {
    DB::table('update_checks')->delete();
    DB::table('teme_releases')->delete();

    $this->actingAs($this->admin)->get('/admin/tools/updates')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tools/updates')
            ->where('check.enabled', true)
            ->where('check.checkedAt', null)
            ->where('check.succeededAt', null)
            ->where('check.error', null)
            ->where('check.tokenSet', false)
            ->where('newest', null)
            ->has('newer', 0));
});

it('loads with the checks off', function (): void {
    config(['teme.updates.check' => false]);
    DB::table('update_checks')->delete();
    DB::table('teme_releases')->delete();

    $this->actingAs($this->admin)->get('/admin/tools/updates')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tools/updates')
            ->where('check.enabled', false)
            ->where('check.checkedAt', null)
            ->where('newest', null));
});

it('counts the migrations a deploy ran from its report', function (): void {
    DB::table('deploy_runs')->insert([
        'id' => strtolower((string) Str::ulid()), 'version' => '0.1.0-rc.13', 'revision' => null,
        'outcome' => 'deployed', 'exit_code' => 0, 'sentence' => 'Deployed.', 'backup_run_id' => null,
        'report' => json_encode(['migrations' => ['ran' => ['2027_01_01_000001_a', '2027_01_01_000002_b', '2027_01_01_000003_c']]]),
        'started_at' => now()->subMinute(), 'finished_at' => now(),
    ]);

    $this->actingAs($this->admin)->get('/admin/tools/updates')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('deploys.0.migrations', 3));
});

it('carries no link on the banner while Tools › Updates itself is open, and keeps it elsewhere', function (): void {
    // Backups that are fine, so their own banner never outranks the update's.
    HealthyBackups::seed();

    $this->actingAs($this->admin)->get('/admin/tools/updates')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('siteStatus.0.level', 'warning')
            ->where('siteStatus.0.message', 'Teme 0.1.0-rc.15 is available, with security fixes. This site runs 0.1.0-rc.13.')
            ->where('siteStatus.0.actionLabel', null)
            ->where('siteStatus.0.actionUrl', null));

    $this->actingAs($this->admin)->get('/admin/tools')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('siteStatus.0.actionLabel', 'See the update')
            ->where('siteStatus.0.actionUrl', route('tools.updates.index')));
});

it('gives the screen what it needs to say an older release carries security fixes', function (): void {
    DB::table('teme_releases')->where('version', '0.1.0-rc.15')->update(['security' => false]);
    DB::table('teme_releases')->where('version', '0.1.0-rc.14')->update(['security' => true]);

    $this->actingAs($this->admin)->get('/admin/tools/updates')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('newest.version', '0.1.0-rc.15')
            ->where('newest.security', false)
            ->where('newer.1.version', '0.1.0-rc.14')
            ->where('newer.1.security', true));
});

it('names the newest pre-release on an untagged build, which follows the pre-release channel', function (): void {
    config(['teme.tagged' => false, 'teme.version' => '0.1.0']);

    $this->actingAs($this->admin)->get(route('tools.updates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('running.tagged', false)
            ->where('running.channel', 'prerelease')
            ->where('newest.version', '0.1.0-rc.15'));
});

it('says the host keeps a restore point, and that no backup runs before a migration, under TEME_BACKUPS=host', function (): void {
    $this->actingAs($this->admin)->get('/admin/tools/updates')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tools/updates')->where('hostKeepsRestorePoint', false));

    config(['teme.backup_mode' => 'host']);

    $this->actingAs($this->admin)->get('/admin/tools/updates')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tools/updates')->where('hostKeepsRestorePoint', true));
});
