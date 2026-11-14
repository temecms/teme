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
    $this->author = User::factory()->create(['name' => 'Bea Author'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');

    // Ivy is invited as the screen invites, with her link and Ada's name in the log.
    $this->withElevation();
    app(InviteUser::class)->execute(new InviteUserInput(name: 'Ivy Invited', email: 'ivy@example.com', role: 'contributor', actorId: (string) $this->admin->id, deliver: false));
    $this->app->instance(Elevation::class, new DeniedElevation);

    // Signs Ada in and confirms her password first, as a sensitive change asks (I-1091).
    $this->signInConfirmed = fn (): mixed => cmSignIn($this->admin)
        ->navigate(route('password.confirm', [], false))
        ->type('#password', 'password')
        ->click('[data-test="confirm-password-button"]')
        ->assertPathIs('/admin');

    // The plugin's assertSee() and assertNotPresent() look once and answer at once (issue #83).
    $this->seen = fn (mixed $page, string $text): mixed => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text, JSON_THROW_ON_ERROR).')');
    $this->gone = fn (mixed $page, string $selector): mixed => cmWaitUntil($page, 'document.querySelector('.json_encode($selector, JSON_THROW_ON_ERROR).') === null');

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->admin)->get(route('users.index'))->assertOk();
});

it('shows every view with its count, and the invitations in a table of their own', function (): void {
    cmSignIn($this->admin)
        ->navigate('/admin/users')
        ->assertScript("[...document.querySelectorAll('nav[aria-label=\"Views\"] a')].map((tab) => tab.textContent.trim()).join('|')", 'All3|Administrators1|Editors1|Authors1|Contributors0|Suspended0|Invited1')
        ->assertScript("document.querySelector('table').innerText.includes('Ivy Invited')", false)
        ->navigate('/admin/users?view=invited')
        ->assertScript("document.querySelector('table caption').textContent", 'Pending invitations')
        ->assertScript("[...document.querySelectorAll('table thead th')].map((cell) => cell.textContent.trim()).join('|')", 'Name|Role|Invited|Link')
        ->assertScript("document.querySelector('table tbody').innerText.includes('by Ada Admin')", true)
        ->assertScript("document.querySelector('table tbody').innerText.includes('Works until')", true);
});

it('invites someone from the header\'s dialog, asking for the password in the dialog, and lands on Invited', function (): void {
    $page = cmSignIn($this->admin)
        ->navigate('/admin/users')
        ->click('[data-test="people-invite"]');

    shellSettled($page, '[data-test="invite-dialog"]')
        ->type('#invite-name', 'Noor New')
        ->type('#invite-email', 'noor@example.com')
        ->click('[data-test="invite-dialog"] >> text="Send invitation"');

    // The dialog asks in place, so nothing typed is lost and no page is left.
    cmWaitUntil($page, "document.getElementById('invite-password') !== null")
        ->assertPathIs('/admin/users')
        ->assertScript("document.getElementById('invite-email').value", 'noor@example.com')
        ->type('#invite-password', 'password')
        ->click('[data-test="invite-password-confirm"]');

    ($this->seen)($page, 'Invitation sent to noor@example.com.')
        ->assertScript("new URLSearchParams(window.location.search).get('view')", 'invited')
        ->assertScript("document.querySelector('table').innerText.includes('Noor New')", true);

    // Contributor, the least access, unless another role is chosen.
    expect(User::query()->where('email', 'noor@example.com')->sole()->hasRole('contributor'))->toBeTrue();
});

it('shows the error under the email field when the password is given and the address is already in use', function (): void {
    $page = cmSignIn($this->admin)
        ->navigate('/admin/users')
        ->click('[data-test="people-invite"]');

    shellSettled($page, '[data-test="invite-dialog"]')
        ->type('#invite-name', 'Bea Again')
        ->type('#invite-email', $this->author->email)
        ->click('[data-test="invite-dialog"] >> text="Send invitation"');

    cmWaitUntil($page, "document.getElementById('invite-password') !== null")
        ->type('#invite-password', 'password')
        ->click('[data-test="invite-password-confirm"]');

    cmWaitUntil($page, "(document.querySelector('#invite-email-error')?.textContent ?? '').length > 0")
        ->assertScript("document.querySelector('[data-test=\"invite-dialog\"]') !== null", true)
        ->assertScript("document.getElementById('invite-email').value", $this->author->email);

    expect(User::query()->where('email', $this->author->email)->count())->toBe(1);
});

it('keeps the invite dialog open, with the error under its field, for an address already in use', function (): void {
    $page = ($this->signInConfirmed)()
        ->navigate('/admin/users')
        ->click('[data-test="people-invite"]');

    shellSettled($page, '[data-test="invite-dialog"]')
        ->type('#invite-name', 'Bea Again')
        ->type('#invite-email', $this->author->email)
        ->click('[data-test="invite-dialog"] >> text="Send invitation"');

    cmWaitUntil($page, "(document.querySelector('#invite-email-error')?.textContent ?? '').length > 0")
        ->assertScript("document.querySelector('[data-test=\"invite-dialog\"]') !== null", true)
        ->assertScript("document.getElementById('invite-email').value", $this->author->email);

    expect(User::query()->where('email', $this->author->email)->count())->toBe(1);
});

