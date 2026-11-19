<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\Operations\Input\RequestBackupInput;
use App\Domain\Backups\Operations\RequestBackup;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Backups\FixedRecoveryKeys;
use Tests\Fixtures\Backups\ReadyBackups;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScreenRecords;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (tests/Browser/ToolsScreensTest.php).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    config(['teme.backups.cache_path' => '']);
    FixedRecoveryKeys::bind([3, 7]);
    $this->folder = TemporaryRepository::freshFolder();
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->seen = fn ($page, string $text) => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text).')');

    // A set-up site, as the screen draws it, for the axe runs.
    $this->seedScreen = function (): void {
        $folder = ScreenRecords::place('This server', ['location' => ['path' => '/srv/teme/backups/a-folder-name-long-enough-to-wrap-on-a-phone']]);
        ScreenRecords::place('Off-site', ['kind' => BackupDestinationKind::S3, 'state' => BackupDestinationState::Failing, 'last_error' => 'Off-site refused Teme’s key: the bucket answered that the access key ID doesn’t exist.', 'created_at' => now()->addSecond()]);
        $backup = ScreenRecords::backup($folder, now()->subHours(3)->format('Y-m-d H:i'), figures: ['seconds' => 845, 'database_bytes' => 52_000_000, 'media_files' => 1_204, 'media_bytes' => 3_400_000_000, 'bytes_added' => 38_000_000]);
        ScreenRecords::check(BackupRunKind::Check, $folder, now()->subHours(3)->addMinutes(6)->format('Y-m-d H:i'), parent: $backup);
    };

    // Warm the screens before the browser's first visit (I-638).
    $this->actingAs($this->admin)->get(route('tools.backups.index'))->assertOk();
});

afterEach(fn () => TemporaryRepository::remove($this->folder));

it('passes axe on Tools › Backups and the setup page, every target 24 pixels or more, in both schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->navigate('/admin/tools/backups');
    ($this->seen)($page, 'Nothing on this site is backed up yet.');
    $page->assertNoAccessibilityIssues(3);
    $first = cmSmallTargets($page);

    $page->click('[data-test="backup-set-up"]');
    ($this->seen)($page, 'Confirm password');
    $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
    ($this->seen)($page, 'Where should backups be kept?');
    $page->assertNoAccessibilityIssues(3);
    $setup = cmSmallTargets($page);

    ($this->seedScreen)();
    $page->navigate('/admin/tools/backups');
    ($this->seen)($page, 'the bucket answered that the access key ID doesn’t exist');
    $page->assertNoAccessibilityIssues(3);
    $screen = cmSmallTargets($page);

    expect($first['small'])->toBe([])->and($setup['small'])->toBe([])->and($screen['small'])->toBe([])
        ->and($screen['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('sets backups up at a folder, shows the key once, and confirms two groups typed back', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/tools/backups')->click('[data-test="backup-set-up"]');
    ($this->seen)($page, 'Confirm password');
    $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
    ($this->seen)($page, 'Where should backups be kept?');

    $page->type('#setup-path', $this->folder)->click('[data-test="setup-submit"]');
    cmWaitUntil($page, 'document.querySelectorAll(\'[data-test="key-group"]\').length === 8');

    expect($page->script('() => [...document.querySelectorAll(\'[data-test="key-group-value"]\')].map((group) => group.textContent.trim())'))
        ->toBe(explode('-', FixedRecoveryKeys::KEY));

    $page->click('[data-test="key-continue"]');
    ($this->seen)($page, 'Type groups 3 and 7 of your recovery key');
    $page->type('#group-3', '9htr')->type('#group-7', 'YA8G')->click('[data-test="key-confirm"]');
    cmWaitUntil($page, 'location.pathname === "/admin/tools/backups"');
    // The verdict says when the first backup starts; the line under it keeps the command alone.
    ($this->seen)($page, 'No backup has finished yet.');
    ($this->seen)($page, 'Or press Back up now to make one within a minute.');
    ($this->seen)($page, 'Backups are set up at This server. The first backup starts within a minute.');
});

