<?php

declare(strict_types=1);

use App\Domain\Extensions\Directory\DirectoryReader;
use App\Domain\Extensions\Directory\RefreshDirectory;
use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Operations\Input\RecordDirectoryIndexInput;
use App\Domain\Extensions\Operations\RecordDirectoryIndex;
use App\Domain\Extensions\Queries\DirectoryPackages;
use App\Domain\Extensions\RequestStatus;
use App\Domain\Extensions\Sources\DirectorySource;
use App\Domain\Extensions\Sources\ExtensionRequest;
use App\Domain\Extensions\Sources\SourceKinds;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/directory-support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->keys = dirKeys();
    config(['teme.directory.address' => DirectoryReader::DEFAULT_ADDRESS, 'teme.directory.public_keys' => [$this->keys['public']]]);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->refresh = fn (): ?string => app(RefreshDirectory::class)->run();
    $this->kept = fn (): ?int => app(DirectoryPackages::class)->state()['sequence'];
});

it('keeps an index whose signature is the directory key’s', function (): void {
    dirServe(dirFiles($this->keys['secret'], [dirPackage('acme/links')]));

    expect(($this->refresh)())->toBeNull()
        ->and(($this->kept)())->toBe(5)
        ->and(app(DirectoryPackages::class)->kept()?->package('acme/links'))->not->toBeNull()
        ->and(app(DirectoryPackages::class)->state()['error'])->toBeNull();
});

it('refuses an index signed with another key, and keeps nothing', function (): void {
    dirServe(dirFiles(dirKeys()['secret'], [dirPackage('acme/links')]));

    expect(($this->refresh)())->toBe('The directory’s list isn’t signed with the directory’s key, so it was refused. It may have been changed on the way.')
        ->and(($this->kept)())->toBeNull()
        ->and(app(DirectoryPackages::class)->kept())->toBeNull()
        ->and(app(DirectoryPackages::class)->state()['error'])->toContain('isn’t signed');
});

it('refuses an index changed after it was signed', function (): void {
    $files = dirFiles($this->keys['secret'], [dirPackage('acme/links')]);
    $files['index.json'] = str_replace('"picked":false', '"picked":true', $files['index.json']);
    dirServe($files);

    expect(($this->refresh)())->toContain('isn’t signed')->and(($this->kept)())->toBeNull();
});

it('accepts an index signed with any listed key, so the key can be replaced', function (): void {
    $next = dirKeys();
    config(['teme.directory.public_keys' => [$this->keys['public'], $next['public']]]);
    dirServe(dirFiles($next['secret'], [dirPackage('acme/links')]));

    expect(($this->refresh)())->toBeNull()->and(($this->kept)())->toBe(5);

    // Once the old key is dropped, a list signed with it is refused.
    config(['teme.directory.public_keys' => [$next['public']]]);
    dirServe(dirFiles($this->keys['secret'], [dirPackage('acme/links')], ['sequence' => 6]));

    expect(($this->refresh)())->toContain('isn’t signed')->and(($this->kept)())->toBe(5);
});

it('reads nothing while it holds no key but the placeholder', function (): void {
    Http::fake();
    config(['teme.directory.public_keys' => ['DIRECTORY_PUBLIC_KEY_GOES_HERE']]);

    expect(($this->refresh)())->toBe('This Teme has no key to check the directory’s signature with, so it reads nothing from the directory.')
        ->and(($this->kept)())->toBeNull();
    Http::assertNothingSent();
});

it('refuses an older index than one already kept, and keeps the newer one', function (): void {
    dirServe(dirFiles($this->keys['secret'], [dirPackage('acme/links')], ['sequence' => 9]));
    ($this->refresh)();

    dirServe(dirFiles($this->keys['secret'], [dirPackage('acme/old')], ['sequence' => 8]));

    expect(($this->refresh)())->toBe('The directory sent an older list (number 8) than one Teme has already seen (number 9), so it was refused.')
        ->and(($this->kept)())->toBe(9)
        ->and(app(DirectoryPackages::class)->kept()?->package('acme/links'))->not->toBeNull();
});

it('refuses an older index under its own lock too, however it was read', function (): void {
    dirServe(dirFiles($this->keys['secret'], [], ['sequence' => 9]));
    ($this->refresh)();
    $older = json_decode(dirFiles($this->keys['secret'], [], ['sequence' => 3])['index.json'], true);

    app(RecordDirectoryIndex::class)->execute(new RecordDirectoryIndexInput($older, null, CarbonImmutable::now()));

    expect(($this->kept)())->toBe(9)->and(app(DirectoryPackages::class)->state()['error'])->toContain('older list (number 3)');
});

it('refuses an index whose expiry has passed', function (): void {
    dirServe(dirFiles($this->keys['secret'], [], ['expires' => '2026-01-01T00:00:00Z']));

    expect(($this->refresh)())->toBe('The directory’s list ran out on 2026-01-01 and no newer one has come, so Teme won’t install from it.')
        ->and(($this->kept)())->toBeNull();
});

it('stops installing from a kept index once it runs out', function (): void {
    dirServe(dirFiles($this->keys['secret'], [dirPackage('acme/links')]));
    ($this->refresh)();
    $this->travel(15)->days();

    expect(app(DirectoryPackages::class)->search('', ExtensionKind::Plugin)['error'])->toContain('ran out on');
});

