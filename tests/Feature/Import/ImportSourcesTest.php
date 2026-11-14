<?php

declare(strict_types=1);

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\ConnectImportSource;
use App\Domain\Import\Operations\ForgetImportSource;
use App\Domain\Import\Operations\Input\ConnectImportSourceInput;
use App\Domain\Import\Operations\Input\ForgetImportSourceInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\FakeCompanion;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->fake = importFake();

    $this->connect = fn (?User $actor, string $address = FakeCompanion::SITE, string $password = FakeCompanion::PASSWORD): array => app(ConnectImportSource::class)
        ->execute(new ConnectImportSourceInput($address, FakeCompanion::USER, $password, $actor === null ? null : (string) $actor->id));

    $this->runOf = fn (ImportSource $source, ImportRunState $state): ImportRun => ImportRun::query()->create([
        'source_id' => $source->id, 'kind' => ImportRunKind::Full, 'state' => $state, 'phase' => 'users',
        'phase_token' => $state === ImportRunState::Running ? strtolower((string) Str::ulid()) : null,
        'high_water_at' => now(), 'started_at' => now(), 'ended_at' => $state === ImportRunState::Running ? null : now(),
    ]);
});

it('connects a site, keeping its application password encrypted, and logs it without the password', function (): void {
    $result = ($this->connect)($this->admin, 'old.example/');
    $source = ImportSource::query()->findOrFail($result['sourceId']);

    expect($result['created'])->toBeTrue()
        ->and($source->address)->toBe('https://old.example')
        ->and($source->api_root)->toBe('https://old.example/wp-json/')
        ->and($source->user_name)->toBe(FakeCompanion::USER)
        ->and($source->credential)->toBe(FakeCompanion::PASSWORD)
        ->and($source->plugin_version)->toBe('0.2.0')
        ->and($source->api_version)->toBe(1)
        ->and($source->created_by)->toBe($this->admin->id)
        ->and($source->forgotten_at)->toBeNull();

    $event = DB::table('security_events')->where('type', 'import.source_connected')->sole();

    expect((int) $event->user_id)->toBe($this->admin->id)
        ->and(json_decode((string) $event->context, true))->toEqualCanonicalizing(['source' => $source->id, 'address' => 'https://old.example', 'plugin_version' => '0.2.0'])
        ->and((string) $event->context)->not->toContain('abcd');
});

it('updates a site it already knows, taking its password back if it was forgotten', function (): void {
    $first = ($this->connect)($this->admin);
    DB::table('import_sources')->update(['credential' => null, 'forgotten_at' => now()]);

    $second = ($this->connect)($this->admin);

    expect($second)->toBe(['sourceId' => $first['sourceId'], 'created' => false])
        ->and(ImportSource::query()->count())->toBe(1)
        ->and(ImportSource::query()->sole()->forgotten_at)->toBeNull()
        ->and(ImportSource::query()->sole()->credential)->toBe(FakeCompanion::PASSWORD);
});

it('shows each refusal under the field it belongs to, and keeps nothing', function (string $case, string $field, string $words): void {
    match ($case) {
        'not an administrator' => $this->fake->answer('settings', 403, ['code' => 'teme_forbidden', 'message' => 'An administrator account is required.', 'data' => ['status' => 403]]),
        'private' => importResolve([FakeCompanion::HOST => ['10.0.0.5']]),
        default => null,
    };

    $connect = fn () => ($this->connect)($this->admin, $case === 'blank' ? '' : FakeCompanion::SITE, $case === 'wrong password' ? 'wrong wrong wrong' : FakeCompanion::PASSWORD);

    expect($connect)->toThrow(function (ValidationException $refusal) use ($field, $words): void {
        expect($refusal->errors()[$field][0] ?? null)->toBe($words);
    });
    expect(ImportSource::query()->count())->toBe(0);
})->with([
    'no address' => ['blank', 'address', 'Enter the WordPress site’s address.'],
    'a wrong application password' => ['wrong password', 'application_password', 'WordPress didn’t accept this user name and application password. If both are right, the site’s server may be removing the sign-in header before WordPress sees it.'],
    'an account that isn\'t an administrator' => ['not an administrator', 'user_name', 'This WordPress account isn’t an administrator. Use an administrator’s user name, and an application password made for that account.'],
    'a site on a private network' => ['private', 'address', 'old.example is on a private network, so Teme won’t connect to it.'],
]);

it('writes nothing for anyone who may not import', function (string $case): void {
    $source = importSource();
    $before = DB::table('import_sources')->orderBy('id')->get()->toJson();
    $events = DB::table('security_events')->count();

    $act = match ($case) {
        'an editor connecting' => fn () => ($this->connect)($this->editor),
        'an editor forgetting' => fn () => app(ForgetImportSource::class)->execute(new ForgetImportSourceInput($source->id, (string) $this->editor->id)),
        default => fn () => ($this->connect)(null),
    };

    expect($act)->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(DB::table('import_sources')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('security_events')->count())->toBe($events);
})->with([
    'an editor connecting' => ['an editor connecting'],
    'an editor forgetting' => ['an editor forgetting'],
    'nobody, away from the operator\'s shell' => ['nobody'],
]);

it('forgets the application password, keeping the site, its runs and its map, and logs it (I-1708)', function (): void {
    $source = importSource($this->admin);
    $run = ($this->runOf)($source, ImportRunState::Finished);
    ImportMapEntry::query()->create([
        'source_id' => $source->id, 'object_type' => 'user', 'object_id' => '1', 'teme_type' => 'user', 'teme_id' => (string) $this->admin->id,
        'status' => ImportStatus::Imported, 'outcome' => ImportOutcome::Updated, 'run_id' => $run->id, 'imported_at' => now(),
    ]);

    app(ForgetImportSource::class)->execute(new ForgetImportSourceInput($source->id, (string) $this->admin->id));
    $source->refresh();

    expect($source->credential)->toBeNull()
        ->and($source->forgotten_at)->not->toBeNull()
        ->and($source->user_name)->toBe(FakeCompanion::USER)
        ->and(DB::table('import_runs')->count())->toBe(1)
        ->and(DB::table('import_map')->count())->toBe(1)
        ->and(DB::table('security_events')->where('type', 'import.source_forgotten')->where('user_id', $this->admin->id)->count())->toBe(1);
});

it('refuses to forget a site while it is being imported', function (): void {
    $source = importSource($this->admin);
    ($this->runOf)($source, ImportRunState::Running);

    expect(fn () => app(ForgetImportSource::class)->execute(new ForgetImportSourceInput($source->id, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'Stop the import that’s running first: it needs the application password.');

    expect($source->fresh()->credential)->toBe(FakeCompanion::PASSWORD);
});