it('asks for the password in place on the key page, and keeps the key there', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/tools/backups')->click('[data-test="backup-set-up"]');
    ($this->seen)($page, 'Confirm password');
    $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
    ($this->seen)($page, 'Where should backups be kept?');
    $page->type('#setup-path', $this->folder)->click('[data-test="setup-submit"]');
    cmWaitUntil($page, 'document.querySelectorAll(\'[data-test="key-group"]\').length === 8');

    // Writing the key down took longer than the confirmation lasts.
    $this->travel(16)->minutes();

    $page->click('[data-test="key-continue"]')->type('#group-3', '9HTR')->type('#group-7', 'YA8G')->click('[data-test="key-confirm"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="fresh-password-input"]\') !== null');
    ($this->seen)($page, 'Type your password to carry on.');
    shellSettled($page, '[data-slot="dialog-content"]');
    $page->type('[data-test="fresh-password-input"]', 'password')->click('[data-test="fresh-password-confirm"]');
    cmWaitUntil($page, 'location.pathname === "/admin/tools/backups"');
    ($this->seen)($page, 'Backups are set up at This server. The first backup starts within a minute.');
});

it('draws setup’s three steps on the step bar, the first current', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/tools/backups')->click('[data-test="backup-set-up"]');
    ($this->seen)($page, 'Confirm password');
    $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
    ($this->seen)($page, 'Where should backups be kept?');

    expect($page->script('() => document.querySelectorAll(\'[data-slot="step-bar"][aria-label="Setup steps"] > li\').length'))->toBe(3)
        ->and($page->script('() => document.querySelector(\'[data-slot="step-bar"][aria-label="Setup steps"] [aria-current="step"]\')?.textContent'))->toContain('Choose a place');
});

it('draws the screen at 375 pixels with nothing scrolling sideways', function (): void {
    ($this->seedScreen)();

    $page = cmSignIn($this->admin)->resize(375, 812)->navigate('/admin/tools/backups');
    ($this->seen)($page, 'Where backups are kept');

    expect($page->script('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth'))->toBeTrue();
    $page->assertNoAccessibilityIssues(3);
});

it('adds a folder place with the recovery key typed once', function (): void {
    $ready = ReadyBackups::atFolder();

    try {
        $page = cmSignIn($this->admin)->navigate('/admin/tools/backups')->click('[data-test="backup-place-add"]');
        ($this->seen)($page, 'Confirm password');
        $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
        ($this->seen)($page, 'Another place to keep backups');
        $page->type('#setup-name', 'Second place')->type('#setup-path', $this->folder)
            ->type('#place-recovery-key', $ready->recoveryKey->value())
            ->click('[data-test="place-submit"]');
        cmWaitUntil($page, 'location.pathname === "/admin/tools/backups"');
        ($this->seen)($page, 'Added Second place.');
    } finally {
        $ready->delete();
    }
});

it('checks a recovery key in its dialog, place by place', function (): void {
    $ready = ReadyBackups::atFolder();

    try {
        $page = cmSignIn($this->admin)->navigate('/admin/tools/backups')->click('[data-test="backup-key-check"]');
        cmWaitUntil($page, 'document.querySelector(\'[role="dialog"] #check-recovery-key\') !== null');
        $page->type('#check-recovery-key', $ready->recoveryKey->value())->click('[data-test="check-key-submit"]');
        ($this->seen)($page, 'Type your password to carry on.');
        $page->type('[data-test="fresh-password-input"]', 'password')->click('[data-test="fresh-password-confirm"]');
        ($this->seen)($page, 'Opens the backups at This server.');
        $page->assertScript('document.querySelector(\'[role="dialog"]\').innerText.includes("This is Recovery key.")');
    } finally {
        $ready->delete();
    }
});

