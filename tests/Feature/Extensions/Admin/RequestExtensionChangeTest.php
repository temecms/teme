<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Operations\Input\RequestExtensionChangeInput;
use App\Domain\Extensions\Operations\RequestExtensionChange;
use App\Domain\Extensions\Sources\ExtensionRequest;
use App\Domain\Extensions\Sources\ExtensionSource;
use App\Domain\Extensions\Sources\SourceKind;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;

require_once __DIR__.'/support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = extAdmin();
    $this->ask = fn (ExtensionAction $action, ?string $package = 'acme/hello', ExtensionKind $kind = ExtensionKind::Plugin, array $more = []) => fn () => app(RequestExtensionChange::class)->execute(
        new RequestExtensionChangeInput(...['action' => $action, 'kind' => $kind, 'package' => $package, 'actorId' => (string) $this->admin->id, ...$more]),
    );
});

afterEach(function (): void {
    $root = config('teme.extensions.path');

    if (is_string($root) && str_contains($root, 'teme-ext-admin-')) {
        File::deleteDirectory($root);
    }
});

it('records an install from Packagist as written, with who asked and when', function (): void {
    extFolder();

    $id = (($this->ask)(ExtensionAction::Install, more: ['constraint' => '^1.2']))();
    $row = DB::table('extension_requests')->where('id', $id)->first();

    expect($row->action)->toBe('install')
        ->and($row->kind)->toBe('plugin')
        ->and($row->package)->toBe('acme/hello')
        ->and($row->constraint)->toBe('^1.2')
        ->and($row->source_kind)->toBe('packagist')
        ->and($row->source_id)->toBeNull()
        ->and($row->status)->toBe('requested')
        ->and($row->step)->toBeNull()
        ->and((int) $row->requested_by)->toBe($this->admin->id)
        ->and($row->requested_at)->not->toBeNull()
        ->and(json_decode((string) $row->steps, true))->toBe([]);
});

it('asks for any version when none is given', function (): void {
    $id = (($this->ask)(ExtensionAction::Install))();

    expect(DB::table('extension_requests')->where('id', $id)->value('constraint'))->toBe('*');
});

it('writes nothing when the person may not install plugins and themes', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);

    expect(fn () => app(RequestExtensionChange::class)->execute(new RequestExtensionChangeInput(ExtensionAction::Install, ExtensionKind::Plugin, 'acme/hello', actorId: (string) $person->id)))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('extension_requests')->count())->toBe(0);
})->with(['editor', 'author', 'contributor']);

it('asks for the password again, and writes nothing without it', function (): void {
    app()->instance(Elevation::class, new DeniedElevation);

    expect(($this->ask)(ExtensionAction::Install))->toThrow(ElevationRequiredException::class);
    expect(DB::table('extension_requests')->count())->toBe(0);
});

it('lets the operator ask with no person, and writes nothing for a null actor when the operator isn’t present', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $id = app(RequestExtensionChange::class)->execute(new RequestExtensionChangeInput(ExtensionAction::Install, ExtensionKind::Plugin, 'acme/hello'));

    expect(DB::table('extension_requests')->where('id', $id)->value('requested_by'))->toBeNull();

    DB::table('extension_requests')->delete();
    app()->forgetInstance(Elevation::class);

    expect(fn () => app(RequestExtensionChange::class)->execute(new RequestExtensionChangeInput(ExtensionAction::Install, ExtensionKind::Plugin, 'acme/hello')))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('extension_requests')->count())->toBe(0);
});

it('refuses a second request while one is open, and allows one once it has ended', function (string $status): void {
    $open = extRequestRow($status);

    expect(($this->ask)(ExtensionAction::Install))->toThrow(ValidationException::class, 'Another plugin or theme request is under way. Wait for it to end, or cancel it.');
    expect(DB::table('extension_requests')->count())->toBe(1);

    DB::table('extension_requests')->where('id', $open)->update(['status' => 'failed', 'finished_at' => now()]);
    (($this->ask)(ExtensionAction::Install))();

    expect(DB::table('extension_requests')->where('status', 'requested')->count())->toBe(1);
})->with(['requested', 'running', 'waiting']);

it('holds the request lock until its transaction ends, so two at once can’t both pass the rules', function (): void {
    DB::transaction(function (): void {
        (($this->ask)(ExtensionAction::Install))();

        config(['database.connections.extensions_probe' => config('database.connections.'.config('database.default'))]);
        $probe = DB::connection('extensions_probe');

        expect((bool) $probe->scalar('SELECT pg_try_advisory_xact_lock(hashtext(?))', [RequestExtensionChange::LOCK]))->toBeFalse();
        $probe->disconnect();

        // The second caller, once it has the lock, reads the first one's row and is refused.
        expect(($this->ask)(ExtensionAction::Install, 'acme/other'))->toThrow(ValidationException::class, 'Another plugin or theme request is under way.');
    });

    expect(DB::table('extension_requests')->count())->toBe(1);
});

