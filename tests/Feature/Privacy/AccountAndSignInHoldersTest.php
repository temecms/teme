<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyErasureInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyExportInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyErasure;
use App\Domain\Privacy\Operations\RunPrivacyExport;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Users\Privacy\AccountHolder;
use App\Domain\Users\Privacy\SignInHolder;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Privacy\PersonalDataSubject;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), [AccountHolder::class, SignInHolder::class]));

    $this->ada = User::factory()->create([
        'name' => 'Ada Okafor',
        'email' => 'Ada@Example.com',
        'email_verified_at' => '2026-10-01 09:00:00',
    ]);
    DB::table('users')->where('id', $this->ada->id)->update([
        'slug' => 'ada-okafor',
        'bio' => 'Grows tomatoes.',
        'two_factor_secret' => 'SECRET-THAT-MUST-NOT-LEAVE',
        'two_factor_recovery_codes' => 'RECOVERY-CODES-THAT-MUST-NOT-LEAVE',
        'two_factor_confirmed_at' => now(),
        'preferences' => json_encode(['editor.spellcheck' => false, 'appearance.mode' => 'dark', 'nav.hidden' => []]),
    ]);
    $this->ada->refresh();

    $this->session = fn (User $user, int $lastActivity, string $ip): bool => DB::table('sessions')->insert([
        'id' => bin2hex(random_bytes(10)), 'user_id' => $user->id, 'ip_address' => $ip, 'user_agent' => 'Firefox',
        'payload' => 'SESSION-PAYLOAD', 'last_activity' => $lastActivity,
    ]);

    $this->run = function (string $kind, string $email = 'ada@example.com'): PrivacyRequest {
        $id = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput($kind, $email, 'Asked by email.', false));

        for ($steps = 0; $steps < 20; $steps++) {
            $step = $kind === 'export'
                ? app(RunPrivacyExport::class)->execute(new RunPrivacyExportInput($id))
                : app(RunPrivacyErasure::class)->execute(new RunPrivacyErasureInput($id));

            if ($step->done) {
                break;
            }
        }

        return PrivacyRequest::query()->findOrFail($id);
    };
});

it('exports the account’s details and its preferences by their labels', function (): void {
    $page = app(AccountHolder::class)->export(new PersonalDataSubject('ada@example.com', (string) $this->ada->id), 1);
    [$account, $preferences] = $page->groups;

    expect($page->done)->toBeTrue()
        ->and($account->title)->toBe('Your account')
        ->and($account->values)->toMatchArray([
            'Name' => 'Ada Okafor',
            'Email' => 'Ada@Example.com',
            'Profile address' => 'ada-okafor',
            'Biography' => 'Grows tomatoes.',
            'Two-factor sign-in' => 'On',
        ])
        ->and($account->values)->toHaveKeys(['Created', 'Email verified'])
        ->and($account->values)->not->toHaveKey('Suspended')
        ->and($preferences->title)->toBe('Preferences')
        ->and($preferences->values['Check spelling'])->toBe('Off')
        ->and($preferences->values['Light or dark'])->toBe('Dark')
        ->and($preferences->values['Rows per page'])->toBe('10')
        ->and($preferences->values)->toHaveKey('Hidden menu entries');
});

it('writes a list preference joined with commas', function (): void {
    $values = app(AccountHolder::class)->export(new PersonalDataSubject('ada@example.com', (string) $this->ada->id), 1)->groups[1]->values;
    $columns = PreferencesFor::user($this->ada)['lists.post.columns'];

    expect($columns)->toBeArray()->not->toBeEmpty()
        ->and($values['Posts columns'])->toBe(implode(', ', (array) $columns));
});

it('exports nothing for an address with no account', function (): void {
    $page = app(AccountHolder::class)->export(new PersonalDataSubject('grace@example.com'), 1);

    expect($page->groups)->toBe([])->and($page->done)->toBeTrue();
});

