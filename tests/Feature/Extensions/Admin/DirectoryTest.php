<?php

declare(strict_types=1);

use App\Domain\Extensions\Directory\RefreshDirectory;
use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Operations\Input\RequestExtensionChangeInput;
use App\Domain\Extensions\Operations\RequestExtensionChange;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/support.php';
require_once __DIR__.'/../Sources/directory-support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(RoleSeeder::class);
    $this->admin = extAdmin();
    $keys = dirKeys();
    config(['teme.directory.public_keys' => [$keys['public']]]);
    dirServe(dirFiles($keys['secret'], [
        dirPackage('acme/links', listing: ['label' => 'Link suggestions', 'summary' => 'Suggests internal links.']),
        dirPackage('acme/related', listing: ['label' => 'Related stories', 'picked' => true, 'categories' => ['reading']]),
        dirPackage('acme/quiet', 'theme', listing: ['label' => 'Quiet']),
    ]));
    $this->ask = fn (string $package, ExtensionKind $kind = ExtensionKind::Plugin) => fn () => app(RequestExtensionChange::class)->execute(new RequestExtensionChangeInput(
        action: ExtensionAction::Install, kind: $kind, package: $package, sourceKind: 'directory', actorId: (string) $this->admin->id,
    ));
    $this->switchOff = function (): void {
        app(UpdateSettings::class)->execute(new UpdateSettingsInput('directory', ['enabled' => false], [], [], 0, (string) $this->admin->id));
    };
});

afterEach(function (): void {
    $root = config('teme.extensions.path');

    if (is_string($root) && str_contains($root, 'teme-ext-admin-')) {
        File::deleteDirectory($root);
    }
});

it('lists the directory’s plugins on Add plugin, the team’s picks first, reading the directory first', function (): void {
    $answer = $this->actingAs($this->admin)->getJson(route('extensions.directory', ['kind' => 'plugin']))->assertOk()->json();

    expect($answer['enabled'])->toBeTrue()
        ->and($answer['error'])->toBeNull()
        ->and(array_column($answer['results'], 'name'))->toBe(['acme/related', 'acme/links'])
        ->and($answer['results'][1])->toMatchArray(['label' => 'Link suggestions', 'summary' => 'Suggests internal links.', 'author' => 'Acme', 'latest' => '1.2.0']);
});

it('lists themes on Add theme, apart from plugins', function (): void {
    $answer = $this->actingAs($this->admin)->getJson(route('extensions.directory', ['kind' => 'theme']))->json();

    expect(array_column($answer['results'], 'name'))->toBe(['acme/quiet']);
});

it('searches by every word given', function (): void {
    $answer = $this->actingAs($this->admin)->getJson(route('extensions.directory', ['kind' => 'plugin', 'q' => 'internal links']))->json();

    expect(array_column($answer['results'], 'name'))->toBe(['acme/links']);
});

it('reads the directory again only after an hour', function (): void {
    $this->actingAs($this->admin)->getJson(route('extensions.directory'));
    $this->actingAs($this->admin)->getJson(route('extensions.directory'));
    Http::assertSentCount(2);

    $this->travel(61)->minutes();
    $this->actingAs($this->admin)->getJson(route('extensions.directory'));
    Http::assertSentCount(4);
});

it('shows a package’s detail: screenshots, its permissions in plain words, and the releases not held', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    app(RefreshDirectory::class)->run();

    $package = $this->actingAs($this->admin)->getJson(route('extensions.directory.package', ['package' => 'acme/links', 'kind' => 'plugin']))->assertOk()->json('package');

    expect($package['readme'])->toBe("Install it.\n")
        ->and($package['screenshots'])->toBe([['url' => 'https://temecms.github.io/directory/assets/acme/links/1.2.0/one.png', 'caption' => 'The screen']])
        ->and($package['permissions'])->toBe([
            ['key' => 'content.read', 'words' => 'Read your posts, pages and other content, including drafts'],
            ['key' => 'http', 'words' => 'Connects to api.example.org'],
        ])
        ->and(array_column($package['releases'], 'version'))->toBe(['1.2.0'])
        ->and($package['links'])->toBe(['source' => 'https://github.com/acme/links', 'issues' => 'https://github.com/acme/links/issues', 'docs' => null, 'support' => null]);
});

it('records a directory install through the request and installer flow', function (): void {
    extFolder();
    app()->instance(Elevation::class, new OperatorElevation);
    app(RefreshDirectory::class)->run();

    $id = (($this->ask)('acme/links'))();
    $row = DB::table('extension_requests')->where('id', $id)->first();

    expect($row->source_kind)->toBe('directory')
        ->and($row->package)->toBe('acme/links')
        ->and($row->kind)->toBe('plugin')
        ->and($row->source_id)->toBeNull()
        ->and($row->constraint)->toBe('*')
        ->and($row->status)->toBe('requested');
});

it('refuses to install from the directory what it doesn’t list as that kind', function (string $package, ExtensionKind $kind): void {
    extFolder();
    app()->instance(Elevation::class, new OperatorElevation);
    app(RefreshDirectory::class)->run();

    expect(($this->ask)($package, $kind))->toThrow(ValidationException::class, "{$package} isn’t in the directory’s list.");
    expect(DB::table('extension_requests')->count())->toBe(0);
})->with([
    'not listed' => ['acme/missing', ExtensionKind::Plugin],
    'listed as a theme' => ['acme/quiet', ExtensionKind::Plugin],
]);

it('hides the directory and installs nothing from it once the site switches it off', function (): void {
    extFolder();
    app()->instance(Elevation::class, new OperatorElevation);
    app(RefreshDirectory::class)->run();
    ($this->switchOff)();

    expect($this->actingAs($this->admin)->getJson(route('extensions.directory'))->json())->toBe(['enabled' => false, 'results' => [], 'error' => null]);
    $this->actingAs($this->admin)->getJson(route('extensions.directory.package', ['package' => 'acme/links']))->assertNotFound();
    app()->instance(Elevation::class, new OperatorElevation);
    expect(($this->ask)('acme/links'))->toThrow(ValidationException::class, 'The plugin and theme directory is switched off on this site.');
    expect(DB::table('extension_requests')->count())->toBe(0);
});

it('lets only a person who installs plugins and themes browse it', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->getJson(route('extensions.directory'))->assertForbidden();
});
