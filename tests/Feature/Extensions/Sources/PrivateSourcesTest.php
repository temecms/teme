<?php

declare(strict_types=1);

use App\Domain\Extensions\Models\ExtensionSourceRecord;
use App\Domain\Extensions\Sources\ComposerSource;
use App\Domain\Extensions\Sources\ExtensionRequest;
use App\Domain\Extensions\Sources\ExtensionSource;
use App\Domain\Extensions\Sources\GitSource;
use App\Domain\Extensions\Sources\SourceKinds;
use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Support\Network\AddressRefused;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Fixtures\Plugins\Lists\FakeResolver;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(HostResolver::class, FakeResolver::answering([
        'github.com' => ['140.82.112.3'],
        'gitlab.com' => ['172.65.251.78'],
        'git.example.com' => ['93.184.216.34'],
        'packages.vendor.example' => ['93.184.216.35'],
        'git.internal' => ['10.0.0.5'],
    ]));
    config(['teme.extensions.private_hosts' => false]);
});

/**
 * A saved source as a kind receives it.
 */
function privateSource(string $kind, string $url, ?string $credential = null, ?string $username = null): ExtensionSource
{
    return new ExtensionSource((string) Str::ulid(), $kind, 'A source', $url, $username, $credential);
}

/**
 * A saved source's row, its key encrypted, and an install request that names it.
 */
function privateSourceRequest(string $kind, string $url, ?string $credential = null): ExtensionRequest
{
    $source = ExtensionSourceRecord::query()->create(['kind' => $kind, 'label' => 'A source', 'url' => $url, 'credential' => $credential]);

    // One request is open at a time: the one before ends first.
    DB::table('extension_requests')->update(['status' => 'done']);

    $id = strtolower((string) Str::ulid());
    DB::table('extension_requests')->insert([
        'id' => $id,
        'action' => 'install',
        'kind' => 'plugin',
        'package' => 'acme/link-suggestions',
        'constraint' => '^1.2',
        'source_kind' => $kind,
        'source_id' => $source->id,
        'status' => 'running',
        'requested_at' => now(),
    ]);

    return ExtensionRequest::fromRow(DB::table('extension_requests')->where('id', $id)->sole());
}

it('registers the Git and vendor kinds', function (): void {
    expect(app(SourceKinds::class)->get('git'))->toBeInstanceOf(GitSource::class)
        ->and(app(SourceKinds::class)->get('composer'))->toBeInstanceOf(ComposerSource::class);
});

it('adds a Git address as a vcs repository and a vendor\'s source as a composer repository', function (): void {
    expect(app(GitSource::class)->repositories(privateSource('git', 'https://github.com/acme/plugin.git', 'key'), '/var/teme/extensions'))
        ->toBe([['type' => 'vcs', 'url' => 'https://github.com/acme/plugin.git']])
        ->and(app(ComposerSource::class)->repositories(privateSource('composer', 'https://packages.vendor.example', 'key'), '/var/teme/extensions'))
        ->toBe([['type' => 'composer', 'url' => 'https://packages.vendor.example']]);
});

it('gives a Git token as the credential its host expects', function (string $url, ?string $username, array $auth): void {
    expect(app(GitSource::class)->auth(privateSource('git', $url, 'the-token', $username)))->toBe($auth);
})->with([
    'GitHub' => ['https://github.com/acme/plugin.git', null, ['github-oauth' => ['github.com' => 'the-token']]],
    'GitLab' => ['https://gitlab.com/acme/plugin.git', null, ['gitlab-token' => ['gitlab.com' => 'the-token']]],
    'another host' => ['https://git.example.com/acme/plugin.git', null, ['http-basic' => ['git.example.com' => ['username' => 'token', 'password' => 'the-token']]]],
    'another host, with a user name' => ['https://git.example.com/acme/plugin.git', 'deploy', ['http-basic' => ['git.example.com' => ['username' => 'deploy', 'password' => 'the-token']]]],
]);

