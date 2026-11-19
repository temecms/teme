<?php

declare(strict_types=1);

use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\InviteUser;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
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

    // Warm the screen before the browser's first visit.
    $this->actingAs($this->admin)->get('/admin/_catalogue')->assertOk();
});

/**
 * The overlay's box and the window's size, rounded to whole pixels. The
 * window's size is the area a fixed overlay is placed in: the admin keeps the
 * scrollbar's gutter (I-3130), which a phone's own scrollbar never takes.
 *
 * @return array{top: int, bottom: int, left: int, width: int, height: int, vw: int, vh: int}
 */
function poBox(mixed $page, string $selector): array
{
    $quoted = json_encode($selector, JSON_THROW_ON_ERROR);

    /** @var array{top: int, bottom: int, left: int, width: int, height: int, vw: int, vh: int} */
    return cmScriptArray($page, <<<JS
        () => { const r = document.querySelector({$quoted}).getBoundingClientRect();
          const probe = document.createElement('div');
          probe.style.cssText = 'position: fixed; inset: 0; visibility: hidden; pointer-events: none';
          document.body.append(probe);
          const w = probe.getBoundingClientRect();
          probe.remove();
          return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left),
                   width: Math.round(r.width), height: Math.round(r.height), vw: Math.round(w.width), vh: Math.round(w.height) }; }
        JS, 'poBox');
}

/** Signs in and opens the catalogue's phone overlays at the given size. */
function poCatalogue(User $person, int $width, int $height = 900): mixed
{
    $page = cmNavigate(cmSignIn($person)->resize($width, $height), '/admin/_catalogue#phone-overlays');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue-overlay-dialog"]\') !== null');

    return $page;
}

/** Opens one of the catalogue's overlays with its button, and waits until it has settled. */
function poOpen(mixed $page, string $name): mixed
{
    $page->click("[data-test=\"{$name}\"]");

    return shellSettled($page, "[data-test=\"{$name}-content\"]");
}

/** Closes the overlay open on the page with Escape, and waits until it has gone. */
function poClose(mixed $page, string $name): mixed
{
    $page->keys("[data-test=\"{$name}-content\"]", 'Escape');

    return cmWaitUntil($page, "document.querySelector('[data-test=\"{$name}-content\"]') === null");
}

/** Presses a key where the focus is, as a person does, without moving it first. */
function poPress(mixed $page, string $key): mixed
{
    $page->page()->locator(':focus')->press($key);

    return $page;
}

/** Opens the catalogue's menu and waits until it has settled, as a sheet on a phone or a dropdown from 640 up. */
function poOpenMenu(mixed $page): mixed
{
    $page->click('[data-test="catalogue-overlay-menu"]');
    cmWaitUntil($page, 'document.querySelector(\'[role="menu"]\') !== null');

    return shellSettled($page, '[data-slot="sheet-content"], [data-slot="dropdown-menu-content"]');
}

/**
 * axe's violations inside the open dropdown menu. A dropdown is not a modal
 * dialog, so while it is open axe would also judge the page Radix hides
 * behind it (its landmarks, and the menu's wrapper outside them); the
 * phone's sheet is a modal dialog and is judged with the whole page.
 *
 * @return list<string>
 */
function poMenuAxeViolations(mixed $page): array
{
    $result = cmScriptArray($page, <<<'JS'
        async () => (await window.axe.run(document.querySelector('[role="menu"]'))).violations.flatMap((violation) => violation.nodes.map((node) => violation.id + ': ' + node.target.join(' ')))
        JS, 'poMenuAxeViolations', once: true);

    return array_values(array_map(strval(...), $result));
}

/** Waits until the focus is on the menu item that reads $name. */
function poFocusOn(mixed $page, string $name): mixed
{
    $quoted = json_encode($name, JSON_THROW_ON_ERROR);

    return cmWaitUntil($page, "document.activeElement?.getAttribute('role') === 'menuitem' && document.activeElement.textContent.trim() === {$quoted}");
}

