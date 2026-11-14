<?php

declare(strict_types=1);

use App\Domain\Users\SetupCode;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    RateLimiter::clear('first-run:127.0.0.1');
});

/**
 * @return array<string, string>
 */
function frForm(array $overrides = []): array
{
    return [
        'code' => app(SetupCode::class)->forThisSite(),
        'site_title' => 'The Garden City Ledger',
        'timezone' => 'Africa/Lagos',
        'name' => 'Ada Obi',
        'email' => 'ada@example.com',
        'password' => 'a-brand-new-long-passphrase',
        'password_confirmation' => 'a-brand-new-long-passphrase',
        ...$overrides,
    ];
}

it('shows the setup page while no account exists', function (): void {
    $this->get(route('first-run.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/set-up')
            ->where('timezones', DateTimeZone::listIdentifiers())
            ->has('passwordRules'));
});

it('points the sign-in page to the setup page while no account exists, and only then', function (): void {
    $this->get(route('login'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('firstRun', true));

    User::factory()->create();

    $this->get(route('login'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('firstRun', false));
});

it('signs the new administrator in and lands on the dashboard', function (): void {
    $this->post(route('first-run.store'), frForm())
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.message', 'Your site is set up. Next, turn on two-factor sign-in in Account › Security.');

    $this->assertAuthenticatedAs(User::query()->sole());
});

it('says the code is wrong, keeps the visitor out and writes nothing', function (): void {
    $this->post(route('first-run.store'), frForm(['code' => 'AAAA-AAAA-AAAA-AAAA']))
        ->assertSessionHasErrors(['code' => 'That setup code isn’t right. Copy it from the deploy log, or run php artisan teme:setup-code on the server.']);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0)
        ->and(session()->getOldInput('code'))->toBeNull();
});

it('stops guessing after five wrong codes a minute', function (): void {
    foreach (range(1, 5) as $try) {
        $this->post(route('first-run.store'), frForm(['code' => 'AAAA-AAAA-AAAA-AAAA']));
    }

    $this->post(route('first-run.store'), frForm())
        ->assertSessionHasErrors('code');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0)
        ->and(session('errors')->first('code'))->toStartWith('Too many tries.');
});

it('still stops guessing when each wrong code comes with an invalid email', function (): void {
    foreach (range(1, 5) as $try) {
        // Whichever field it names, a wrong code's try is never given back.
        $this->post(route('first-run.store'), frForm(['code' => 'AAAA-AAAA-AAAA-AAAA', 'email' => 'not-an-address']))
            ->assertSessionHasErrors();
    }

    $this->post(route('first-run.store'), frForm())
        ->assertSessionHasErrors('code');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0)
        ->and(session('errors')->first('code'))->toStartWith('Too many tries.');
});

it('lets the owner fix a mistyped password more than five times with the right code', function (): void {
    foreach (range(1, 6) as $try) {
        $this->post(route('first-run.store'), frForm(['password_confirmation' => 'a-different-long-passphrase']))
            ->assertSessionHasErrors('password')
            ->assertSessionDoesntHaveErrors('code');
    }

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('closes for good once any account exists, by this page or the command', function (string $how): void {
    $how === 'page'
        ? $this->post(route('first-run.store'), frForm())
        : User::factory()->create(['invited_at' => now()]);
    auth()->logout();

    $this->get(route('first-run.show'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This site is already set up. Sign in to continue.');

    $this->post(route('first-run.store'), frForm(['email' => 'someone@example.com']))
        ->assertRedirect(route('login'));

    expect(User::query()->where('email', 'someone@example.com')->exists())->toBeFalse();
})->with(['page', 'command']);

it('is not offered to someone signed in', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('first-run.show'))
        ->assertRedirect();
});

it('answers a list where a field expects text with a field error, never a server error, and counts the try', function (string $field): void {
    $this->post(route('first-run.store'), frForm([$field => ['x']]))
        ->assertRedirect()
        ->assertSessionHasErrors($field);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0)
        ->and(RateLimiter::attempts('first-run:127.0.0.1'))->toBe(1);
})->with(['code', 'email']);
