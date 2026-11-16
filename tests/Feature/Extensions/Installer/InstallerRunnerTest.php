<?php

declare(strict_types=1);

use App\Domain\Extensions\Installer\ComposerResult;
use App\Domain\Extensions\Installer\ComposerRunner;
use App\Domain\Extensions\Installer\InstallerFailed;
use App\Domain\Extensions\Models\ExtensionSourceRecord;
use App\Domain\Extensions\Operations\AgreeToExtensionRequest;
use App\Domain\Extensions\Operations\BeginExtensionRequest;
use App\Domain\Extensions\Operations\CancelExtensionRequest;
use App\Domain\Extensions\Operations\EndExtensionRequest;
use App\Domain\Extensions\Operations\Input\AgreeToExtensionRequestInput;
use App\Domain\Extensions\Operations\Input\BeginExtensionRequestInput;
use App\Domain\Extensions\Operations\Input\CancelExtensionRequestInput;
use App\Domain\Extensions\Operations\Input\EndExtensionRequestInput;
use App\Domain\Extensions\Operations\Input\RecordExtensionStepInput;
use App\Domain\Extensions\Operations\RecordExtensionStep;
use App\Domain\Extensions\Queries\ExtensionsScreen;
use App\Domain\Extensions\RequestStatus;
use App\Domain\Extensions\RequestStep;
use App\Domain\Extensions\Sources\ExtensionRequest;
use App\Domain\Extensions\Sources\ExtensionSource;
use App\Domain\Extensions\Sources\SourceKind;
use App\Domain\Extensions\Sources\SourceKinds;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Teme\Contracts\Operations\OperationInput;
use Tests\Fixtures\Extensions\ExtensionsFolder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.version' => '0.5.0']);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->folder = ExtensionsFolder::make();
    $this->runner = $this->folder->runner();

    /** Records a request as the admin would, and returns its id. */
    $this->ask = function (string $action, ?string $package = null, ?string $constraint = null, array $values = []): string {
        $id = strtolower((string) Str::ulid());
        DB::table('extension_requests')->insert([
            'id' => $id, 'action' => $action, 'kind' => 'plugin', 'package' => $package, 'constraint' => $constraint,
            'source_kind' => ExtensionsFolder::SOURCE, 'status' => 'requested', 'requested_at' => now(), ...$values,
        ]);

        return $id;
    };

    /** Runs passes until the request stops moving, at most 20. */
    $this->settle = function (string $id): object {
        for ($pass = 0; $pass < 20; $pass++) {
            $this->runner->tick();
            $row = DB::table('extension_requests')->where('id', $id)->first();

            if (in_array($row->status, ['done', 'failed', 'cancelled', 'waiting'], true)) {
                return $row;
            }
        }

        return DB::table('extension_requests')->where('id', $id)->first();
    };
});

afterEach(function (): void {
    $this->folder->delete();
});

it('carries a request from requested to done, step by step, and switches to the new release', function (): void {
    $upload = strtolower((string) Str::ulid());
    DB::table('extension_uploads')->insert(['id' => $upload, 'file_name' => 'hello.zip', 'size' => 3, 'sha256' => str_repeat('a', 64), 'package' => 'fixtures/hello-extension', 'version' => '1.0.0', 'bytes' => 'zip', 'created_at' => now()]);
    $id = ($this->ask)('install', 'fixtures/hello-extension', '1.0.0', ['upload_id' => $upload]);
    $restarted = Cache::get('illuminate:queue:restart');

    $row = ($this->settle)($id);
    $steps = json_decode((string) $row->steps, true);

    expect($row->status)->toBe('done', (string) $row->error)
        ->and(array_column($steps, 'step'))->toBe(array_map(fn (RequestStep $step): string => $step->value, RequestStep::cases()))
        ->and(array_unique(array_column($steps, 'state')))->toBe(['done'])
        ->and($this->folder->store->current())->toBe($row->to_release)
        ->and($this->folder->store->path($row->to_release).'/compat/'.ExtensionsFolder::pins()['hash'])->toBeFile()
        ->and($this->folder->store->path($row->to_release).'/cache/'.ExtensionsFolder::pins()['hash'].'/teme-plugins.php')->toBeFile()
        ->and(Cache::get('illuminate:queue:restart'))->not->toBe($restarted)
        ->and(DB::table('extension_uploads')->count())->toBe(0)
        ->and(json_decode((string) $row->report, true)['packages'][0]['name'])->toBe('fixtures/hello-extension');
})->group('composer');