it('changes a role in a dialog from the row\'s menu, and says what happened, on the view it was on', function (): void {
    $page = ($this->signInConfirmed)()
        ->navigate('/admin/users?view=author')
        ->click('[aria-label="Actions for Bea Author"] >> text="Change role…"');

    ($this->gone)($page, '[role="menu"]');

    shellSettled($page, '[data-test="role-dialog"]')
        ->assertScript("document.querySelector('[data-test=\"role-dialog\"] h2')?.textContent", 'Change the role of Bea Author')
        ->assertScript("document.getElementById('change-role-hint').textContent", 'They’re signed out everywhere, so their next sign-in uses the new role.')
        ->click('#change-role')
        ->click('[role="option"] >> text="Editor"');

    ($this->gone)($page, '[role="listbox"]')
        ->click('[data-test="role-dialog"] >> text="Change role"');

    ($this->seen)($page, 'Role changed. Bea Author is now an editor and was signed out everywhere.')
        ->assertScript("new URLSearchParams(window.location.search).get('view')", 'author');

    expect($this->author->refresh()->hasRole('editor'))->toBeTrue();
});

it('locks your own row\'s Change role…, saying why, and offers no menu there', function (): void {
    $page = cmSignIn($this->admin)
        ->navigate('/admin/users');

    cmWaitUntil($page, "document.querySelector('[data-test=\"own-row-lock\"]') !== null && document.querySelector('[aria-label=\"Actions for Ada Admin\"]') === null")
        ->assertAttribute('[data-test="own-row-lock"]', 'aria-disabled', 'true')
        ->assertAttribute('[data-test="own-row-lock"]', 'aria-describedby', 'own-role-reason')
        ->assertScript("document.getElementById('own-role-reason').textContent", 'You cannot change your own role. Ask another administrator.')
        // Pressed from the keyboard's focus, through the page itself: a locked
        // button is one a browser driver refuses to click, by design.
        ->assertScript("(() => { const lock = document.querySelector('[data-test=\"own-row-lock\"]'); lock.focus(); lock.click(); return document.activeElement === lock && document.querySelector('[role=\"dialog\"]') === null; })()", true)
        ->click('a >> text="Manage yourself in Account"')
        ->assertPathIs(route('profile.edit', [], false));
});

it('revokes an invitation after a confirmation that names it, without asking for the password', function (): void {
    $page = cmSignIn($this->admin)
        ->navigate('/admin/users?view=invited')
        ->click('[aria-label="Actions for Ivy Invited"] >> text="Revoke invitation"');

    ($this->seen)($page, 'Revoke the invitation to ivy@example.com?')
        // The menu stays open behind the confirmation; click inside the dialog once its own parts have settled.
        ->assertScript("(() => { const parts = [...document.querySelectorAll('[data-slot=\"dialog-content\"], [data-slot=\"dialog-overlay\"]')]; return parts.length === 0 ? -1 : parts.flatMap((element) => element.getAnimations()).filter((animation) => animation.playState === 'running').length; })()", 0)
        ->click('[role="dialog"] >> text="Revoke invitation"');

    ($this->seen)($page, 'Revoked the invitation to ivy@example.com.');
    ($this->seen)($page, 'No pending invitations');

    expect(User::query()->where('email', 'ivy@example.com')->exists())->toBeFalse();
});

it('asks in the delete dialog who should own their content, and says their name stays', function (): void {
    $page = ($this->signInConfirmed)()
        ->navigate('/admin/users?view=author')
        ->click('[aria-label="Actions for Bea Author"] >> text="Delete…"');

    ($this->gone)($page, '[role="menu"]');

    shellSettled($page, '[data-test="delete-dialog"]')
        ->assertScript("document.querySelector('[data-test=\"delete-dialog\"] label[for^=\"reassign-\"]')?.textContent", 'Give their content to')
        ->assertScript("document.querySelector('[data-test=\"delete-dialog\"] [id^=\"reassign-\"][id$=\"-hint\"]')?.textContent", 'Their name stays on what they wrote.');
});

it('passes axe on People, with the change-role dialog open, and on Invited, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)
        ->navigate('/admin/users')
        ->assertNoAccessibilityIssues(3);

    $list = cmSmallTargets($page);

    $page->click('[aria-label="Actions for Bea Author"] >> text="Change role…"');

    ($this->gone)($page, '[role="menu"]');

    // The dialog and its overlay fade in, and axe doesn't retry: it would read their colours mid-fade.
    shellSettled($page, '[data-test="role-dialog"]')
        ->assertScript("[...document.querySelectorAll('[data-slot=\"dialog-overlay\"], [role=\"dialog\"]')].map((element) => getComputedStyle(element).opacity).join(' ')", '1 1')
        ->assertNoAccessibilityIssues(3);

    $dialog = cmSmallTargets($page);

    $page->navigate('/admin/users?view=invited')
        ->assertNoAccessibilityIssues(3);

    $invited = cmSmallTargets($page);

    expect($list['small'])->toBe([])
        ->and($list['checked'])->toBeGreaterThan(0)
        ->and($dialog['small'])->toBe([])
        ->and($dialog['checked'])->toBeGreaterThan(0)
        ->and($invited['small'])->toBe([])
        ->and($invited['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);