it('fills a phone\'s screen with a dialog, its title and Save in view as the body scrolls', function (): void {
    $page = poOpen(poCatalogue($this->admin, 375, 667), 'catalogue-overlay-dialog');

    $box = poBox($page, '[data-test="catalogue-overlay-dialog-content"]');

    expect([$box['top'], $box['left'], $box['width'], $box['bottom']])->toBe([0, 0, $box['vw'], $box['vh']]);

    $page->assertScript('(() => { const body = document.querySelector(\'[data-test="catalogue-overlay-dialog-content"] [data-slot="responsive-dialog-body"]\'); return body.scrollHeight > body.clientHeight; })()');

    // Save is in view before the body scrolls, the title after it.
    expect(poBox($page, '[data-test="catalogue-overlay-dialog-save"]')['bottom'])->toBeLessThanOrEqual($box['vh']);

    $page->script('() => { const body = document.querySelector(\'[data-test="catalogue-overlay-dialog-content"] [data-slot="responsive-dialog-body"]\'); body.scrollTop = body.scrollHeight; }');
    cmWaitUntil($page, '(() => { const body = document.querySelector(\'[data-test="catalogue-overlay-dialog-content"] [data-slot="responsive-dialog-body"]\'); return body.scrollTop > 0 && body.scrollTop + body.clientHeight >= body.scrollHeight - 1; })()');

    $title = poBox($page, '[data-test="catalogue-overlay-dialog-content"] [data-slot="dialog-title"]');
    $save = poBox($page, '[data-test="catalogue-overlay-dialog-save"]');

    expect($title['top'] - $box['top'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(16)
        ->and($save['bottom'])->toBeLessThanOrEqual($box['vh']);
});

it('keeps the dialog centered at its width from 640 up', function (): void {
    $page = poOpen(poCatalogue($this->admin, 1280, 900), 'catalogue-overlay-dialog');

    $box = poBox($page, '[data-test="catalogue-overlay-dialog-content"]');

    expect($box['width'])->toBe(640)
        ->and(abs($box['left'] - intdiv($box['vw'] - 640, 2)))->toBeLessThanOrEqual(1)
        ->and($box['top'])->toBeGreaterThan(0)
        ->and($box['bottom'])->toBeLessThan($box['vh']);

    // The window's new height reaches the dialog's cap a few frames after the resize.
    $page->resize(1280, 600);
    cmWaitUntil($page, 'innerHeight === 600 && document.querySelector(\'[data-test="catalogue-overlay-dialog-content"]\').getBoundingClientRect().height <= innerHeight - 48');

    $box = poBox($page, '[data-test="catalogue-overlay-dialog-content"]');

    expect($box['height'])->toBeLessThanOrEqual($box['vh'] - 48);
    $page->assertScript('(() => { const body = document.querySelector(\'[data-test="catalogue-overlay-dialog-content"] [data-slot="responsive-dialog-body"]\'); return body.scrollHeight > body.clientHeight; })()');
});

it('opens a confirmation from the foot of a phone\'s screen', function (): void {
    $page = poOpen(poCatalogue($this->admin, 375, 667), 'catalogue-overlay-confirm');

    $box = poBox($page, '[data-test="catalogue-overlay-confirm-content"]');

    expect([$box['bottom'], $box['left'], $box['width']])->toBe([$box['vh'], 0, $box['vw']])
        ->and($box['top'])->toBeGreaterThan(0);

    $page->assertScript('getComputedStyle(document.querySelector(\'[data-test="catalogue-overlay-confirm-content"]\')).borderTopLeftRadius !== "0px"')
        ->assertScript('getComputedStyle(document.querySelector(\'[data-test="catalogue-overlay-confirm-content"]\')).borderTopRightRadius !== "0px"')
        ->assertScript('getComputedStyle(document.querySelector(\'[data-test="catalogue-overlay-confirm-content"]\')).borderBottomLeftRadius', '0px')
        ->assertScript('getComputedStyle(document.querySelector(\'[data-test="catalogue-overlay-confirm-content"]\')).borderBottomRightRadius', '0px')
        ->assertScript('document.activeElement === document.querySelector(\'[data-test="catalogue-overlay-confirm-cancel"]\')');

    poClose($page, 'catalogue-overlay-confirm');
    cmWaitUntil($page, 'document.activeElement === document.querySelector(\'[data-test="catalogue-overlay-confirm"]\')');
});

it('fills a phone\'s screen with a side sheet at 600 pixels', function (): void {
    $page = poOpen(poCatalogue($this->admin, 600, 800), 'catalogue-overlay-sheet');

    $box = poBox($page, '[data-test="catalogue-overlay-sheet-content"]');

    expect($box['width'])->toBe($box['vw']);

    poClose($page, 'catalogue-overlay-sheet');
    $page->resize(1280, 900);
    cmWaitUntil($page, 'innerWidth === 1280');
    poOpen($page, 'catalogue-overlay-sheet');

    expect(poBox($page, '[data-test="catalogue-overlay-sheet-content"]')['width'])->toBe(480);
});

it('passes axe with each overlay open, every target 24 pixels or more, at 375 and 1280 in both schemes', function (string $scheme, int $width): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = poCatalogue($this->admin, $width);

    foreach (['catalogue-overlay-dialog', 'catalogue-overlay-confirm', 'catalogue-overlay-sheet'] as $overlay) {
        poOpen($page, $overlay);

        $targets = cmSmallTargets($page);

        expect(cmAxeViolations($page))->toBe([], "axe with {$overlay} open")
            ->and($targets['small'])->toBe([], "targets with {$overlay} open")
            ->and($targets['checked'])->toBeGreaterThan(0);

        poClose($page, $overlay);
    }

    poOpenMenu($page);

    $targets = cmSmallTargets($page);

    expect($width < 640 ? cmAxeViolations($page) : poMenuAxeViolations($page))->toBe([], 'axe with the menu open')
        ->and($targets['small'])->toBe([], 'targets with the menu open')
        ->and($targets['checked'])->toBeGreaterThan(0);

    if ($width < 640) {
        $page->assertScript('[...document.querySelectorAll(\'[role="menu"] [role="menuitem"]\')].every((item) => item.getBoundingClientRect().height >= 44)');
    }
})->with(['light', 'dark'])->with([375, 1280]);

it('scrolls nothing sideways at 320 with the dialog open', function (): void {
    $page = poOpen(poCatalogue($this->admin, 320, 640), 'catalogue-overlay-dialog');

    $page->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth');
});

it('opens a menu as a sheet from the foot of a phone\'s screen', function (): void {
    $page = poOpenMenu(poCatalogue($this->admin, 375, 667));

    $box = poBox($page, '[data-slot="sheet-content"]');

    expect([$box['bottom'], $box['width']])->toBe([$box['vh'], $box['vw']]);

    $page->assertScript('document.querySelector(\'[data-slot="sheet-content"] [role="menu"]\') !== null')
        ->assertScript('document.querySelector(\'[data-slot="sheet-content"] [data-slot="sheet-title"]\').textContent', 'More actions');
    poFocusOn($page, 'Edit');
});

it('moves through a phone menu with the arrows, Home and End, past the disabled item', function (): void {
    $page = poOpenMenu(poCatalogue($this->admin, 375, 667));
    poFocusOn($page, 'Edit');

    poFocusOn(poPress($page, 'ArrowDown'), 'Duplicate');
    poFocusOn(poPress($page, 'ArrowDown'), 'Move to trash');
    poFocusOn(poPress($page, 'ArrowDown'), 'Edit');
    poFocusOn(poPress($page, 'End'), 'Move to trash');
    poFocusOn(poPress($page, 'Home'), 'Edit');
    poFocusOn(poPress($page, 'ArrowUp'), 'Move to trash');

    // Tab leaves the items for the sheet's Close button; Shift+Tab comes back to the item last on.
    poPress($page, 'Tab');
    cmWaitUntil($page, 'document.activeElement !== null && document.activeElement.getAttribute(\'role\') !== \'menuitem\' && document.querySelector(\'[data-slot="sheet-content"]\').contains(document.activeElement)');
    poFocusOn(poPress($page, 'Shift+Tab'), 'Move to trash');
});

it('runs an item and closes on Enter, and closes on Esc with nothing run', function (): void {
    $page = poOpenMenu(poCatalogue($this->admin, 375, 667));
    poFocusOn($page, 'Edit');

    poFocusOn(poPress($page, 'ArrowDown'), 'Duplicate');
    poPress($page, 'Enter');
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="sheet-content"]\') === null && document.activeElement === document.querySelector(\'[data-test="catalogue-overlay-menu"]\')');
    $page->assertScript('document.querySelector(\'[data-test="catalogue-overlay-menu-chosen"]\').textContent', 'Duplicate');

    poOpenMenu($page);
    poFocusOn($page, 'Edit');
    poPress($page, 'Escape');
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="sheet-content"]\') === null && document.activeElement === document.querySelector(\'[data-test="catalogue-overlay-menu"]\')');
    $page->assertScript('document.querySelector(\'[data-test="catalogue-overlay-menu-chosen"]\').textContent', 'Duplicate');
});