it('leaves current as it was and deletes its release when a request fails', function (): void {
    $before = $this->folder->install('fixtures/hello-extension', '1.0.0');
    $id = ($this->ask)('install', 'fixtures/wants-new-laravel', '1.0.0');

    $row = ($this->settle)($id);

    expect($row->status)->toBe('failed')
        ->and($row->error)->toContain('laravel/framework')
        ->and($this->folder->store->current())->toBe($before)
        ->and($this->folder->store->all())->toBe([$before]);
})->group('composer');

it('waits for agreement, then goes on once what was found is agreed to', function (): void {
    $id = ($this->ask)('install', 'fixtures/files-dependency', '1.0.0');

    $row = ($this->settle)($id);
    $needs = json_decode((string) $row->needs, true);

    expect($row->status)->toBe('waiting')
        ->and($row->step)->toBe('validating')
        ->and($needs)->toBe(['files' => ['fixtures/with-files' => ['helpers.php']]])
        ->and($this->folder->store->current())->toBeNull();

    $this->runner->tick();
    expect(DB::table('extension_requests')->where('id', $id)->value('status'))->toBe('waiting');

    DB::table('extension_requests')->where('id', $id)->update(['agreed' => json_encode($needs)]);
    $row = ($this->settle)($id);

    expect($row->status)->toBe('done', (string) $row->error)
        ->and($this->folder->store->current())->toBe($row->to_release);
})->group('composer');

it('goes on once an administrator agrees, through the admin’s Operation, to every key it found', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    $needs = [
        'files' => ['fixtures/with-files' => ['helpers.php']],
        'composer_plugins' => ['fixtures/marker-plugin'],
        'source' => ['package' => 'fixtures/hello-extension', 'from' => 'packagist', 'to' => 'upload'],
        'replace' => ['package' => 'fixtures/hello-extension', 'from' => '1.0.0', 'to' => '1.1.0'],
    ];
    $id = ($this->ask)('update', 'fixtures/hello-extension', '1.1.0', ['status' => 'waiting', 'step' => 'validating', 'started_at' => now(), 'needs' => json_encode($needs)]);

    $this->runner->tick();
    expect(DB::table('extension_requests')->where('id', $id)->value('status'))->toBe('waiting');

    $shown = json_decode((string) DB::table('extension_requests')->where('id', $id)->value('needs'), true);
    app(AgreeToExtensionRequest::class)->execute(new AgreeToExtensionRequestInput($id, $shown, (string) $admin->id));
    $this->runner->tick();
    $row = DB::table('extension_requests')->where('id', $id)->first();

    expect($row->status)->toBe('running')
        ->and($row->step)->toBe('validating')
        ->and((int) $row->agreed_by)->toBe($admin->id);
});

it('cancels a request that waited over a day, and deletes its release', function (): void {
    $id = ($this->ask)('install', 'fixtures/files-dependency', '1.0.0');
    $row = ($this->settle)($id);

    $this->travel(25)->hours();
    $this->runner->tick();

    $row = DB::table('extension_requests')->where('id', $id)->first();

    expect($row->status)->toBe('cancelled')
        ->and($row->error)->toBe('Nobody agreed within a day, so the request was cancelled.')
        ->and($this->folder->store->all())->toBe([]);
})->group('composer');

