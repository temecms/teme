<?php

declare(strict_types=1);

use App\Domain\Users\Operations\Input\SetPictureInput;
use App\Domain\Users\Operations\SetPicture;
use App\Domain\Users\Queries\PersonPictures;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    app(SetPicture::class)->execute(new SetPictureInput((string) $this->admin->id, pageImage($this->admin, 400, 400)->id, (string) $this->admin->id));

    // The test server answers on its own host and port, not APP_URL's: the picture is asked for from wherever the page is.
    config(['filesystems.disks.media.url' => '/storage/media']);
    Storage::forgetDisk('media');

    // The media disk lies under the public disk, served from public/storage, the link `storage:link` makes.
    if (! file_exists(public_path('storage'))) {
        File::link(storage_path('app/public'), public_path('storage'));
    }

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->admin)->get(route('notifications.edit'))->assertOk();
});

it('heads the account list with your picture, name and role, and marks the page you are on', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/account/notifications');
    cmWaitUntil($page, 'document.querySelector(\'nav[aria-label="Account"]\') !== null');

    $page->assertScript('document.querySelector(\'nav[aria-label="Account"] img\') !== null')
        ->assertScript('document.querySelector(\'nav[aria-label="Account"]\').innerText.includes("Administrator")')
        ->assertScript('[...document.querySelectorAll(\'nav[aria-label="Account"] a\')].map((link) => link.textContent.trim()).join("|")', 'Profile|Preferences|Notifications|Security|Two-factor sign-in')
        ->assertAttribute('nav[aria-label="Account"] a[href$="/admin/account/notifications"]', 'aria-current', 'page')
        ->assertScript('document.querySelectorAll("h1").length === 1');
});

it('shows your picture in the account menu', function (): void {
    $picture = (string) app(PersonPictures::class)->of((int) $this->admin->id);

    $page = cmNavigate(cmSignIn($this->admin), '/admin/account/notifications');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="account-menu-button"] img\') !== null');

    $page->assertScript('document.querySelector(\'[data-test="account-menu-button"] img\').getAttribute("src")', $picture);
});

it('opens the account pages from one button under 1024 pixels, with nothing scrolling sideways at 375', function (): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize(375, 812), '/admin/account/notifications');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="account-pages"]\') !== null');

    $page->assertScript('getComputedStyle(document.querySelector(\'nav[aria-label="Account"]\')).display', 'none')
        ->assertAttribute('[data-test="account-pages"]', 'aria-label', 'Account pages: Notifications')
        ->click('[data-test="account-pages"]');
    shellSettled($page, '[role="menu"]');

    $page->assertScript('document.querySelectorAll(\'[role="menu"] a\').length', 5)
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth');
});

it('passes axe on Account › Profile with every target 24 pixels or more, at 1280 and 375, in both schemes', function (string $scheme, int $width): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmNavigate(cmSignIn($this->admin)->resize($width, 900), '/admin/account/profile');
    cmWaitUntil($page, 'document.querySelector(\'nav[aria-label="Account"]\') !== null');
    $page->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark'])->with([1280, 375]);