it('keeps the dropdown menu from 640 up', function (): void {
    $page = poOpenMenu(poCatalogue($this->admin, 1280));

    $page->assertScript('document.querySelector(\'[data-slot="dropdown-menu-content"] [role="menu"], [data-slot="dropdown-menu-content"][role="menu"]\') !== null')
        ->assertScript('document.querySelector(\'[data-slot="sheet-content"]\') === null');
});

it('closes an open menu when the window crosses 640', function (): void {
    $page = poOpenMenu(poCatalogue($this->admin, 375, 667));

    $page->resize(1280, 900);
    cmWaitUntil($page, 'innerWidth === 1280 && document.querySelector(\'[role="menu"]\') === null && document.querySelector(\'[data-slot="sheet-content"]\') === null');
});

it('fills a phone\'s screen with the invite dialog, Send invitation in view', function (): void {
    $this->actingAs($this->admin)->get(route('users.index'))->assertOk();

    $page = cmNavigate(cmSignIn($this->admin)->resize(375, 667), '/admin/users');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="people-invite"]\') !== null');
    $page->click('[data-test="people-invite"]');
    shellSettled($page, '[data-test="invite-dialog"]');

    $box = poBox($page, '[data-test="invite-dialog"]');

    expect([$box['top'], $box['left'], $box['width'], $box['bottom']])->toBe([0, 0, $box['vw'], $box['vh']]);

    $page->assertScript('[...document.querySelectorAll(\'[data-test="invite-dialog"] button\')].some((button) => button.textContent.trim() === "Send invitation" && button.getBoundingClientRect().bottom <= document.querySelector(\'[data-test="invite-dialog"]\').getBoundingClientRect().bottom && button.getBoundingClientRect().bottom > 0)');
});