it('never puts a two-factor secret, a recovery code, a password, a token or a session’s payload in the file', function (): void {
    ($this->session)($this->ada, now()->getTimestamp(), '192.0.2.7');
    DB::table('password_reset_tokens')->insert(['email' => 'Ada@Example.com', 'token' => 'RESET-TOKEN-THAT-MUST-NOT-LEAVE', 'created_at' => now()]);
    $stored = DB::table('users')->where('id', $this->ada->id)->first(['password', 'remember_token']);

    $request = ($this->run)('export');
    $file = (string) Storage::disk('local')->get((string) $request->export_path);

    expect($file)->toContain('Ada Okafor')
        ->and($file)->not->toContain('SECRET-THAT-MUST-NOT-LEAVE')
        ->and($file)->not->toContain('RECOVERY-CODES-THAT-MUST-NOT-LEAVE')
        ->and($file)->not->toContain('RESET-TOKEN-THAT-MUST-NOT-LEAVE')
        ->and($file)->not->toContain('SESSION-PAYLOAD')
        ->and($file)->not->toContain((string) $stored->password);
});

it('keeps the account on erasure, saying why, and changes nothing on it but its remember token', function (): void {
    $before = (array) DB::table('users')->where('id', $this->ada->id)->first();

    $request = ($this->run)('erase');
    $after = (array) DB::table('users')->where('id', $this->ada->id)->first();

    expect($request->results[0])->toMatchArray(['key' => 'account', 'removed' => 0, 'retained' => 1, 'reasons' => ['The account is kept. To remove it, delete it under Users.']])
        ->and(array_keys(array_diff_assoc(array_map(fn (mixed $value): string => (string) $value, $after), array_map(fn (mixed $value): string => (string) $value, $before))))
        ->each->toBeIn(['remember_token', 'updated_at']);
});

it('exports the account’s open sessions, when it was last active, from where, and when it asked for a password reset, never the token', function (): void {
    ($this->session)($this->ada, now()->subHour()->getTimestamp(), '192.0.2.1');
    ($this->session)($this->ada, now()->getTimestamp(), '192.0.2.7');
    DB::table('password_reset_tokens')->insert(['email' => 'Ada@Example.com', 'token' => 'RESET-TOKEN', 'created_at' => '2026-11-10 08:00:00']);

    $page = app(SignInHolder::class)->export(new PersonalDataSubject('ada@example.com', (string) $this->ada->id), 1);

    expect($page->groups[0]->title)->toBe('Signing in')
        ->and($page->groups[0]->values)->toMatchArray(['Open sessions' => '2', 'Last IP address' => '192.0.2.7'])
        ->and($page->groups[0]->values)->toHaveKeys(['Last active', 'Password reset asked for'])
        ->and(json_encode($page->groups))->not->toContain('RESET-TOKEN');
});

it('ends the account’s sessions and deletes its reset row on erasure, counting them, and leaves another person’s', function (): void {
    config(['session.driver' => 'database']);
    $grace = User::factory()->create(['email' => 'grace@example.com']);
    ($this->session)($this->ada, now()->getTimestamp(), '192.0.2.7');
    ($this->session)($this->ada, now()->getTimestamp(), '192.0.2.8');
    ($this->session)($grace, now()->getTimestamp(), '192.0.2.9');
    DB::table('password_reset_tokens')->insert([
        ['email' => 'Ada@Example.com', 'token' => 'A', 'created_at' => now()],
        ['email' => 'grace@example.com', 'token' => 'G', 'created_at' => now()],
    ]);

    $request = ($this->run)('erase');

    expect($request->results[1])->toMatchArray(['key' => 'sign-in', 'removed' => 3, 'retained' => 0, 'done' => true])
        ->and(DB::table('sessions')->where('user_id', $this->ada->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $grace->id)->count())->toBe(1)
        ->and(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['grace@example.com']);
});

it('deletes only the reset row for an address with no account', function (): void {
    config(['session.driver' => 'database']);
    DB::table('password_reset_tokens')->insert(['email' => 'Nobody@Example.com', 'token' => 'N', 'created_at' => now()]);
    ($this->session)($this->ada, now()->getTimestamp(), '192.0.2.7');

    $request = ($this->run)('erase', 'nobody@example.com');

    expect($request->results[0])->toMatchArray(['key' => 'account', 'removed' => 0, 'retained' => 0, 'reasons' => []])
        ->and($request->results[1])->toMatchArray(['key' => 'sign-in', 'removed' => 1])
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(DB::table('sessions')->count())->toBe(1);
});
