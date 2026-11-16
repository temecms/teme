<?php

declare(strict_types=1);

namespace Tests\Fixtures\Extensions;

use App\Domain\Extensions\CorePins;
use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Installer\CompatibilityCheck;
use App\Domain\Extensions\Installer\ComposerRunner;
use App\Domain\Extensions\Installer\InstallerRunner;
use App\Domain\Extensions\Installer\ProjectFile;
use App\Domain\Extensions\Installer\ReleaseCaches;
use App\Domain\Extensions\Installer\ReleaseStore;
use App\Domain\Extensions\Installer\StageValidator;
use App\Domain\Extensions\RequestStatus;
use App\Domain\Extensions\Sources\ExtensionRequest;
use App\Domain\Extensions\Sources\SourceKinds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * An extensions folder the installer's tests build in the system's temporary
 * folder: its releases, Composer run on the fixture packages with Packagist
 * off, and the pins of this checkout's own vendor/.
 */
final class ExtensionsFolder
{
    /** The fixture packages, as one path repository. */
    public const string PACKAGES = __DIR__.'/packages';

    /** The source kind the fixture packages come from. */
    public const string SOURCE = 'fixtures';

    public readonly ReleaseStore $store;

    public function __construct(public readonly string $root)
    {
        @mkdir($root.'/releases', 0755, true);
        @mkdir($root.'/packages', 0755, true);
        @mkdir($root.'/.composer', 0700, true);
        $this->store = new ReleaseStore($root);
    }

    /** A new, empty extensions folder. */
    public static function make(): self
    {
        return new self(sys_get_temp_dir().'/teme-extensions-'.bin2hex(random_bytes(6)));
    }

    /**
     * This checkout's pins, the dev packages included: the tests run with them.
     *
     * @return array{replace: array<string, string>, provide: array<string, string>, hash: string}
     */
    public static function pins(): array
    {
        static $pins = null;

        return $pins ??= (new CorePins)->fromFile(base_path('vendor/composer/installed.json'), 'teme/teme');
    }

    /**
     * This checkout's pins with core libraries at the versions given, as an
     * image that ships them would have them.
     *
     * @param  array<string, string>  $replace
     *
     * @return array{replace: array<string, string>, provide: array<string, string>, hash: string}
     */
    public static function pinsWith(array $replace): array
    {
        $pins = self::pins();
        $pins['replace'] = [...$pins['replace'], ...$replace];
        ksort($pins['replace']);
        $pins['hash'] = substr(hash('sha256', (string) json_encode([$pins['replace'], $pins['provide']])), 0, 16);

        return $pins;
    }

    /**
     * Writes the pins to a file, as the image's bootstrap/cache has them.
     */
    public function pinsFile(): string
    {
        $file = $this->root.'/teme-core-pins.php';
        (new CorePins)->write(self::pins(), $file);

        return $file;
    }

    /**
     * The path repository the fixtures install from.
     *
     * @return array<string, mixed>
     */
    public static function repository(string $glob = '*'): array
    {
        return ['type' => 'path', 'url' => self::PACKAGES.'/'.$glob, 'options' => ['symlink' => false]];
    }

    /** A Composer runner for this folder. */
    public function composer(): ComposerRunner
    {
        return new ComposerRunner((string) config('teme.extensions.composer'), $this->root);
    }

    /**
     * Makes a release whose composer.json requires what the release it
     * builds on did, copying its lock too.
     *
     * @param  array{replace: array<string, string>, provide: array<string, string>, hash: string}|null  $pins
     * @param  list<array<string, mixed>>  $repositories
     */
    public function stage(?string $from = null, ?array $pins = null, array $repositories = []): string
    {
        $id = strtolower((string) Str::ulid());
        usleep(1000);
        $path = $this->store->create($id);
        $files = new ProjectFile;
        $current = $from === null ? null : $files->read($this->store->path($from));

        if ($from !== null && is_file($this->store->path($from).'/composer.lock')) {
            copy($this->store->path($from).'/composer.lock', $path.'/composer.lock');
        }

        $files->write($path, $files->compose($current, $pins ?? self::pins(), [self::repository(), ...$repositories], false));

        return $id;
    }

    /** A runner for this folder, with Packagist off and the fixtures as a source. */
    public function runner(?array $pins = null): InstallerRunner
    {
        app()->instance('tests.fixtures-source', new FixturesSource);
        app()->tag('tests.fixtures-source', SourceKinds::TAG);

        return new InstallerRunner(
            $this->store,
            $this->composer(),
            app()->make(StageValidator::class, ['composer' => $this->composer(), 'imageVendor' => base_path('vendor'), 'imageCodes' => fn (): array => ['plugins' => [], 'themes' => []]]),
            new CompatibilityCheck,
            new ReleaseCaches,
            new ProjectFile,
            app(SourceKinds::class),
            $pins ?? self::pins(),
            false,
            fn (string $path): float => 10.0 * 1024 * 1024 * 1024,
        );
    }

    /** A request as the installer reads it. */
    public static function request(ExtensionAction $action, ?string $package = null, ?string $constraint = null, ExtensionKind $kind = ExtensionKind::Plugin): ExtensionRequest
    {
        return new ExtensionRequest(
            id: strtolower((string) Str::ulid()), action: $action, kind: $kind, package: $package, constraint: $constraint,
            sourceKind: 'packagist', sourceId: null, uploadId: null, status: RequestStatus::Running, step: null,
            steps: [], needs: [], agreed: [], report: [], error: null, fromRelease: null, toRelease: null,
            requestedBy: null, agreedBy: null, cancelledBy: null, requestedAt: CarbonImmutable::now(), startedAt: null, finishedAt: null,
        );
    }

    /**
     * Stages a release on the current one with this package installed,
     * stamped for the core, and switches to it. Validates nothing.
     *
     * @param  array<string, mixed>|null  $repository  another repository to install from
     * @param  array{replace: array<string, string>, provide: array<string, string>, hash: string}|null  $pins
     */
    public function install(string $package, string $constraint = '*', ?array $repository = null, ?array $pins = null): string
    {
        $id = $this->stage($this->store->current(), $pins, $repository === null ? [] : [$repository]);
        $result = $this->composer()->change(self::request(ExtensionAction::Install, $package, $constraint), $this->store->path($id));

        if (! $result->ok()) {
            throw new RuntimeException($result->output);
        }

        $this->stamp($id, ($pins ?? self::pins())['hash']);
        $this->store->swap($id);

        return $id;
    }

    /** Writes the stamp that says this release was checked against this core. */
    public function stamp(string $id, ?string $hash = null): void
    {
        @mkdir($this->store->path($id).'/compat', 0755, true);
        touch($this->store->path($id).'/compat/'.($hash ?? self::pins()['hash']));
    }

    /** Deletes the folder. */
    public function delete(): void
    {
        File::deleteDirectory($this->root);
    }
}
