<?php

declare(strict_types=1);

use App\Domain\Security\SecurityEventType;
use App\Domain\Settings\Models\SettingsChange;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Users\Operations\Input\SetUpSiteInput;
use App\Domain\Users\Operations\SetUpSite;
use App\Domain\Users\SetupCode;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

function suInput(array $overrides = []): SetUpSiteInput
{
    $values = [
        'code' => app(SetupCode::class)->forThisSite(),
        'siteTitle' => '  The Garden City Ledger ',
        'timezone' => 'Africa/Lagos',
        'name' => ' Ada Obi ',
        'email' => ' Ada@Example.com ',
        'password' => 'a-brand-new-long-passphrase',
        'passwordConfirmation' => 'a-brand-new-long-passphrase',
        ...$overrides,
    ];

    return new SetUpSiteInput(...$values);
}

/**
 * @return array{users: int, changes: int, events: int}
 */
function suWritten(): array
{
    return [
        'users' => User::query()->count(),
        'changes' => SettingsChange::query()->count(),
        'events' => DB::table('security_events')->count(),
    ];
}

it('makes the first administrator, verified, and names the site, its time zone and its notices address', function (): void {
    $result = app(SetUpSite::class)->execute(suInput());
    $user = User::query()->findOrFail($result['userId']);
    $settings = app(SettingsRepository::class);

    expect($user->name)->toBe('Ada Obi')
        ->and($user->email)->toBe('ada@example.com')
        ->and($user->hasRole('administrator'))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->invited_at)->toBeNull()
        ->and(Hash::check('a-brand-new-long-passphrase', $user->password))->toBeTrue()
        ->and($settings->string('general', 'site_title'))->toBe('The Garden City Ledger')
        ->and($settings->string('general', 'timezone'))->toBe('Africa/Lagos')
        ->and($settings->string('general', 'admin_email'))->toBe('ada@example.com')
        ->and(DB::table('security_events')->where('type', SecurityEventType::SiteSetUp->value)->where('user_id', $user->id)->exists())->toBeTrue();
});

it('writes nothing when the setup code is wrong', function (): void {
    $before = suWritten();

    expect(fn () => app(SetUpSite::class)->execute(suInput(['code' => 'AAAA-AAAA-AAAA-AAAA'])))
        ->toThrow(AuthorizationException::class)
        ->and(suWritten())->toBe($before);
});

it('writes nothing once any account exists, an invited one included', function (): void {
    User::factory()->create(['invited_at' => now()]);
    $before = suWritten();

    expect(fn () => app(SetUpSite::class)->execute(suInput()))
        ->toThrow(AuthorizationException::class)
        ->and(suWritten())->toBe($before);
});

it('writes nothing when another request makes the first account while this one waits', function (): void {
    $made = false;
    // The moment the Operation's own transaction opens: another request's
    // account appears after authorize() passed and before perform() checks.
    Event::listen(TransactionBeginning::class, function () use (&$made): void {
        if (! $made) {
            $made = true;
            User::factory()->create();
        }
    });

    expect(fn () => app(SetUpSite::class)->execute(suInput()))
        ->toThrow(AuthorizationException::class, 'This site is already set up.')
        ->and(User::query()->count())->toBe(0)
        ->and(SettingsChange::query()->count())->toBe(0);
});

it('takes the first-run lock before it checks, so two at once never both win', function (): void {
    $other = DB::connectUsing('first-run-other', [...DB::connection()->getConfig(), 'name' => 'first-run-other'], force: true);
    $other->select('SELECT pg_advisory_lock(hashtext(?))', ['teme.first-run']);
    DB::statement("SET statement_timeout = '1s'");

    try {
        expect(fn () => app(SetUpSite::class)->execute(suInput()))->toThrow(QueryException::class)
            ->and(User::query()->count())->toBe(0);
    } finally {
        DB::statement('SET statement_timeout = 0');
        $other->select('SELECT pg_advisory_unlock_all()');
        $other->disconnect();
    }
});

it('refuses what Settings would refuse, and a weak or mistyped password, writing nothing', function (array $overrides, string $field): void {
    $before = suWritten();

    try {
        app(SetUpSite::class)->execute(suInput($overrides));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $refusal) {
        expect($refusal->errors())->toHaveKey($field)
            ->and(suWritten())->toBe($before);
    }
})->with([
    'no site title' => [['siteTitle' => '   '], 'site_title'],
    'a title too long' => [['siteTitle' => str_repeat('a', 121)], 'site_title'],
    'no such time zone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'no time zone' => [['timezone' => ''], 'timezone'],
    'no name' => [['name' => ''], 'name'],
    'no email address' => [['email' => 'not-an-address'], 'email'],
    'a short password' => [['password' => 'short', 'passwordConfirmation' => 'short'], 'password'],
    'a mistyped confirmation' => [['passwordConfirmation' => 'a-different-long-passphrase'], 'password'],
]);
