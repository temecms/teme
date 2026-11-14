<?php

declare(strict_types=1);

use App\Domain\Preferences\PreferenceDefinition;
use App\Domain\Preferences\PreferenceRegistry;
use App\Domain\Preferences\Queries\PreferencesFor;
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

it('chooses a picture from Media on Profile, shows it at once, and removes it again', function (): void {
    $writer = User::factory()->create(['name' => 'Bea Author'])->assignRole('author');
    pageImage($writer, 400, 400);

    $page = cmNavigate(cmSignIn($writer), '/admin/account/profile');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="picture-choose"]\') !== null');
    $page->assertScript('document.querySelector(\'[data-slot="form-panel"] [data-slot="person-picture"] img\') === null')
        ->click('[data-test="picture-choose"]');
    shellSettled($page, '[role="dialog"]');
    cmWaitUntil($page, 'document.querySelector(\'[role="dialog"] [role="option"]\') !== null');
    $page->click('[role="dialog"] [role="option"] >> nth=0')
        ->click('[role="dialog"] button >> text="Use as picture"');
    cmWaitUntil($page, 'document.body.innerText.includes("Picture changed.") && document.querySelector(\'nav[aria-label="Account"] img\') !== null');

    expect($writer->refresh()->picture_media_id)->not->toBeNull();

    $page->click('[data-test="picture-remove"]');
    cmWaitUntil($page, 'document.body.innerText.includes("Picture removed. Your initials show instead.") && document.querySelector(\'nav[aria-label="Account"] img\') === null');

    expect($writer->refresh()->picture_media_id)->toBeNull();
});

it('shows the save bar once a detail changes on Profile, and saves it', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/account/profile');
    cmWaitUntil($page, 'document.querySelector(\'nav[aria-label="Account"]\') !== null');

    $page->assertScript('document.querySelector(\'[data-test="save-bar"]\')?.hidden !== false')
        ->clear('#name')
        ->type('#name', 'Ada Lovelace');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="save-bar"]\')?.hidden === false');

    $page->click('[data-test="update-profile-button"]');
    cmWaitUntil($page, 'document.body.innerText.includes("Profile updated.") && document.querySelector(\'[data-test="save-bar"]\').hidden');

    $page->assertScript('document.querySelector(\'nav[aria-label="Account"]\').innerText.includes("Ada Lovelace")');

    expect($this->admin->refresh()->name)->toBe('Ada Lovelace');
});

it('saves a choice on Preferences the moment it is made, and keeps it after a reload', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/account/preferences');
    cmWaitUntil($page, 'document.getElementById("field-editor.smart_quotes") !== null');

    $page->assertScript('document.querySelector(\'[data-test="save-bar"]\') === null')
        ->click('[id="field-editor.smart_quotes"]');
    cmWaitUntil($page, 'document.body.innerText.includes("Preferences saved.")');

    $page->navigate('/admin/account/preferences');
    cmWaitUntil($page, 'document.getElementById("field-editor.smart_quotes") !== null');

    $page->assertScript('document.getElementById("field-editor.smart_quotes").getAttribute("data-state")', 'unchecked');

    expect(PreferencesFor::user($this->admin->refresh())['editor.smart_quotes'])->toBeFalse();
});

it('keeps a typed number until the field is left', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/account/preferences');
    cmWaitUntil($page, 'document.getElementById("field-editor.rail_width") !== null');

    $page->clear('[id="field-editor.rail_width"]')
        ->type('[id="field-editor.rail_width"]', '400');

    // Nothing is sent while the field keeps the focus.
    expect(PreferencesFor::user($this->admin->refresh())['editor.rail_width'])->toBe(360);

    $page->keys('[id="field-editor.rail_width"]', 'Tab');
    cmWaitUntil($page, 'document.body.innerText.includes("Preferences saved.")');

    expect(PreferencesFor::user($this->admin->refresh())['editor.rail_width'])->toBe(400);
});

it('keeps typed words in a box until the field is left, sending nothing at each key', function (): void {
    // No preference is a text box yet and the registry takes no such type, so
    // the fixture is put straight among its declarations, as a box a plugin's
    // text preference would draw.
    $registry = app(PreferenceRegistry::class);
    $definitions = new ReflectionProperty($registry, 'definitions');
    $definitions->setValue($registry, [...$definitions->getValue($registry), 'fixture.note' => new PreferenceDefinition('fixture.note', 'textarea', '', 'Note')]);

    $page = cmNavigate(cmSignIn($this->admin), '/admin/account/preferences');
    cmWaitUntil($page, 'document.getElementById("field-fixture.note") !== null');

    // Counts each request to save preferences as it starts, not as it ends.
    $page->script(<<<'JS'
        () => {
            window.preferencesSent = 0;
            const open = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                if (String(url).includes('/admin/account/preferences') && method.toUpperCase() !== 'GET') {
                    window.preferencesSent += 1;
                }

                return open.call(this, method, url, ...rest);
            };
        }
        JS);
    $sent = 'window.preferencesSent';

    $page->click('[id="field-fixture.note"]')
        ->keys('[id="field-fixture.note"]', ['N', 'o', 't', 'e', 's']);

    // Nothing is sent while the field keeps the focus.
    $page->assertScript('document.getElementById("field-fixture.note").tagName', 'TEXTAREA')
        ->assertScript($sent, 0)
        ->keys('[id="field-fixture.note"]', 'Tab');
    cmWaitUntil($page, $sent.' === 1');
});

it('opens Two-factor sign-in from the account list after the password, with Turn on two-factor sign-in', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/account/profile');
    cmWaitUntil($page, 'document.querySelector(\'nav[aria-label="Account"]\') !== null');

    $page->click('nav[aria-label="Account"] >> text="Two-factor sign-in"')
        // The password screen is another page; type() and click() wait for its field and button.
        ->type('#password', 'password')
        ->click('[data-test="confirm-password-button"]');
    cmWaitUntil($page, 'document.querySelector("h1")?.textContent === "Two-factor sign-in"');

    $page->assertSee('Turn on two-factor sign-in');
});

it('passes axe on Preferences and Two-factor sign-in, every target 24 pixels or more, in both schemes', function (string $path, string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmNavigate(cmSignIn($this->admin), $path);

    if ($path === '/admin/account/two-factor') {
        // The password screen is another page; type() and click() wait for its field and button.
        $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
    }

    cmWaitUntil($page, 'location.pathname === "'.$path.'" && document.querySelectorAll("h1").length === 1');
    $page->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['/admin/account/preferences', '/admin/account/two-factor'])->with(['light', 'dark']);