it('asks to revoke an invitation from the foot of a phone\'s screen', function (): void {
    // Ivy is invited as the screen invites.
    $this->withElevation();
    app(InviteUser::class)->execute(new InviteUserInput(name: 'Ivy Invited', email: 'ivy@example.com', role: 'contributor', actorId: (string) $this->admin->id, deliver: false));
    $this->app->instance(Elevation::class, new DeniedElevation);
    $this->actingAs($this->admin)->get(route('users.index'))->assertOk();

    $page = cmNavigate(cmSignIn($this->admin)->resize(375, 667), '/admin/users?view=invited');
    cmWaitUntil($page, 'document.querySelector(\'[aria-label="More actions for Ivy Invited"]\') !== null');
    // Revoking is in the invited row's menu; the row's own line holds Send again and Change role….
    $page->click('[aria-label="More actions for Ivy Invited"]');
    shellSettled($page, '[role="menu"]');
    $page->click('[role="menu"] >> text="Revoke invitation"');
    cmWaitUntil($page, 'document.querySelector(\'[role="dialog"]\')?.textContent.includes("Revoke the invitation to ivy@example.com?") === true');
    shellSettled($page, '[data-slot="dialog-content"]');

    $box = poBox($page, '[data-slot="dialog-content"]');

    expect($box['bottom'])->toBe($box['vh'])
        ->and($box['top'])->toBeGreaterThan(0);

    $page->click('[role="dialog"] >> text="Cancel"');
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="dialog-content"]\') === null');

    expect(User::query()->where('email', 'ivy@example.com')->exists())->toBeTrue();
});

