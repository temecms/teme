<?php

declare(strict_types=1);

use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * @param  list<string>  $roles
 */
function usersRequireTwoFactorFor(User $admin, array $roles): void
{
    test()->withElevation();
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('security', ['two_factor_required_roles' => $roles], [], [], 0, (string) $admin->id));
}

it('signs a suspended person straight out, with a message', function (): void {
    $suspended = User::factory()->suspended()->create()->assignRole('author');

    $this->actingAs($suspended)->get('/admin')->assertRedirect(route('login'));

    expect(session('status'))->toContain('suspended');
    $this->assertGuest();
});

it('requires two-factor for no role on a new install', function (): void {
    expect(app(SettingsReader::class)->stringList('security', 'two_factor_required_roles'))->toBe([]);

    $this->actingAs($this->admin)->get('/admin')->assertOk();
});

it('sends a member of a required role to set up two-factor, and nowhere else', function (): void {
    usersRequireTwoFactorFor($this->admin, ['editor']);
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->get('/admin')->assertRedirect(route('two-factor.edit'));
    $this->actingAs($editor)->get('/admin/account/profile')->assertRedirect(route('two-factor.edit'));
    $this->actingAs($editor)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get('/admin/account/two-factor')
        ->assertOk();
});

it('lets a member of a required role through once two-factor is confirmed', function (): void {
    usersRequireTwoFactorFor($this->admin, ['editor']);
    $editor = User::factory()->withTwoFactor()->create()->assignRole('editor');

    $this->actingAs($editor)->get('/admin')->assertOk();
});

it('leaves other roles alone', function (): void {
    usersRequireTwoFactorFor($this->admin, ['editor']);

    $this->actingAs($this->admin)->get('/admin')->assertOk();
});

it('refuses a role that does not exist', function (): void {
    expect(fn () => usersRequireTwoFactorFor($this->admin, ['overlord']))->toThrow(ValidationException::class);
});

it('stores the selection in a fixed order, without duplicates', function (): void {
    usersRequireTwoFactorFor($this->admin, ['editor', 'administrator', 'editor']);

    expect(app(SettingsReader::class)->stringList('security', 'two_factor_required_roles'))->toBe(['administrator', 'editor']);
});