it('leaves a request waiting while an update of Teme is open', function (): void {
    DB::table('teme_updates')->insert(['id' => strtolower((string) Str::ulid()), 'kind' => 'update', 'from_version' => '0.5.0', 'to_version' => '0.6.0', 'status' => 'requested', 'requested_at' => now()]);
    $id = ($this->ask)('install', 'fixtures/hello-extension', '1.0.0');

    $this->runner->tick();
    $this->runner->tick();

    expect(DB::table('extension_requests')->where('id', $id)->value('status'))->toBe('requested');
});

it('stops a begun request, an administrator’s rebuild among them, when an update of Teme opened meanwhile', function (string $action, ?string $package): void {
    $admin = User::factory()->create();
    $id = ($this->ask)($action, $package, $package === null ? null : '1.0.0', ['status' => 'running', 'step' => 'checking', 'started_at' => now(), 'requested_by' => $admin->id]);
    DB::table('teme_updates')->insert(['id' => strtolower((string) Str::ulid()), 'kind' => 'update', 'from_version' => '0.5.0', 'to_version' => '0.6.0', 'status' => 'requested', 'requested_at' => now()]);

    $this->runner->tick();
    $row = DB::table('extension_requests')->where('id', $id)->first();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('An update of Teme is under way; ask again once it has ended.');
})->with([
    'an install' => ['install', 'fixtures/hello-extension'],
    'an administrator’s rebuild' => ['rebuild', null],
]);

it('takes Teme’s own rebuild past the check while the update that asked for it is open', function (): void {
    $id = ($this->ask)('rebuild', null, null, ['status' => 'running', 'step' => 'checking', 'started_at' => now()]);
    DB::table('teme_updates')->insert(['id' => strtolower((string) Str::ulid()), 'kind' => 'update', 'from_version' => '0.5.0', 'to_version' => '0.6.0', 'status' => 'running', 'requested_at' => now()]);

    $this->runner->tick();
    $row = DB::table('extension_requests')->where('id', $id)->first();

    expect($row->status)->toBe('running', (string) $row->error)
        ->and($row->step)->toBe('preparing');
});

it('fails a request when current moved while it was being built', function (): void {
    $id = ($this->ask)('install', 'fixtures/hello-extension', '1.0.0');

    for ($pass = 0; $pass < 20 && DB::table('extension_requests')->where('id', $id)->value('step') !== 'switching'; $pass++) {
        $this->runner->tick();
    }

    $elsewhere = $this->folder->stage();
    $this->folder->store->swap($elsewhere);
    $row = ($this->settle)($id);

    expect($row->status)->toBe('failed')
        ->and($row->error)->toContain('changed while this request was being built')
        ->and($this->folder->store->current())->toBe($elsewhere);
})->group('composer');

it('rolls back to the kept release checked against this core, and refuses with none', function (): void {
    $first = $this->folder->install('fixtures/hello-extension', '1.0.0');
    $this->folder->install('fixtures/hello-extension', '1.1.0');

    $row = ($this->settle)(($this->ask)('rollback'));

    expect($row->status)->toBe('done', (string) $row->error)
        ->and($this->folder->store->current())->toBe($first);

    $row = ($this->settle)(($this->ask)('rollback'));

    expect($row->status)->toBe('failed')
        ->and($row->error)->toContain('nothing to roll back to')
        ->and($this->folder->store->current())->toBe($first);
})->group('composer');