it('gives no credential for a public Git repository', function (): void {
    expect(app(GitSource::class)->auth(privateSource('git', 'https://github.com/acme/plugin.git')))->toBe([]);
});

it('gives a vendor\'s key as a bearer token, or with the user name the vendor gives', function (): void {
    $composer = app(ComposerSource::class);

    expect($composer->auth(privateSource('composer', 'https://packages.vendor.example', 'license-key')))
        ->toBe(['bearer' => ['packages.vendor.example' => 'license-key']])
        ->and($composer->auth(privateSource('composer', 'https://packages.vendor.example', 'license-key', 'site@example.com')))
        ->toBe(['http-basic' => ['packages.vendor.example' => ['username' => 'site@example.com', 'password' => 'license-key']]]);
});

it('builds COMPOSER_AUTH for two sources holding both', function (): void {
    $auth = array_replace_recursive(
        app(GitSource::class)->auth(privateSource('git', 'https://git.example.com/acme/plugin.git', 'git-token')),
        app(ComposerSource::class)->auth(privateSource('composer', 'https://packages.vendor.example', 'license-key', 'site')),
    );

    expect(json_decode(json_encode($auth, JSON_THROW_ON_ERROR), true))->toBe(['http-basic' => [
        'git.example.com' => ['username' => 'token', 'password' => 'git-token'],
        'packages.vendor.example' => ['username' => 'site', 'password' => 'license-key'],
    ]]);
});

it('checks the address before Composer runs, and refuses one inside the server\'s network', function (string $kind): void {
    $source = $kind === 'git' ? app(GitSource::class) : app(ComposerSource::class);

    $source->prepare(privateSourceRequest($kind, 'https://git.example.com/acme/plugin.git'), sys_get_temp_dir(), sys_get_temp_dir());

    expect(fn () => $source->prepare(privateSourceRequest($kind, 'https://git.internal/acme/plugin.git'), sys_get_temp_dir(), sys_get_temp_dir()))
        ->toThrow(AddressRefused::class, 'git.internal is on a private network');
})->with(['git', 'composer']);

it('refuses a request whose source was removed', function (): void {
    $request = privateSourceRequest('git', 'https://git.example.com/acme/plugin.git');
    ExtensionSourceRecord::query()->delete();

    expect(fn () => app(GitSource::class)->prepare(ExtensionRequest::fromRow(DB::table('extension_requests')->sole()), sys_get_temp_dir(), sys_get_temp_dir()))
        ->toThrow(RuntimeException::class, 'The package source this request names was removed.');
});

it('writes the key to no log line, report, file or exception message', function (string $kind): void {
    $key = 'planted-key-'.Str::random(24);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.json_encode($message->context);
    });

    $folder = sys_get_temp_dir().'/teme-sources-'.Str::random(8);
    mkdir($folder.'/releases/r1', 0755, true);
    $source = $kind === 'git' ? app(GitSource::class) : app(ComposerSource::class);
    $request = privateSourceRequest($kind, 'https://git.internal/acme/plugin.git', $key);
    $saved = ExtensionSourceRecord::query()->sole()->toSource();

    try {
        $source->prepare($request, $folder.'/releases/r1', $folder);
        $this->fail('prepare() should have refused the private host.');
    } catch (Throwable $failure) {
        expect($failure->getMessage())->not->toContain($key)
            ->and($failure->getTraceAsString())->not->toContain($key);
    }

    $files = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)))
        ->map(fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()));

    expect(json_encode($source->auth($saved)))->toContain($key)
        ->and(implode("\n", $logged))->not->toContain($key)
        ->and(json_encode(DB::table('extension_requests')->get()))->not->toContain($key)
        ->and(json_encode($source->repositories($saved, $folder)))->not->toContain($key)
        ->and(print_r($saved, true))->not->toContain($key)
        ->and($files->implode("\n"))->not->toContain($key)
        ->and(DB::table('extension_sources')->value('credential'))->not->toContain($key);

    (new Filesystem)->deleteDirectory($folder);
})->with(['git', 'composer']);