it('answers a restore’s pause: this server is now the site', function (): void {
    ScreenRecords::place('This server', ['paused_at' => now()]);

    // The dialog's own animations, never its subtree's: a spinner inside runs for ever.
    $settled = 'document.querySelector(\'[role="dialog"]\') !== null && document.querySelector(\'[role="dialog"]\').getAnimations().every((a) => a.playState !== "running")';

    $page = cmSignIn($this->admin)->navigate('/admin/tools/backups');
    ($this->seen)($page, 'Backups are paused because this site was restored');
    $page->click('[data-test="restored-site"]');
    cmWaitUntil($page, $settled);
    $page->click('[role="dialog"] >> text="This server is now the site"');
    ($this->seen)($page, 'Confirm password');
    $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
    ($this->seen)($page, 'Backups are paused because this site was restored');
    $page->click('[data-test="restored-site"]');
    cmWaitUntil($page, $settled);
    $page->click('[role="dialog"] >> text="This server is now the site"');
    ($this->seen)($page, 'Backups resume at This server.');
});

it('backs up now: the job waits, the scheduler takes it, and the screen follows it to its end', function (): void {
    $backups = RunnableBackups::atFolders(1);

    // Its repositories go however the run ends: this file's afterEach doesn't know them.
    try {
        $page = cmSignIn($this->admin)->navigate('/admin/tools/backups');
        ($this->seen)($page, 'Or press Back up now to make one within a minute.');

        $page->click('[data-test="backup-now"]');
        ($this->seen)($page, 'Back up now is waiting to start. It starts within a minute, once nothing else is running.');
        cmWaitUntil($page, 'document.querySelector(\'[data-test="backup-now"]\').disabled === true');

        $this->artisan('teme:backup:due')->assertExitCode(0);
        // The next request mustn't keep the command's operator elevation.
        app()->forgetScopedInstances();

        ($this->seen)($page, 'The database is backed up at Place 1, and checked.');
        ($this->seen)($page, 'The database (');
        cmWaitUntil($page, 'document.querySelector(\'[data-test="backup-now"]\').disabled === false');
    } finally {
        $backups->delete();
    }
});

it('passes axe while Back up now waits, every target 24 pixels or more, in both schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);
    ($this->seedScreen)();
    app(RequestBackup::class)->execute(new RequestBackupInput((string) $this->admin->id));

    $page = cmSignIn($this->admin)->navigate('/admin/tools/backups');
    ($this->seen)($page, 'Back up now is waiting to start. It starts within a minute, once nothing else is running.');
    $page->assertNoAccessibilityIssues(3);

    expect(cmSmallTargets($page)['small'])->toBe([]);
})->with(['light', 'dark']);

it('follows the first scheduled backup after setup without a reload', function (): void {
    $place = ScreenRecords::place();

    $page = cmSignIn($this->admin)->navigate('/admin/tools/backups');
    ($this->seen)($page, 'The first starts within a minute.');

    // As the scheduler's first run would start: a running backup, and no job the page was opened on.
    $run = ScreenRecords::backup($place, now()->format('Y-m-d H:i:s'), BackupRunState::Running);
    app()->forgetScopedInstances();

    ($this->seen)($page, 'A backup is running now');
    ($this->seen)($page, 'No backup has finished yet: the first is running now.');

    $run->forceFill(['state' => BackupRunState::Finished, 'ended_at' => now()])->save();
    app()->forgetScopedInstances();

    cmWaitUntil($page, '!document.body.innerText.includes("No backup has finished yet")');
    $page->assertScript('!document.body.innerText.includes("Or press Back up now to make one within a minute.")');
});

it('stops asking once the first backup has failed', function (): void {
    $place = ScreenRecords::place();
    $count = "() => performance.getEntriesByType('resource').filter((entry) => entry.name.includes('/admin/tools/backups')).length";

    $page = cmSignIn($this->admin)->navigate('/admin/tools/backups');
    ($this->seen)($page, 'The first starts within a minute.');

    $run = ScreenRecords::backup($place, now()->format('Y-m-d H:i:s'), BackupRunState::Running);
    app()->forgetScopedInstances();
    ($this->seen)($page, 'A backup is running now');

    $run->forceFill(['state' => BackupRunState::Failed, 'message' => 'No place answered, so nothing was backed up.', 'ended_at' => now()])->save();
    app()->forgetScopedInstances();
    ($this->seen)($page, 'The last backup failed: No place answered, so nothing was backed up.');

    // The poll that showed the failure was the last: let one more interval pass, then count.
    usleep(4_000_000);
    $before = $page->script($count);
    usleep(7_000_000);

    expect($page->script($count))->toBe($before);
});
