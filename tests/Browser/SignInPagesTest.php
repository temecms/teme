<?php

declare(strict_types=1);

use App\Http\Admin\Auth\SignInThrottle;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

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

    cmSettings(['site_title' => 'The Garden City Ledger'], 'general');
    $this->editor = User::factory()->create(['name' => 'Eve Editor', 'email' => 'eve@example.com'])->assignRole('editor');
});

/** Whether the page scrolls sideways: its content wider than the window. */
function spNoSidewaysScroll(mixed $page): bool
{
    return (bool) $page->script('() => document.documentElement.scrollWidth <= window.innerWidth');
}

/** The page in the given scheme, at the given size. */
function spVisit(string $url, string $scheme = 'light', int $width = 1280, int $height = 900): mixed
{
    $page = visit($url);
    $page = $scheme === 'dark' ? $page->inDarkMode() : $page->inLightMode();

    return $page->resize($width, $height);
}

it('draws sign-in on one card at most 420 pixels wide, the site\'s mark at its head, the credit under it', function (): void {
    $page = spVisit('/admin/login');
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="auth-card"]\') !== null');

    expect($page->script('() => Math.round(document.querySelector(\'[data-slot="auth-card"]\').getBoundingClientRect().width)'))->toBe(420)
        ->and($page->script('() => { const card = document.querySelector(\'[data-slot="auth-card"]\'); const brand = card.querySelector(\'[data-test="sign-in-brand"]\'); const h1 = card.querySelector("h1"); return brand !== null && h1 !== null && h1.textContent === "Sign in" && (brand.compareDocumentPosition(h1) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0; }'))->toBeTrue()
        ->and($page->script('() => { const card = document.querySelector(\'[data-slot="auth-card"]\'); const credit = document.querySelector(\'[data-test="powered-by"]\'); return credit !== null && !card.contains(credit) && (card.compareDocumentPosition(credit) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0; }'))->toBeTrue();

    $page->assertSeeIn('[data-test="sign-in-brand"]', 'The Garden City Ledger')
        ->assertSeeIn('[data-test="brand-mark"]', 'T');
});

it('fits a 320 pixel screen: no sideways scroll, the card at the top, 16 pixel inputs and 44 pixel links', function (): void {
    $page = spVisit('/admin/login', 'light', 320, 640);
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="auth-card"]\') !== null');

    expect(spNoSidewaysScroll($page))->toBeTrue()
        ->and($page->script('() => document.querySelector(\'[data-slot="auth-card"]\').getBoundingClientRect().top'))->toBeLessThan(24)
        ->and($page->script('() => getComputedStyle(document.querySelector("#email")).fontSize'))->toBe('16px')
        ->and($page->script('() => [...document.querySelectorAll("a")].find((link) => link.textContent.trim() === "Forgot your password?").getBoundingClientRect().height'))->toBeGreaterThanOrEqual(44);
});

it('names the password\'s show and hide button, reaches it by Tab, and shows what is typed', function (): void {
    $page = spVisit('/admin/login');

    $page->type('password', 'a-secret')
        ->keys('#password', 'Tab');

    expect($page->script('() => document.activeElement.getAttribute("aria-label")'))->toBe('Show password')
        ->and($page->script('() => document.activeElement.getAttribute("aria-pressed")'))->toBe('false')
        ->and($page->script('() => document.activeElement.getAttribute("aria-controls")'))->toBe('password');

    $page->keys('[aria-controls="password"]', 'Enter');
    cmWaitUntil($page, 'document.querySelector("#password").getAttribute("type") === "text"');

    expect($page->script('() => document.querySelector(\'[aria-controls="password"]\').getAttribute("aria-label")'))->toBe('Hide password')
        ->and($page->script('() => document.querySelector(\'[aria-controls="password"]\').getAttribute("aria-pressed")'))->toBe('true');
});

it('names a wrong email or password on the field', function (): void {
    $page = spVisit('/admin/login');

    $page->type('email', $this->editor->email)
        ->type('password', 'not-the-password')
        ->click('[data-test="login-button"]');

    cmWaitUntil($page, 'document.querySelector("#email-error") !== null');

    expect($page->script('() => document.querySelector("#email-error").textContent'))->toContain(__('auth.failed'))
        ->and($page->script('() => document.querySelector("#email").getAttribute("aria-invalid")'))->toBe('true');
});