it('fills a phone\'s screen with the status sheet at 600 pixels', function (): void {
    $page = cmSignIn($this->admin)->resize(600, 800);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="status-button"]\') !== null');

    $page->click('[data-test="status-button"]');
    shellSettled($page, '[data-test="status-sheet"]');

    $box = poBox($page, '[data-test="status-sheet"]');

    expect($box['width'])->toBe($box['vw']);
});

it('opens the account menu as a sheet from the foot of a phone\'s screen, and signs out from it', function (): void {
    $page = cmSignIn($this->admin)->resize(375, 667);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="account-menu-button"]\') !== null');

    $page->click('[data-test="account-menu-button"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="sheet-content"] [role="menu"]\') !== null');
    shellSettled($page, '[data-slot="sheet-content"]');

    $box = poBox($page, '[data-slot="sheet-content"]');

    expect($box['bottom'])->toBe($box['vh']);

    $page->assertScript('[...document.querySelectorAll(\'[data-slot="sheet-content"] [role="menu"] [role="menuitem"]\')].some((item) => item.textContent.trim() === "Profile")')
        ->assertScript('document.querySelector(\'[data-slot="sheet-content"] [role="menu"] [data-test="logout-button"]\')?.getAttribute("role")', 'menuitem')
        ->click('[data-test="logout-button"]');

    // Signing out leaves the admin for the sign-in page in a full visit, never a frame over the admin (#509).
    cmWaitUntil($page, "location.pathname === '/admin/login' && document.querySelector('[data-test=\"login-button\"]') !== null")
        ->assertScript("document.querySelector('iframe') === null && document.querySelector('[data-slot=\"sheet-content\"]') === null");
    cmNavigate($page, '/admin')->assertPathIs('/admin/login');
});

it('opens the account pages as a sheet from the foot of a phone\'s screen', function (): void {
    $this->actingAs($this->admin)->get(route('notifications.edit'))->assertOk();

    $page = cmNavigate(cmSignIn($this->admin)->resize(375, 667), '/admin/account/notifications');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="account-pages"]\') !== null');

    $page->click('[data-test="account-pages"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="sheet-content"] [role="menu"]\') !== null');
    shellSettled($page, '[data-slot="sheet-content"]');

    $page->assertScript('document.querySelector(\'[data-slot="sheet-content"] [data-slot="sheet-title"]\').textContent', 'Account pages')
        ->assertScript('document.querySelectorAll(\'[data-slot="sheet-content"] [role="menu"] a\').length', 5)
        ->assertAttribute('[data-slot="sheet-content"] [role="menu"] a[href$="/admin/account/notifications"]', 'aria-current', 'page');
});

it('keeps the account menu inside the top bar from 640 up', function (): void {
    $page = cmSignIn($this->admin)->resize(1280, 900);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="account-menu-button"]\') !== null');

    $page->click('[data-test="account-menu-button"]');
    cmWaitUntil($page, 'document.querySelector(\'[role="menu"]\') !== null');

    $page->assertScript('document.querySelector(\'[role="menu"]\').closest("header") !== null && document.querySelector(\'[role="menu"]\').parentElement !== document.body')
        ->assertScript('document.querySelector(\'[data-slot="sheet-content"]\') === null');
});