it('the database refuses a second open row written by hand', function (): void {
    extRequestRow('requested');

    expect(fn () => extRequestRow('waiting'))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses while Teme is being updated', function (): void {
    extOpenTemeUpdate();

    expect(($this->ask)(ExtensionAction::Install))->toThrow(ValidationException::class, 'Teme is being updated. Ask again once the update has ended.');
    expect(DB::table('extension_requests')->count())->toBe(0);
});

it('refuses a name that isn’t a Composer package’s', function (string $name): void {
    expect(($this->ask)(ExtensionAction::Install, $name))->toThrow(ValidationException::class, 'isn’t a Composer package name');
    expect(DB::table('extension_requests')->count())->toBe(0);
})->with(['no slash' => 'hello', 'capitals' => 'Acme/Hello', 'a space' => 'acme/hel lo', 'a path' => 'acme/../hello', 'too long' => fn () => 'acme/'.str_repeat('a', 210)]);

it('refuses to install a package the image has', function (string $name): void {
    expect(($this->ask)(ExtensionAction::Install, $name))->toThrow(ValidationException::class, "{$name} is part of Teme’s build, so it can’t be installed again.");
    expect(DB::table('extension_requests')->count())->toBe(0);
})->with(['laravel/framework', 'teme/contracts', 'inertiajs/inertia-laravel']);

it('refuses to install one already in the extensions folder: that is an update', function (): void {
    extFolder(['acme/hello' => ['type' => 'teme-plugin']]);

    expect(($this->ask)(ExtensionAction::Install))->toThrow(ValidationException::class, 'acme/hello is already installed. Update it instead.');
    expect(DB::table('extension_requests')->count())->toBe(0);
});

it('refuses to update or remove a package of the image, or one not installed from the admin', function (ExtensionAction $action): void {
    extFolder(['acme/library-plugin' => ['type' => 'teme-plugin', 'dependency' => true]]);

    expect(($this->ask)($action, 'laravel/framework'))->toThrow(ValidationException::class, 'It is part of Teme’s build; change it where the site is built.')
        ->and(($this->ask)($action, 'acme/nowhere'))->toThrow(ValidationException::class, 'acme/nowhere isn’t installed from the admin.')
        ->and(($this->ask)($action, 'acme/library-plugin'))->toThrow(ValidationException::class, 'acme/library-plugin isn’t installed from the admin.');
    expect(DB::table('extension_requests')->count())->toBe(0);
})->with([ExtensionAction::Update, ExtensionAction::Remove]);

it('refuses to change a theme asked for as a plugin, and a plugin as a theme', function (): void {
    extFolder(['acme/hello' => ['type' => 'teme-plugin'], 'acme/look' => ['type' => 'teme-theme']]);

    expect(($this->ask)(ExtensionAction::Update, 'acme/look'))->toThrow(ValidationException::class, 'acme/look is a theme, not a plugin.')
        ->and(($this->ask)(ExtensionAction::Remove, 'acme/hello', ExtensionKind::Theme))->toThrow(ValidationException::class, 'acme/hello is a plugin, not a theme.');
});

it('records an update and a removal of a package installed from the admin', function (): void {
    extFolder(['acme/hello' => ['type' => 'teme-plugin']]);

    $update = (($this->ask)(ExtensionAction::Update, more: ['constraint' => '^1.1']))();
    DB::table('extension_requests')->where('id', $update)->update(['status' => 'done', 'finished_at' => now()]);
    $remove = (($this->ask)(ExtensionAction::Remove))();
    $row = DB::table('extension_requests')->where('id', $remove)->first();

    expect(DB::table('extension_requests')->where('id', $update)->value('constraint'))->toBe('^1.1')
        ->and($row->action)->toBe('remove')
        ->and($row->constraint)->toBeNull()
        ->and($row->source_kind)->toBeNull();
});

it('refuses to remove an enabled plugin, and records the removal once it is disabled', function (): void {
    extFolder(['acme/hello' => ['type' => 'teme-plugin']]);
    $record = PluginRecord::query()->forceCreate(['name' => 'acme/hello', 'code' => 'hello', 'version' => '1.0.0', 'state' => PluginRecord::ENABLED, 'consent' => [], 'consented_at' => now()]);

    expect(($this->ask)(ExtensionAction::Remove))->toThrow(ValidationException::class, 'Disable acme/hello first; its data stays until you remove it.');
    expect(DB::table('extension_requests')->count())->toBe(0);

    $record->forceFill(['state' => PluginRecord::DISABLED])->save();
    (($this->ask)(ExtensionAction::Remove))();

    expect(DB::table('extension_requests')->count())->toBe(1)
        ->and(PluginRecord::query()->where('name', 'acme/hello')->exists())->toBeTrue();
});

