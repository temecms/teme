<?php

declare(strict_types=1);

use App\Domain\Extensions\Models\ExtensionSourceRecord;
use App\Domain\Extensions\Models\ExtensionUploadRecord;
use App\Domain\Extensions\Operations\AddExtensionSource;
use App\Domain\Extensions\Operations\Input\AddExtensionSourceInput;
use App\Domain\Extensions\Operations\Input\RemoveExtensionSourceInput;
use App\Domain\Extensions\Operations\Input\UploadExtensionInput;
use App\Domain\Extensions\Operations\RemoveExtensionSource;
use App\Domain\Extensions\Operations\UploadExtension;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = extAdmin();
    $this->add = fn (array $values = [], ?string $actor = null) => fn () => app(AddExtensionSource::class)->execute(new AddExtensionSourceInput(...[
        'kind' => 'git', 'label' => 'Acme’s Git', 'url' => 'https://git.example.com/acme/hello.git', 'username' => null, 'credential' => 'ghp_secret-key-1234',
        ...$values, 'actorId' => $actor ?? (string) $this->admin->id,
    ]));
    $this->remove = fn (string $id, ?string $actor = null) => fn () => app(RemoveExtensionSource::class)->execute(new RemoveExtensionSourceInput($id, $actor ?? (string) $this->admin->id));
    $this->upload = fn (string $bytes, ?string $actor = null) => fn () => app(UploadExtension::class)->execute(new UploadExtensionInput('hello-1.0.0.zip', $bytes, $actor ?? (string) $this->admin->id));
});

afterEach(function (): void {
    $root = config('teme.extensions.path');

    if (is_string($root) && str_contains($root, 'teme-ext-admin-')) {
        File::deleteDirectory($root);
    }
});

/**
 * A ZIP archive's bytes holding $files, by path.
 *
 * @param  array<string, string>  $files
 */
function extZip(array $files): string
{
    $file = tempnam(sys_get_temp_dir(), 'teme-ext-zip-');
    $zip = new ZipArchive;
    $zip->open($file, ZipArchive::OVERWRITE);

    foreach ($files as $path => $contents) {
        $zip->addFromString($path, $contents);
    }

    $zip->close();
    $bytes = (string) file_get_contents($file);
    unlink($file);

    return $bytes;
}

it('saves a source with its key encrypted, never readable as written', function (): void {
    $id = (($this->add)())();
    $raw = DB::table('extension_sources')->where('id', $id)->first();

    expect($raw->kind)->toBe('git')
        ->and($raw->label)->toBe('Acme’s Git')
        ->and($raw->url)->toBe('https://git.example.com/acme/hello.git')
        ->and((int) $raw->created_by)->toBe($this->admin->id)
        ->and($raw->credential)->not->toContain('ghp_secret')
        ->and(ExtensionSourceRecord::query()->findOrFail($id)->toSource()->credential)->toBe('ghp_secret-key-1234')
        ->and(json_encode(ExtensionSourceRecord::query()->findOrFail($id)))->not->toContain('credential');
});

it('saves a public source with no key', function (): void {
    $id = (($this->add)(['credential' => '', 'username' => ' ']))();

    expect(DB::table('extension_sources')->where('id', $id)->first())
        ->credential->toBeNull()
        ->username->toBeNull();
});

it('refuses a kind that isn’t saved, an address that isn’t https, a missing name, and the same address twice', function (array $values, string $message): void {
    expect(($this->add)($values))->toThrow(ValidationException::class, $message);
    expect(DB::table('extension_sources')->count())->toBe(0);
})->with([
    'packagist' => [['kind' => 'packagist'], '“packagist” isn’t a kind of package source that is saved.'],
    'upload' => [['kind' => 'upload'], '“upload” isn’t a kind of package source that is saved.'],
    'unknown' => [['kind' => 'ftp'], '“ftp” isn’t a kind of package source that is saved.'],
    'http' => [['url' => 'http://git.example.com/acme/hello.git'], 'The address must start with https:// and hold no username or password.'],
    'ssh' => [['url' => 'git@github.com:acme/hello.git'], 'The address must start with https://'],
    'with a password' => [['url' => 'https://me:secret@git.example.com/acme/hello.git'], 'hold no username or password'],
    'no name' => [['label' => '  '], 'Give the source a name of up to 100 characters.'],
]);

it('refuses the same address twice', function (): void {
    (($this->add)())();

    expect(($this->add)())->toThrow(ValidationException::class, 'This source is already saved.');
    expect(DB::table('extension_sources')->count())->toBe(1);
});

it('removes a source no package came from', function (): void {
    $id = (($this->add)())();

    (($this->remove)($id))();

    expect(DB::table('extension_sources')->count())->toBe(0);
});

