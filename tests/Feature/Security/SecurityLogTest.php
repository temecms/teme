<?php

declare(strict_types=1);

use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Users\Notifications\ResetPasswordLink;
use App\Domain\Users\Operations\ChangePassword;
use App\Domain\Users\Operations\Input\ChangePasswordInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['email' => 'editor@example.com', 'password' => 'correct-horse-battery-staple']);
});

/**
 * @return Collection<int, object>
 */
function seclogRows(string $type): Collection
{
    return DB::table('security_events')->where('type', $type)->get();
}

function seclogSignIn(string $email, string $password): void
{
    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->post('/admin/login', ['email' => $email, 'password' => $password]);
    Auth::forgetGuards();
}

it('counts failed sign-ins in one row per account, address and hour', function (): void {
    foreach (range(1, 3) as $attempt) {
        seclogSignIn('editor@example.com', 'wrong-horse-battery-staple');
    }

    $rows = seclogRows('signin.failed');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->count)->toBe(3)
        ->and($rows->first()->subject)->toBe('user:'.$this->user->id)
        ->and($rows->first()->ip)->toBe('198.51.100.20');
});

it('never stores an address that has no account', function (): void {
    seclogSignIn('nobody-here@example.com', 'wrong-horse-battery-staple');

    $row = seclogRows('signin.failed')->sole();

    expect($row->subject)->toBe(SecurityLog::subject(null, 'nobody-here@example.com'))
        ->and(json_encode(DB::table('security_events')->get()))->not->toContain('nobody-here');
});

it('records each successful sign-in as its own row', function (): void {
    seclogSignIn('editor@example.com', 'correct-horse-battery-staple');
    $this->post('/admin/logout');
    Auth::forgetGuards();
    seclogSignIn('editor@example.com', 'correct-horse-battery-staple');

    expect(seclogRows('signin.succeeded'))->toHaveCount(2);
});

it('records Fortify\'s two-factor events — I-100\'s second safeguard', function (): void {
    event(new TwoFactorAuthenticationConfirmed($this->user));

    expect(seclogRows('two_factor.enabled')->sole()->user_id)->toBe($this->user->id);
});

it('records a password change', function (): void {
    app(ChangePassword::class)->execute(new ChangePasswordInput(
        userId: (string) $this->user->id,
        currentPassword: 'correct-horse-battery-staple',
        password: 'a-brand-new-long-passphrase',
        passwordConfirmation: 'a-brand-new-long-passphrase',
        actorId: (string) $this->user->id,
    ));

    expect(seclogRows('password.changed'))->toHaveCount(1);
});

it('records security-settings changes with the keys changed, and no other group', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    $this->withElevation();

    app(UpdateSettings::class)->execute(new UpdateSettingsInput('security', ['password_min_length' => 20], [], [], 0, (string) $admin->id));
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['site_title' => 'Plant Notes'], [], [], 0, (string) $admin->id));

    $row = seclogRows('settings.security_changed')->sole();

    expect(json_decode($row->context, true))->toEqual(['keys' => ['password_min_length'], 'by' => $admin->id])
        ->and(DB::table('security_events')->where('type', 'like', 'settings.%')->count())->toBe(1);
});

it('records a reset email that failed to send', function (): void {
    (new ResetPasswordLink('token', $this->user->id))->failed(new RuntimeException('SMTP refused'));

    expect(seclogRows('mail.reset_failed')->sole()->user_id)->toBe($this->user->id);
});

it('prunes rows older than the retention period', function (): void {
    app(SecurityLog::class)->record(SecurityEventType::SignInSucceeded, $this->user->id);
    DB::table('security_events')->update(['last_at' => now()->subDays(400)]);
    app(SecurityLog::class)->record(SecurityEventType::SignInSucceeded, $this->user->id);

    $this->artisan('teme:security-log:prune')->assertSuccessful();

    expect(DB::table('security_events')->count())->toBe(1);
});