it('counts a paused sign-in down to zero, then lets the person try again', function (): void {
    $key = SignInThrottle::accountKey($this->editor->email);

    foreach (range(1, 10) as $attempt) {
        RateLimiter::hit($key, 3);
    }

    $page = spVisit('/admin/login');
    $page->type('email', $this->editor->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]');

    cmWaitUntil($page, 'document.querySelector(\'[data-test="sign-in-paused"]\') !== null');

    expect($page->script('() => document.querySelector(\'[data-test="sign-in-paused"]\').getAttribute("role")'))->toBe('alert')
        ->and($page->script('() => document.querySelector(\'[data-test="sign-in-paused-wait"]\').textContent'))->toMatch('/Wait [123] seconds?, then try again\./')
        ->and($page->script('() => document.querySelector(\'[data-test="login-button"]\').disabled'))->toBeTrue()
        ->and($page->script('() => document.querySelector("#email-error") === null'))->toBeTrue();

    cmWaitUntil($page, 'document.querySelector(\'[data-test="sign-in-paused"]\') === null && !document.querySelector(\'[data-test="login-button"]\').disabled');

    $page->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin');
});

it('passes axe on sign-in in both schemes, at 1280 and 320 pixels, every target 24 pixels or more', function (string $scheme, int $width, int $height): void {
    $page = spVisit('/admin/login', $scheme, $width, $height);
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="auth-card"]\') !== null');

    $targets = cmSmallTargets($page);

    expect(cmAxeViolations($page))->toBe([])
        ->and($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with([
    ['light', 1280, 900],
    ['dark', 1280, 900],
    ['light', 320, 640],
    ['dark', 320, 640],
]);

/** The page a dataset row names, reached the way a person reaches it, in the scheme and size given. */
function spReach(string $which, string $scheme, int $width, int $height): mixed
{
    $through = function (User $person, string $scheme): mixed {
        $page = spVisit('/admin/login', $scheme);

        return $page->type('email', $person->email)
            ->type('password', 'password')
            ->click('[data-test="login-button"]');
    };

    $page = match ($which) {
        'forgot password' => spVisit('/admin/forgot-password', $scheme),
        'reset password' => spVisit(route('password.reset', ['token' => 'a-token', 'email' => test()->editor->email], false), $scheme),
        'accept an invitation' => spVisit(route('invitation.show', ['token' => 'a-token', 'email' => 'new@example.com'], false), $scheme),
        'two-factor' => $through(User::factory()->withTwoFactor()->create()->assignRole('editor'), $scheme)->assertPathIs('/admin/two-factor-challenge'),
        'verify email' => cmNavigate($through(User::factory()->unverified()->create()->assignRole('editor'), $scheme), route('verification.notice', [], false)),
        'confirm password' => cmNavigate($through(test()->editor, $scheme)->assertPathIs('/admin'), route('password.confirm', [], false)),
    };

    $page->resize($width, $height);
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="auth-card"] h1\') !== null');

    return $page;
}

it('draws every sign-in page on the one card, with no sideways scroll at 320 pixels and no axe violation in either scheme', function (string $which): void {
    foreach ([['light', 1280, 900], ['dark', 1280, 900], ['light', 320, 640], ['dark', 320, 640]] as [$scheme, $width, $height]) {
        $page = spReach($which, $scheme, $width, $height);
        $targets = cmSmallTargets($page);

        expect($page->script('() => document.querySelectorAll(\'[data-slot="auth-card"]\').length'))->toBe(1, "{$which}, {$scheme}, {$width}")
            ->and($page->script('() => document.querySelectorAll("h1").length === 1 && document.querySelector(\'[data-slot="auth-card"]\').contains(document.querySelector("h1"))'))->toBeTrue("{$which}, {$scheme}, {$width}")
            ->and(spNoSidewaysScroll($page))->toBeTrue("{$which}, {$scheme}, {$width}")
            ->and(cmAxeViolations($page))->toBe([], "{$which}, {$scheme}, {$width}")
            ->and($targets['small'])->toBe([], "{$which}, {$scheme}, {$width}")
            ->and($targets['checked'])->toBeGreaterThan(0);
    }
})->with(['forgot password', 'reset password', 'accept an invitation', 'two-factor', 'verify email', 'confirm password']);

it('draws the admin\'s 404 and 403 on the card, passing axe in both schemes with nothing small', function (string $scheme): void {
    // People is for administrators only, so an editor is refused it.
    foreach (['/admin/no-such-screen' => '404', route('users.index', [], false) => '403'] as $url => $code) {
        $page = spVisit('/admin/login', $scheme)
            ->type('email', $this->editor->email)
            ->type('password', 'password')
            ->click('[data-test="login-button"]')
            ->assertPathIs('/admin');

        cmNavigate($page, $url);
        cmWaitUntil($page, "document.querySelector('main[data-error=\"{$code}\"]') !== null");

        $targets = cmSmallTargets($page);

        expect(cmAxeViolations($page))->toBe([], "{$code}, {$scheme}")
            ->and($targets['small'])->toBe([], "{$code}, {$scheme}")
            ->and($targets['checked'])->toBeGreaterThan(0)
            ->and($page->script('() => document.querySelector(\'[data-test="powered-by"]\') === null'))->toBeTrue();

        $page->resize(320, 640);

        expect(spNoSidewaysScroll($page))->toBeTrue("{$code}, {$scheme}, 320");
    }
})->with(['light', 'dark']);
