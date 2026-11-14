<?php

declare(strict_types=1);

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Support\Security\SessionElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

final readonly class ProbeInput implements OperationInput
{
    public function __construct(public ?string $actorId, public bool $allowed = true) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}

final class ProbeSensitiveOperation extends BaseOperation implements RequiresElevation
{
    public static int $performed = 0;

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function authorize(OperationInput $input): void
    {
        /** @var ProbeInput $input */
        if (! $input->allowed) {
            throw new AuthorizationException;
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        self::$performed++;

        return null;
    }
}

beforeEach(fn () => ProbeSensitiveOperation::$performed = 0);

function sessionElevationFor(User $user, ?int $confirmedSecondsAgo): SessionElevation
{
    $request = Request::create('/admin');
    $request->setLaravelSession($session = app('session.store'));
    $request->setUserResolver(fn (): User => $user);

    if ($confirmedSecondsAgo !== null) {
        $session->put('auth.password_confirmed_at', now()->getTimestamp() - $confirmedSecondsAgo);
    }

    return new SessionElevation($request, app(SettingsReader::class));
}

it('refuses by default — queue jobs, API and AI can never elevate', function (): void {
    expect(fn () => app(ProbeSensitiveOperation::class)->execute(new ProbeInput('1')))
        ->toThrow(ElevationRequiredException::class);

    expect(ProbeSensitiveOperation::$performed)->toBe(0);
});

it('checks authorization before elevation', function (): void {
    expect(fn () => app(ProbeSensitiveOperation::class)->execute(new ProbeInput('1', allowed: false)))
        ->toThrow(AuthorizationException::class);
});

it('runs for the operator', function (): void {
    $this->withElevation();

    app(ProbeSensitiveOperation::class)->execute(new ProbeInput('1'));

    expect(ProbeSensitiveOperation::$performed)->toBe(1);
});

it('accepts a confirmation from 14 minutes ago and refuses one from 16', function (): void {
    $user = User::factory()->create();

    sessionElevationFor($user, 14 * 60)->ensure(new ProbeInput((string) $user->id));

    expect(fn () => sessionElevationFor($user, 16 * 60)->ensure(new ProbeInput((string) $user->id)))
        ->toThrow(ElevationRequiredException::class);
});

it('refuses a session that never confirmed', function (): void {
    $user = User::factory()->create();

    expect(fn () => sessionElevationFor($user, null)->ensure(new ProbeInput((string) $user->id)))
        ->toThrow(ElevationRequiredException::class);
});

it('refuses when the actor is not the signed-in user', function (): void {
    $user = User::factory()->create();

    expect(fn () => sessionElevationFor($user, 60)->ensure(new ProbeInput('999999')))
        ->toThrow(ElevationRequiredException::class);
});

it('sends an unconfirmed security-settings change to password confirmation and writes nothing', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->getTimestamp()])
        ->from('/admin/settings/security')
        ->put('/admin/settings/security', ['values' => ['password_min_length' => 20], 'expected_version' => 0])
        ->assertRedirect(route('password.confirm'));

    expect(app(SettingsReader::class)->int('security', 'password_min_length'))->toBe(15)
        ->and(session('url.intended'))->toEndWith('/admin/settings/security');
});

it('saves a confirmed security-settings change', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->put('/admin/settings/security', ['values' => ['password_min_length' => 20], 'expected_version' => 0])
        ->assertSessionHasNoErrors();

    expect(app(SettingsReader::class)->int('security', 'password_min_length'))->toBe(20);
});

it('asks for confirmation to change an email address, but not a name', function (): void {
    $user = User::factory()->create(['name' => 'Old', 'email' => 'old@example.com']);

    $this->actingAs($user)
        ->patch('/admin/account/profile', ['name' => 'New', 'email' => 'old@example.com'])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->patch('/admin/account/profile', ['name' => 'New', 'email' => 'new@example.com'])
        ->assertRedirect(route('password.confirm'));

    expect($user->refresh()->email)->toBe('old@example.com');
});

it('answers 423 to a JSON request that needs confirmation', function (): void {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($user)
        ->patchJson('/admin/account/profile', ['name' => 'New', 'email' => 'new@example.com'])
        ->assertStatus(423);
});