it('refuses to remove a source the current plugins and themes came from, or one a request under way uses', function (): void {
    $id = (($this->add)())();
    extFolder(['acme/hello' => ['type' => 'teme-plugin', 'source' => 'git']], repositories: [['type' => 'vcs', 'url' => 'https://git.example.com/acme/hello.git']]);

    expect(($this->remove)($id))->toThrow(ValidationException::class, 'Packages installed now came from this source. Remove them first, or keep the source.');

    extFolder();
    extRequestRow('requested', ['source_kind' => 'git', 'source_id' => $id]);

    expect(($this->remove)($id))->toThrow(ValidationException::class, 'A request under way uses this source. Wait for it to end, or cancel it.');
    expect(DB::table('extension_sources')->count())->toBe(1);
});

it('keeps an upload as data: name, version and checksum read from its composer.json, nothing extracted', function (array $files): void {
    $bytes = extZip($files);

    $upload = (($this->upload)($bytes))();
    $row = ExtensionUploadRecord::query()->findOrFail($upload['id']);

    expect($upload)->toMatchArray(['package' => 'acme/hello', 'version' => '1.0.0'])
        ->and($row->file_name)->toBe('hello-1.0.0.zip')
        ->and($row->size)->toBe(strlen($bytes))
        ->and($row->sha256)->toBe(hash('sha256', $bytes))
        ->and((int) $row->uploaded_by)->toBe($this->admin->id)
        ->and($row->archive())->toBe($bytes)
        ->and(glob(sys_get_temp_dir().'/teme-upload-*') ?: [])->toBe([]);
})->with([
    'at the top' => [['composer.json' => '{"name": "acme/hello", "version": "1.0.0", "type": "teme-plugin"}', 'src/Hello.php' => '<?php echo "never run";']],
    'in one folder' => [['hello/composer.json' => '{"name": "acme/hello", "version": "1.0.0", "type": "teme-plugin"}', 'hello/src/Hello.php' => '<?php']],
]);

it('refuses an upload over the limit, one that isn’t a ZIP, and one with no package named, and writes nothing', function (Closure $bytes, string $message): void {
    config(['teme.extensions.upload_max' => 1048576]);

    expect(($this->upload)($bytes()))->toThrow(ValidationException::class, $message);
    expect(DB::table('extension_uploads')->count())->toBe(0);
})->with([
    'over the limit' => [fn () => extZip(['composer.json' => '{"name": "acme/hello", "version": "1.0.0"}', 'big.txt' => random_bytes(1100000)]), 'The archive is larger than 1 MB.'],
    'not a ZIP' => [fn () => "%PDF-1.7\n", 'That file isn’t a ZIP archive.'],
    'a broken ZIP' => [fn () => "PK\x03\x04garbage", 'The archive has no composer.json at its top, or one Teme can’t read.'],
    'no composer.json' => [fn () => extZip(['README.md' => 'hello']), 'The archive has no composer.json at its top'],
    'two folders' => [fn () => extZip(['one/composer.json' => '{"name": "acme/hello", "version": "1.0.0"}', 'two/x' => '']), 'The archive has no composer.json at its top'],
    'no version' => [fn () => extZip(['composer.json' => '{"name": "acme/hello"}']), 'must give the package’s name and its version'],
    'a bad name' => [fn () => extZip(['composer.json' => '{"name": "Hello World", "version": "1.0.0"}']), 'must give the package’s name and its version'],
    'a composer.json over 64 KB' => [fn () => extZip(['composer.json' => json_encode(['name' => 'acme/hello', 'version' => '1.0.0', 'description' => str_repeat('a', 70000)])]), 'The archive has no composer.json at its top, or one Teme can’t read.'],
]);

it('writes nothing when the person may not install plugins and themes', function (string $role): void {
    $person = (string) User::factory()->create()->assignRole($role)->id;
    $kept = (($this->add)())();

    expect(($this->add)(['url' => 'https://git.example.com/other.git'], $person))->toThrow(AuthorizationException::class)
        ->and(($this->remove)($kept, $person))->toThrow(AuthorizationException::class)
        ->and(($this->upload)(extZip(['composer.json' => '{"name": "acme/hello", "version": "1.0.0"}']), $person))->toThrow(AuthorizationException::class);
    expect(DB::table('extension_sources')->pluck('id')->all())->toBe([$kept])
        ->and(DB::table('extension_uploads')->count())->toBe(0);
})->with(['editor', 'author', 'contributor']);

it('asks for the password again, and writes nothing without it', function (): void {
    $kept = (($this->add)())();
    app()->instance(Elevation::class, new DeniedElevation);

    expect(($this->add)(['url' => 'https://git.example.com/other.git']))->toThrow(ElevationRequiredException::class)
        ->and(($this->remove)($kept))->toThrow(ElevationRequiredException::class)
        ->and(($this->upload)(extZip(['composer.json' => '{"name": "acme/hello", "version": "1.0.0"}'])))->toThrow(ElevationRequiredException::class);
    expect(DB::table('extension_sources')->count())->toBe(1)
        ->and(DB::table('extension_uploads')->count())->toBe(0);
});
