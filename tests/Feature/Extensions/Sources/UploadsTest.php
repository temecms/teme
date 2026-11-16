<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Sources\ArchiveReader;
use App\Domain\Extensions\Sources\ExtensionRequest;
use App\Domain\Extensions\Sources\SourceKinds;
use App\Domain\Extensions\Sources\UploadSource;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->extensions = sys_get_temp_dir().'/teme-uploads-'.Str::random(10);
    mkdir($this->extensions.'/packages', 0755, true);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->extensions);
});

/**
 * A ZIP built from a fixture package's folder, inside a top-level folder
 * unless $top is null; $files adds or replaces entries (null leaves one
 * out), and $build changes the archive before it is closed.
 *
 * @param  array<string, string|null>  $files
 * @param  (Closure(ZipArchive): void)|null  $build
 */
function uploadZip(string $package = 'hello-extension', ?string $top = 'hello-extension', array $files = [], ?Closure $build = null): string
{
    $folder = base_path("tests/Fixtures/Extensions/packages/{$package}");
    $entries = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
        $entries[substr($file->getPathname(), strlen($folder) + 1)] = (string) file_get_contents($file->getPathname());
    }

    $entries = array_filter([...$entries, ...$files], fn (?string $contents): bool => $contents !== null);
    ksort($entries);

    $path = (string) tempnam(sys_get_temp_dir(), 'teme-zip-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);

    foreach ($entries as $name => $contents) {
        $zip->addFromString(($top === null ? '' : "{$top}/").$name, $contents);
    }

    if ($build !== null) {
        $build($zip);
    }

    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/**
 * Unpacks an archive as the installer would for an upload of this package and version.
 */
function unpackUpload(string $bytes, string $package = 'fixtures/hello-extension', string $version = '1.0.0', ExtensionKind $kind = ExtensionKind::Plugin): string
{
    return app(ArchiveReader::class)->unpack($bytes, hash('sha256', $bytes), $package, $version, $kind, test()->extensions.'/packages');
}

it('unpacks a good archive into packages/<vendor>/<name>/<version>, stripped of its top folder', function (): void {
    $folder = unpackUpload(uploadZip());

    expect($folder)->toBe($this->extensions.'/packages/fixtures/hello-extension/1.0.0')
        ->and($folder.'/composer.json')->toBeFile()
        ->and($folder.'/teme-plugin.json')->toBeFile()
        ->and($folder.'/src/HelloExtension.php')->toBeFile()
        ->and(scandir($this->extensions.'/packages/fixtures/hello-extension'))->toBe(['.', '..', '1.0.0']);
});

it('unpacks an archive with its package at the root, and a theme', function (): void {
    expect(unpackUpload(uploadZip(top: null)).'/src/HelloExtension.php')->toBeFile()
        ->and(unpackUpload(uploadZip('hello-theme', 'hello-theme'), 'fixtures/hello-theme', '1.0.0', ExtensionKind::Theme).'/public/build/theme.css')->toBeFile();
});

it('writes files 0644 and folders 0755, whatever the umask', function (): void {
    $umask = umask(0077);

    try {
        $folder = unpackUpload(uploadZip());
    } finally {
        umask($umask);
    }

    expect(fileperms($folder) & 0777)->toBe(0755)
        ->and(fileperms($folder.'/src') & 0777)->toBe(0755)
        ->and(fileperms($folder.'/src/HelloExtension.php') & 0777)->toBe(0644)
        ->and(fileperms($folder.'/composer.json') & 0777)->toBe(0644);
})->skipOnWindows();

it('changes nothing when the same archive is unpacked again', function (): void {
    $bytes = uploadZip();
    $folder = unpackUpload($bytes);
    $before = filemtime($folder.'/composer.json');

    expect(unpackUpload($bytes))->toBe($folder)
        ->and(filemtime($folder.'/composer.json'))->toBe($before);
});

it('refuses a different archive claiming a name and version uploaded before', function (): void {
    unpackUpload(uploadZip());

    expect(fn () => unpackUpload(uploadZip(files: ['src/Extra.php' => '<?php // more'])))
        ->toThrow(RuntimeException::class, 'A different fixtures/hello-extension 1.0.0 was uploaded before. Raise the version.')
        ->and($this->extensions.'/packages/fixtures/hello-extension/1.0.0/src/Extra.php')->not->toBeFile();
});

it('refuses an unsafe archive and writes nothing', function (Closure $archive, string $reason): void {
    $bytes = $archive();

    expect(fn () => unpackUpload($bytes))->toThrow(RuntimeException::class, $reason)
        ->and((new Filesystem)->allFiles($this->extensions.'/packages', true))->toBe([]);
})->with([
    'a name that climbs out' => [fn () => uploadZip(files: ['../evil.php' => '<?php']), 'could reach outside its folder: hello-extension/../evil.php'],
    'an absolute path' => [fn () => uploadZip(top: null, build: fn (ZipArchive $zip) => $zip->addFromString('/etc/evil.php', '<?php')), 'could reach outside its folder: /etc/evil.php'],
    'a backslash' => [fn () => uploadZip(files: ['src\\..\\..\\evil.php' => '<?php']), 'could reach outside its folder'],
    'a drive letter' => [fn () => uploadZip(top: null, build: fn (ZipArchive $zip) => $zip->addFromString('C:/evil.php', '<?php')), 'could reach outside its folder: C:/evil.php'],
    'a symbolic link' => [fn () => uploadZip(build: function (ZipArchive $zip): void {
        $zip->addFromString('hello-extension/src/link.php', '/etc/passwd');
        $zip->setExternalAttributesName('hello-extension/src/link.php', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    }), 'The archive holds a symbolic link: hello-extension/src/link.php'],
    'more than 20,000 entries' => [fn () => uploadZip(build: function (ZipArchive $zip): void {
        for ($file = 0; $file < 20_001; $file++) {
            $zip->addFromString("hello-extension/many/{$file}.txt", '');
        }
    }), 'The archive holds more than 20,000 files.'],
    'a 300 MB entry that compresses small' => [fn () => uploadZip(build: function (ZipArchive $zip): void {
        $zeros = (string) tempnam(sys_get_temp_dir(), 'teme-zeros-');
        $handle = fopen($zeros, 'wb');
        ftruncate($handle, 300 * 1_048_576);
        fclose($handle);
        $zip->addFile($zeros, 'hello-extension/zeros.bin');
        register_shutdown_function(fn () => @unlink($zeros));
    }), 'The archive unpacks to more than 256 MB.'],
    'an entry outside the top folder' => [fn () => uploadZip(build: fn (ZipArchive $zip) => $zip->addFromString('other/readme.txt', 'hi')), 'The archive must hold one package'],
    'no composer.json' => [fn () => uploadZip(files: ['composer.json' => null]), 'The archive has no composer.json.'],
    'composer.json that isn\'t JSON' => [fn () => uploadZip(files: ['composer.json' => '{"name": ']), 'The archive’s composer.json isn’t valid JSON.'],
    'the wrong type' => [fn () => uploadZip(files: ['composer.json' => json_encode(['name' => 'fixtures/hello-extension', 'version' => '1.0.0', 'type' => 'library'])]), 'The archive’s composer.json must give the type teme-plugin.'],
    'no manifest' => [fn () => uploadZip(files: ['teme-plugin.json' => null]), 'The archive has no teme-plugin.json beside its composer.json.'],
    'a name differing from the upload' => [fn () => uploadZip(files: ['composer.json' => json_encode(['name' => 'fixtures/other', 'version' => '1.0.0', 'type' => 'teme-plugin'])]), 'The archive’s composer.json names fixtures/other, not fixtures/hello-extension.'],
    'a version differing from the upload' => [fn () => uploadZip(files: ['composer.json' => json_encode(['name' => 'fixtures/hello-extension', 'version' => '2.0.0', 'type' => 'teme-plugin'])]), 'The archive’s composer.json gives version 2.0.0, not 1.0.0.'],
    'not a ZIP' => [fn () => 'just some text', 'The upload isn’t a ZIP archive the installer can read.'],
]);

it('refuses an archive whose bytes don\'t match the upload\'s checksum', function (): void {
    $bytes = uploadZip();

    expect(fn () => app(ArchiveReader::class)->unpack($bytes, hash('sha256', 'something else'), 'fixtures/hello-extension', '1.0.0', ExtensionKind::Plugin, $this->extensions.'/packages'))
        ->toThrow(RuntimeException::class, 'The uploaded file has changed since it was uploaded.');
});

it('refuses a theme archive asked for as a plugin', function (): void {
    expect(fn () => unpackUpload(uploadZip('hello-theme', 'hello-theme'), 'fixtures/hello-theme'))
        ->toThrow(RuntimeException::class, 'must give the type teme-plugin');
});

it('registers the upload kind', function (): void {
    expect(app(SourceKinds::class)->get('upload'))->toBeInstanceOf(UploadSource::class);
});

it('adds every uploaded package as a path repository, copied, never linked', function (): void {
    expect(app(UploadSource::class)->repositories(null, '/var/teme/extensions'))->toBe([[
        'type' => 'path',
        'url' => '/var/teme/extensions/packages/*/*/*',
        'options' => ['symlink' => false],
    ]]);
});

/**
 * An upload's row and an install request for it.
 */
function uploadRequest(string $bytes, string $package = 'fixtures/hello-extension', string $version = '1.0.0', ?string $requested = null): ExtensionRequest
{
    $upload = strtolower((string) Str::ulid());
    DB::table('extension_uploads')->insert([
        'id' => $upload,
        'file_name' => 'hello-extension.zip',
        'size' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'package' => $package,
        'version' => $version,
        'bytes' => DB::raw("decode('".bin2hex($bytes)."', 'hex')"),
        'created_at' => now(),
    ]);

    $id = strtolower((string) Str::ulid());
    DB::table('extension_requests')->insert([
        'id' => $id,
        'action' => 'install',
        'kind' => 'plugin',
        'package' => $requested ?? $package,
        'constraint' => $version,
        'source_kind' => 'upload',
        'upload_id' => $upload,
        'status' => 'running',
        'requested_at' => now(),
    ]);

    return ExtensionRequest::fromRow(DB::table('extension_requests')->where('id', $id)->sole());
}

it('unpacks a request\'s upload from the database before Composer runs', function (): void {
    app(UploadSource::class)->prepare(uploadRequest(uploadZip()), $this->extensions.'/releases/r1', $this->extensions);

    expect($this->extensions.'/packages/fixtures/hello-extension/1.0.0/src/HelloExtension.php')->toBeFile();
});

it('refuses an upload for another package than the request names', function (): void {
    expect(fn () => app(UploadSource::class)->prepare(uploadRequest(uploadZip(), requested: 'fixtures/other'), $this->extensions.'/releases/r1', $this->extensions))
        ->toThrow(RuntimeException::class, 'The upload holds fixtures/hello-extension, but the request is for fixtures/other.');
});

it('refuses a request whose upload is gone', function (): void {
    $request = uploadRequest(uploadZip());
    DB::table('extension_uploads')->delete();

    expect(fn () => app(UploadSource::class)->prepare(ExtensionRequest::fromRow(DB::table('extension_requests')->sole()), $this->extensions.'/releases/r1', $this->extensions))
        ->toThrow(RuntimeException::class, 'The uploaded file for this request is gone.');
});

it('lets Composer install an unpacked upload from the path repository, copied', function (): void {
    unpackUpload(uploadZip());
    unpackUpload(uploadZip('hello-extension-1.1.0', 'hello-extension'), 'fixtures/hello-extension', '1.1.0');

    $release = $this->extensions.'/releases/r1';
    mkdir($release, 0755, true);
    file_put_contents($release.'/composer.json', json_encode([
        'name' => 'teme/site-extensions',
        'type' => 'project',
        'require' => ['fixtures/hello-extension' => '1.0.0'],
        'replace' => ['teme/contracts' => 'dev-main'],
        'repositories' => [['packagist.org' => false], ...app(UploadSource::class)->repositories(null, $this->extensions)],
        'config' => ['allow-plugins' => false],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    $composer = new Process(
        [config('teme.extensions.composer'), 'update', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress', '--no-audit', '--working-dir='.$release],
        $release,
        ['COMPOSER_HOME' => $this->extensions.'/.composer', 'COMPOSER_ALLOW_SUPERUSER' => '1'],
    );
    $composer->setTimeout(120)->run();

    expect($composer->getExitCode())->toBe(0, $composer->getErrorOutput())
        ->and($release.'/vendor/fixtures/hello-extension/src/HelloExtension.php')->toBeFile()
        ->and(is_link($release.'/vendor/fixtures/hello-extension'))->toBeFalse()
        ->and(json_decode((string) file_get_contents($release.'/composer.lock'), true)['packages'][0]['version'])->toBe('1.0.0');
})->group('composer');