it('says why when the directory doesn’t answer, keeping the last good index', function (): void {
    dirServe(dirFiles($this->keys['secret'], [dirPackage('acme/links')]));
    ($this->refresh)();
    dirServe([]);

    expect(($this->refresh)())->toBe('The directory didn’t answer with index.json. Try again later.')
        ->and(($this->kept)())->toBe(5);
});

it('refuses a detail file that doesn’t match the signed index', function (): void {
    $files = dirFiles($this->keys['secret'], [dirPackage('acme/links')]);
    dirServe($files);
    ($this->refresh)();
    $files['packages/acme/links.json'] = str_replace('Install it.', 'Run this first.', $files['packages/acme/links.json']);
    dirServe($files);

    expect(app(DirectoryPackages::class)->detail('acme/links', ExtensionKind::Plugin))
        ->toBe(['package' => null, 'error' => 'The directory’s packages/acme/links.json doesn’t match its signed list, so it was refused. It may have been changed on the way.']);
});

it('asks nothing while the directory is switched off', function (): void {
    Http::fake();
    DB::table('settings')->insert(['group_key' => 'directory', 'overrides' => json_encode(['enabled' => false])]);

    expect(($this->refresh)())->toBeNull()->and(($this->kept)())->toBeNull();
    Http::assertNothingSent();
});

it('is registered as a source kind with the key directory', function (): void {
    expect(app(SourceKinds::class)->get('directory'))->toBeInstanceOf(DirectorySource::class);
});

describe('in the installer', function (): void {
    beforeEach(function (): void {
        $this->root = sys_get_temp_dir().'/teme-ext-dir-'.Str::random(12);
        mkdir($this->root.'/releases/r', 0755, true);
        $this->request = fn (string $package = 'acme/links', ExtensionKind $kind = ExtensionKind::Plugin): ExtensionRequest => new ExtensionRequest(
            id: strtolower((string) Str::ulid()), action: ExtensionAction::Install, kind: $kind, package: $package, constraint: '*',
            sourceKind: 'directory', sourceId: null, uploadId: null, status: RequestStatus::Running, step: null, steps: [], needs: [], agreed: [], report: [],
            error: null, fromRelease: null, toRelease: null, requestedBy: null, agreedBy: null, cancelledBy: null,
            requestedAt: CarbonImmutable::now(), startedAt: null, finishedAt: null,
        );
    });

    afterEach(function (): void {
        File::deleteDirectory($this->root);
    });

    it('adds the checked packages.json as a Composer repository read from the extensions folder', function (): void {
        $files = dirFiles($this->keys['secret'], [dirPackage('acme/links')]);
        dirServe($files);
        ($this->refresh)();
        $source = app(DirectorySource::class);

        $source->prepare(($this->request)(), $this->root.'/releases/r', $this->root);

        expect($source->repositories(null, $this->root))->toBe([['type' => 'composer', 'url' => 'file://'.$this->root.'/directory']])
            ->and(file_get_contents($this->root.'/directory/packages.json'))->toBe($files['composer/packages.json']);
    });

    it('refuses a packages.json that doesn’t match the signed index, and writes nothing', function (): void {
        $files = dirFiles($this->keys['secret'], [dirPackage('acme/links')]);
        dirServe($files);
        ($this->refresh)();
        $files['composer/packages.json'] = '{"packages":{"acme/links":{"9.9.9":{"dist":{"url":"https://evil.example/x.zip"}}}}}';
        dirServe($files);

        expect(fn () => app(DirectorySource::class)->prepare(($this->request)(), $this->root.'/releases/r', $this->root))
            ->toThrow(RuntimeException::class, 'The directory’s composer/packages.json doesn’t match its signed list');
        expect(file_exists($this->root.'/directory/packages.json'))->toBeFalse();
    });

    it('refuses a package the index doesn’t list as that kind', function (): void {
        dirServe(dirFiles($this->keys['secret'], [dirPackage('acme/links')]));
        ($this->refresh)();

        expect(fn () => app(DirectorySource::class)->prepare(($this->request)('acme/links', ExtensionKind::Theme), $this->root.'/releases/r', $this->root))
            ->toThrow(RuntimeException::class, 'acme/links isn’t in the directory’s list.');
    });
});

describe('who may record an index', function (): void {
    beforeEach(function (): void {
        $this->seed(RoleSeeder::class);
        $this->index = json_decode(dirFiles($this->keys['secret'])['index.json'], true);
    });

    it('writes nothing for a person who doesn’t install plugins and themes', function (): void {
        $editor = User::factory()->create()->assignRole('editor');

        expect(fn () => app(RecordDirectoryIndex::class)->execute(new RecordDirectoryIndexInput($this->index, null, CarbonImmutable::now(), (string) $editor->id)))
            ->toThrow(AuthorizationException::class);
        expect(DB::table('directory_index')->count())->toBe(0);
    });

    it('writes nothing with no person and no operator', function (): void {
        app()->instance(Elevation::class, new DeniedElevation);

        expect(fn () => app(RecordDirectoryIndex::class)->execute(new RecordDirectoryIndexInput($this->index, null, CarbonImmutable::now())))
            ->toThrow(AuthorizationException::class);
        expect(DB::table('directory_index')->count())->toBe(0);
    });

    it('records for an administrator', function (): void {
        $admin = User::factory()->create()->assignRole('administrator');

        app(RecordDirectoryIndex::class)->execute(new RecordDirectoryIndexInput($this->index, null, CarbonImmutable::now(), (string) $admin->id));

        expect(DB::table('directory_index')->value('sequence'))->toBe(5);
    });
});
