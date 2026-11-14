<?php

declare(strict_types=1);

use App\Domain\Users\Notifications\InvitationLink;
use App\Domain\Users\Operations\Input\IssuePasswordResetLinkInput;
use App\Domain\Users\Operations\IssuePasswordResetLink;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Notification::fake();
});

it('creates the first administrator on a site with no users, and prints their link', function (): void {
    $this->artisan('teme:user:create', ['--name' => 'Ada Admin', '--email' => 'ada@example.com', '--role' => 'administrator'])
        ->expectsOutputToContain('/admin/invitation/')
        ->assertSuccessful();

    $ada = User::query()->where('email', 'ada@example.com')->sole();

    expect($ada->hasRole('administrator'))->toBeTrue()
        ->and($ada->invited_at)->not->toBeNull();
    Notification::assertNothingSent();
});

it('emails the link as well when asked', function (): void {
    $this->artisan('teme:user:create', ['--name' => 'Ada', '--email' => 'ada@example.com', '--send' => true])->assertSuccessful();

    Notification::assertSentTo(User::query()->where('email', 'ada@example.com')->sole(), InvitationLink::class);
});

it('reports validation problems and creates nothing', function (): void {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->artisan('teme:user:create', ['--name' => 'Ada', '--email' => 'ada@example.com'])
        ->expectsOutputToContain('already been taken')
        ->assertFailed();

    expect(User::query()->where('email', 'ada@example.com')->count())->toBe(1);
});

it('prints a working reset link for any account', function (): void {
    User::factory()->create(['email' => 'locked-out@example.com']);

    $this->artisan('teme:user:reset-link', ['email' => 'locked-out@example.com'])
        ->expectsOutputToContain('/admin/reset-password/')
        ->assertSuccessful();

    expect(DB::table('password_reset_tokens')->where('email', 'locked-out@example.com')->exists())->toBeTrue();
});

it('fails clearly for an unknown address', function (): void {
    $this->artisan('teme:user:reset-link', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No account uses that address')
        ->assertFailed();
});

it('refuses to issue a reset link for anyone but the operator', function (): void {
    User::factory()->create(['email' => 'locked-out@example.com']);

    expect(fn () => app(IssuePasswordResetLink::class)->execute(new IssuePasswordResetLinkInput('locked-out@example.com')))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});