describe('the installer’s Operations', function (): void {
    beforeEach(function (): void {
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create()->assignRole('administrator');
    });

    it('refuses a person, even an administrator, and writes nothing', function (Closure $call): void {
        $id = ($this->ask)('install', 'fixtures/hello-extension', '1.0.0');
        $before = DB::table('extension_requests')->get()->toArray();

        expect(fn () => $call($id, (string) $this->admin->id))->toThrow(AuthorizationException::class, 'Only the installer');

        app()->instance(Elevation::class, new class implements Elevation
        {
            public function isOperator(): bool
            {
                return false;
            }

            public function ensure(OperationInput $input): void {}
        });

        expect(fn () => $call($id, null))->toThrow(AuthorizationException::class, 'Only the installer')
            ->and(DB::table('extension_requests')->get()->toArray())->toEqual($before);
    })->with([
        'begin' => [fn (string $id, ?string $actor) => app(BeginExtensionRequest::class)->execute(new BeginExtensionRequestInput($id, actorId: $actor))],
        'record' => [fn (string $id, ?string $actor) => app(RecordExtensionStep::class)->execute(new RecordExtensionStepInput($id, RequestStep::Checking, RequestStep::RUNNING, actorId: $actor))],
        'end' => [fn (string $id, ?string $actor) => app(EndExtensionRequest::class)->execute(new EndExtensionRequestInput($id, RequestStatus::Failed, actorId: $actor))],
    ]);

    it('purges the page cache after commit for an enabled plugin’s update, not for a new install, and not on rollback', function (): void {
        $folder = cacheOn();

        try {
            DB::table('plugins')->insert(['id' => strtolower((string) Str::ulid()), 'name' => 'fixtures/hello-extension', 'code' => 'hello-extension', 'state' => 'enabled', 'version' => '1.0.0', 'consent' => '{}', 'consented_at' => now()]);
            $end = fn (array $plugins) => app(EndExtensionRequest::class)->execute(new EndExtensionRequestInput(
                DB::table('extension_requests')->whereIn('status', ['requested', 'running', 'waiting'])->value('id'), RequestStatus::Done, plugins: $plugins,
            ));

            ($this->ask)('install', 'fixtures/other', '1.0.0');
            cachePage('/');
            $end(['other']);
            expect(cacheKept('/'))->toBe(['/']);

            ($this->ask)('update', 'fixtures/hello-extension', '1.1.0');
            cacheRolledBack(fn () => $end(['hello-extension']));
            expect(cacheKept('/'))->toBe(['/']);

            $end(['hello-extension']);
            expect(cacheKept('/'))->toBe([]);
        } finally {
            cacheOff($folder);
        }
    });
});

it('fails a request with the source’s own reason when the source refuses to prepare it', function (): void {
    $row = ($this->settle)(($this->ask)('install', 'acme/gone', '1.0.0', ['source_kind' => 'upload']));

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('The uploaded file for this request is gone. Upload it again, then ask again.');
});

it('deletes an unpacked upload no kept release names when its request fails, and when one is cancelled', function (): void {
    $root = $this->folder->root;
    $kept = $this->folder->stage();
    file_put_contents($this->folder->store->path($kept).'/composer.lock', json_encode(['packages' => [
        ['name' => 'acme/kept', 'version' => '1.0.0', 'type' => 'teme-plugin', 'dist' => ['type' => 'path', 'url' => "{$root}/packages/acme/kept/1.0.0", 'reference' => 'abc']],
    ]]));
    $unpack = function (string $folder) use ($root): string {
        @mkdir("{$root}/packages/{$folder}", 0755, true);
        file_put_contents("{$root}/packages/{$folder}/composer.json", '{}');

        return "{$root}/packages/{$folder}";
    };
    $keptFolder = $unpack('acme/kept/1.0.0');
    $failedFolder = $unpack('acme/gone/1.0.0');

    $row = ($this->settle)(($this->ask)('install', 'acme/gone', '1.0.0', ['source_kind' => 'upload']));

    expect($row->status)->toBe('failed')
        ->and($failedFolder)->not->toBeDirectory()
        ->and($keptFolder)->toBeDirectory();

    $cancelledFolder = $unpack('acme/gone/2.0.0');
    $staged = $this->folder->stage();
    file_put_contents($this->folder->store->path($staged).'/composer.lock', json_encode(['packages' => [
        ['name' => 'acme/gone', 'version' => '2.0.0', 'type' => 'teme-plugin', 'dist' => ['type' => 'path', 'url' => $cancelledFolder, 'reference' => 'def']],
    ]]));
    $waiting = ($this->ask)('install', 'acme/gone', '2.0.0', ['source_kind' => 'upload', 'status' => 'waiting', 'step' => 'validating', 'to_release' => $staged, 'from_release' => '', 'needs' => json_encode(['replace' => ['package' => 'acme/gone', 'from' => '1.0.0', 'to' => '2.0.0']])]);
    app(CancelExtensionRequest::class)->execute(new CancelExtensionRequestInput($waiting));

    $this->runner->tick();

    expect($cancelledFolder)->not->toBeDirectory()
        ->and($this->folder->store->all())->toBe([$kept])
        ->and($keptFolder)->toBeDirectory();
});