it('refuses to remove the theme the site is drawn with', function (): void {
    extFolder(['acme/look' => ['type' => 'teme-theme', 'code' => 'look']]);
    $settings = Mockery::mock(SettingsReader::class);
    $settings->shouldReceive('string')->with('design', 'theme')->andReturn('look');
    app()->instance(SettingsReader::class, $settings);

    expect(($this->ask)(ExtensionAction::Remove, 'acme/look', ExtensionKind::Theme))->toThrow(ValidationException::class, 'The site is drawn with this theme. Activate another theme first.');
    expect(DB::table('extension_requests')->count())->toBe(0);
});

it('refuses to remove the theme the design’s draft switches to', function (): void {
    extFolder(['acme/look' => ['type' => 'teme-theme', 'code' => 'look']]);
    DB::table('settings')->updateOrInsert(['group_key' => 'design'], ['overrides' => '{}', 'draft_values' => json_encode(['theme' => 'look'])]);

    expect(($this->ask)(ExtensionAction::Remove, 'acme/look', ExtensionKind::Theme))->toThrow(ValidationException::class, 'The design’s draft switches to this theme. Publish or discard the draft first.');
    expect(DB::table('extension_requests')->count())->toBe(0);
});

it('refuses a rollback with no earlier release kept, and records one when there is', function (): void {
    expect(($this->ask)(ExtensionAction::Rollback, null))->toThrow(ValidationException::class, 'This server has no extensions folder, so there is nothing to roll back.');

    extFolder(['acme/hello' => ['type' => 'teme-plugin']]);
    expect(($this->ask)(ExtensionAction::Rollback, null))->toThrow(ValidationException::class, 'No earlier plugins and themes are kept to roll back to.');
    expect(DB::table('extension_requests')->count())->toBe(0);

    extFolder(['acme/hello' => ['type' => 'teme-plugin']], earlier: 1);
    $id = (($this->ask)(ExtensionAction::Rollback, null))();
    $row = DB::table('extension_requests')->where('id', $id)->first();

    expect($row->action)->toBe('rollback')
        ->and($row->package)->toBeNull()
        ->and($row->constraint)->toBeNull();
});

it('refuses a rebuild: Teme asks for that itself', function (): void {
    expect(($this->ask)(ExtensionAction::Rebuild, null))->toThrow(ValidationException::class, 'it can’t be asked for');
    expect(DB::table('extension_requests')->count())->toBe(0);
});

it('refuses a version Composer can’t read, an unknown source kind, and Packagist when it is off', function (): void {
    expect(($this->ask)(ExtensionAction::Install, more: ['constraint' => 'not a version']))->toThrow(ValidationException::class, '“not a version” isn’t a version Composer understands')
        ->and(($this->ask)(ExtensionAction::Install, more: ['sourceKind' => 'ftp']))->toThrow(ValidationException::class, '“ftp” isn’t a kind of package source Teme knows.');

    config(['teme.extensions.packagist' => false]);

    expect(($this->ask)(ExtensionAction::Install))->toThrow(ValidationException::class, 'Packagist is turned off on this server. Use another source.');
    expect(DB::table('extension_requests')->count())->toBe(0);
});

it('records an upload’s request with the archive’s own version, and refuses an upload that holds another package', function (): void {
    app()->tag([ExtFakeUploadKind::class], 'teme.extensions.sources');
    $upload = strtolower((string) Str::ulid());
    DB::statement("INSERT INTO extension_uploads (id, file_name, size, sha256, package, version, bytes, created_at) VALUES (?, 'hello.zip', 4, ?, 'acme/hello', '1.4.0', decode('UEsDBA==', 'base64'), now())", [$upload, str_repeat('a', 64)]);

    expect(($this->ask)(ExtensionAction::Install, 'acme/other', more: ['sourceKind' => 'upload', 'uploadId' => $upload]))->toThrow(ValidationException::class, 'The uploaded archive holds acme/hello, not acme/other.');

    $id = (($this->ask)(ExtensionAction::Install, more: ['sourceKind' => 'upload', 'uploadId' => $upload]))();
    $row = DB::table('extension_requests')->where('id', $id)->first();

    expect($row->source_kind)->toBe('upload')
        ->and($row->upload_id)->toBe($upload)
        ->and($row->constraint)->toBe('1.4.0');
});

/**
 * Stands in for part C's upload source kind.
 */
final readonly class ExtFakeUploadKind implements SourceKind
{
    public function key(): string
    {
        return 'upload';
    }

    public function repositories(?ExtensionSource $source, string $extensionsPath): array
    {
        return [];
    }

    public function auth(?ExtensionSource $source): array
    {
        return [];
    }

    public function prepare(ExtensionRequest $request, string $releasePath, string $extensionsPath): void {}
}
