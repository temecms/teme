<?php

declare(strict_types=1);

use App\Domain\Users\SetupCode;
use App\Http\Middleware\HoldUntilSetUp;
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
    config(['teme.theme_paths' => []]);
    themeActivate('default');

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->seen = fn (mixed $page, string $text): mixed => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text).')');
});

it('passes axe on the setup page, with nothing small, in the light and the dark scheme', function (string $scheme): void {
    $page = visit('/admin/setup');
    $page = $scheme === 'dark' ? $page->inDarkMode() : $page->inLightMode();
    ($this->seen)($page, 'Set up your site');

    $small = cmSmallTargets($page);

    expect(cmAxeViolations($page))->toBe([])
        ->and($small['small'])->toBe([])
        ->and($small['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('holds the public site with core’s page, which passes axe with nothing small and leads to the setup page', function (string $scheme): void {
    // The suite runs with the hold off (tests/TestCase.php); this case is about it.
    $this->withMiddleware(HoldUntilSetUp::class);

    $page = visit('/');
    $page = $scheme === 'dark' ? $page->inDarkMode() : $page->inLightMode();
    ($this->seen)($page, 'This site is being set up and will open soon.');

    $small = cmSmallTargets($page);

    expect(cmAxeViolations($page))->toBe([])
        ->and($small['small'])->toBe([])
        ->and($small['checked'])->toBeGreaterThan(0)
        ->and($page->script('() => document.querySelector("main[data-holding]") !== null'))->toBeTrue();

    $page->click('Set up the site');
    ($this->seen)($page, 'Set up your site');
    $page->assertPathIs('/admin/setup');
})->with(['light', 'dark']);

it('leads from the sign-in page to the setup page, makes the first administrator and lands on the dashboard', function (): void {
    $this->withMiddleware(HoldUntilSetUp::class);

    $page = visit('/admin/login');
    ($this->seen)($page, 'This site has no accounts yet.');
    // The admin's notice icon beside its words.
    expect($page->script('() => document.querySelector(\'[data-test="first-run-notice"] svg[aria-hidden="true"]\') !== null'))->toBeTrue();

    $page->click('Set up the site');
    ($this->seen)($page, 'Set up your site');
    $page->assertPathIs('/admin/setup')
        ->type('code', app(SetupCode::class)->forThisSite())
        ->type('site_title', 'The Garden City Ledger')
        ->type('name', 'Ada Obi')
        ->type('email', 'ada@example.com')
        ->type('password', 'a-brand-new-long-passphrase')
        ->type('password_confirmation', 'a-brand-new-long-passphrase')
        ->click('[data-test="set-up-button"]');

    ($this->seen)($page, 'Your site is set up. Next, turn on two-factor sign-in in Account › Security.');
    $page->assertPathIs('/admin');

    expect(User::query()->sole()->email)->toBe('ada@example.com');

    // The hold gives way to the theme's site at once.
    $page->navigate('/');
    ($this->seen)($page, 'The Garden City Ledger');
    expect($page->script('() => document.querySelector("main[data-holding]") === null'))->toBeTrue();
});

it('says a wrong code is wrong, under its field, and stays on the setup page', function (): void {
    $page = visit('/admin/setup');
    ($this->seen)($page, 'Set up your site');

    $page->type('code', 'AAAA-AAAA-AAAA-AAAA')
        ->type('site_title', 'The Garden City Ledger')
        ->type('name', 'Ada Obi')
        ->type('email', 'ada@example.com')
        ->type('password', 'a-brand-new-long-passphrase')
        ->type('password_confirmation', 'a-brand-new-long-passphrase')
        ->click('[data-test="set-up-button"]');

    ($this->seen)($page, 'That setup code isn’t right.');
    expect($page->script('() => document.querySelector("#code").getAttribute("aria-invalid")'))->toBe('true')
        ->and($page->script('() => document.querySelector("#code").getAttribute("aria-describedby").split(" ").includes("code-error")'))->toBeTrue()
        ->and($page->script('() => document.querySelector("#site_title").hasAttribute("aria-invalid")'))->toBeFalse()
        ->and($page->script('() => document.querySelector("#code-hint code")?.textContent'))->toBe('php artisan teme:setup-code');

    $page->assertPathIs('/admin/setup')
        ->assertSeeIn('#code-error', 'That setup code isn’t right. Copy it from the deploy log, or run php artisan teme:setup-code on the server.');

    expect(User::query()->count())->toBe(0);
});