it('refuses before fetching when the staged composer.json holds a Git or Composer address on a private network', function (): void {
    app()->instance('tests.private-source', new ExtJoinPrivateRepositorySource);
    app()->tag('tests.private-source', SourceKinds::TAG);
    $composer = new class((string) config('teme.extensions.composer'), $this->folder->root) extends ComposerRunner
    {
        public int $changes = 0;

        public function change(ExtensionRequest $request, string $release, #[SensitiveParameter] array $auth = [], array $blocking = []): ComposerResult
        {
            $this->changes++;

            return new ComposerResult(1, 'Composer ran.');
        }
    };
    $this->runner = $this->folder->runner(composer: $composer);

    $row = ($this->settle)(($this->ask)('install', 'acme/private', '1.0.0', ['source_kind' => 'private_repo']));

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('10.0.0.5 is on a private network, so Teme won’t connect to it.')
        ->and($composer->changes)->toBe(0);
});

it('records no saved key in a failed request’s reason, steps or report, nor on the screens that show it', function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance('tests.leaky-source', new ExtJoinLeakySource);
    app()->tag('tests.leaky-source', SourceKinds::TAG);
    $source = strtolower((string) Str::ulid());
    DB::table('extension_sources')->insert([
        'id' => $source, 'kind' => 'leaky', 'label' => 'Vendor', 'url' => 'https://vendor.example.com', 'username' => null,
        'credential' => encrypt('secret-key-123', false), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $id = ($this->ask)('install', 'acme/paid', '1.0.0', ['source_kind' => 'leaky', 'source_id' => $source]);

    $row = ($this->settle)($id);

    expect($row->status)->toBe('failed')
        ->and($row->error)->toContain('The vendor refused the key')
        ->and(json_encode($row))->not->toContain('secret-key-123');

    config(['teme.extensions.path' => $this->folder->root]);
    $admin = User::factory()->create()->assignRole('administrator');
    $shown = $this->actingAs($admin)->getJson("/admin/extensions/requests/{$id}")->assertOk();

    expect((string) $shown->getContent())->toContain('The vendor refused the key')->not->toContain('secret-key-123')
        ->and(json_encode(app(ExtensionsScreen::class)->data()))->toContain($id)->not->toContain('secret-key-123');
});

/**
 * A vendor's source whose prepare() repeats its key in the reason it fails,
 * as a careless message or Composer's own output might.
 */
final readonly class ExtJoinLeakySource implements SourceKind
{
    public function key(): string
    {
        return 'leaky';
    }

    public function repositories(?ExtensionSource $source, string $extensionsPath): array
    {
        return [];
    }

    public function auth(?ExtensionSource $source): array
    {
        return $source?->credential === null ? [] : ['bearer' => ['vendor.example.com' => $source->credential]];
    }

    public function prepare(ExtensionRequest $request, string $releasePath, string $extensionsPath): void
    {
        $key = ExtensionSourceRecord::query()->findOrFail($request->sourceId)->toSource()->credential;

        throw new InstallerFailed("The vendor refused the key {$key} for {$request->package}.");
    }
}

/**
 * A source whose release names a Git address on a private network, as an
 * earlier source left in composer.json might.
 */
final readonly class ExtJoinPrivateRepositorySource implements SourceKind
{
    public function key(): string
    {
        return 'private_repo';
    }

    public function repositories(?ExtensionSource $source, string $extensionsPath): array
    {
        return [['type' => 'vcs', 'url' => 'https://10.0.0.5/acme/private.git']];
    }

    public function auth(?ExtensionSource $source): array
    {
        return [];
    }

    public function prepare(ExtensionRequest $request, string $releasePath, string $extensionsPath): void {}
}
