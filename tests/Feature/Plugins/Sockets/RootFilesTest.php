<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Contributions\Contributions;
use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestValidator;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\PublicRoutes\RootFileNames;
use App\Domain\PublicRoutes\RootFiles;
use App\Domain\Redirects\Operations\CreateRedirect;
use App\Domain\Redirects\Operations\Input\CreateRedirectInput;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\SettingsCheck;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/**
 * Registers a fixture plugin's settings group and root-file check as its
 * start would (the tests point Teme at the fixtures after boot), then
 * enables it as the operator.
 *
 * @param  array<string, mixed>  $manifest
 */
function rootFilesEnable(array $manifest, bool $register = true): void
{
    $read = new Manifest($manifest);

    if ($register) {
        app(SettingsRegistry::class)->group(
            DeclaredSettings::group($read->code(), $read->label(), $read->label(), $read->version(), $read->contributes()['settings'] ?? [], rootFiles: true),
            $read->name(),
        );
        RootFileNames::register(app(), $read->name(), $read->code());
    }

    app(EnablePlugin::class)->execute(new EnablePluginInput(
        name: $read->name(),
        version: $read->version(),
        capabilities: $read->capabilities(),
        hosts: [],
        hooks: [],
        actorId: null,
    ));
}

/**
 * Saves a plugin's site key as the operator would on its settings page.
 */
function rootFilesSaveKey(string $code, string $key, User $admin): void
{
    app(UpdateSettings::class)->execute(new UpdateSettingsInput("plugin.{$code}", ['site_key' => $key], [], [], 0, (string) $admin->id));
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    app()->instance(Elevation::class, new OperatorElevation);
    $this->admin = User::factory()->create()->assignRole('administrator');

    $this->other = PluginFixtures::copy('keyfile', ['name' => 'tests/keyfile-two', 'code' => 'keyfile-two', 'label' => 'Keyfile two'], ['name' => 'tests/keyfile-two']);
    PluginFixtures::use('keyfile', $this->other);
    $this->manifest = PluginFixtures::manifest('keyfile');
    $this->otherManifest = json_decode((string) file_get_contents($this->other.'/teme-plugin.json'), true);
});

afterEach(fn () => PluginFixtures::forget());

it('answers the file once the setting holds a name, as plain text that is never indexed or stored', function (): void {
    $folder = cacheOn();

    try {
        rootFilesEnable($this->manifest);
        rootFilesSaveKey('keyfile', 'abcd1234ef', $this->admin);

        $first = addrGet('/abcd1234ef.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // Outside production Teme adds nofollow to every answer's X-Robots-Tag.
        expect($first->getContent())->toBe('abcd1234ef')
            ->and((string) $first->headers->get('X-Robots-Tag'))->toContain('noindex')
            ->and((string) $first->headers->get('Cache-Control'))->toContain('no-store')
            ->and($first->headers->get('X-Teme-Cache'))->toStartWith('BYPASS');

        addrGet('/abcd1234ef.txt')->assertOk();
        expect(cacheEntry('/abcd1234ef.txt'))->toBeNull();

        // The literal file answers too.
        expect(addrGet('/keyfile-literal.txt')->assertOk()->getContent())->toBe('A literal body.');
    } finally {
        cacheOff($folder);
    }
});

it('answers the stored "not found" page before the setting is saved, and the save purges it', function (): void {
    $folder = cacheOn();

    try {
        rootFilesEnable($this->manifest);

        addrGet('/abcd1234ef.txt')->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS');
        expect(cacheEntry('/abcd1234ef.txt')?->tags)->toContain(PageTags::ROOT_FILES);
        addrGet('/abcd1234ef.txt')->assertNotFound()->assertHeader('X-Teme-Cache', 'HIT');

        rootFilesSaveKey('keyfile', 'abcd1234ef', $this->admin);

        expect(cacheEntry('/abcd1234ef.txt'))->toBeNull();
        expect(addrGet('/abcd1234ef.txt')->assertOk()->getContent())->toBe('abcd1234ef');
    } finally {
        cacheOff($folder);
    }
});

it('purges the stored "not found" pages at root-file addresses when such a plugin is enabled or disabled', function (): void {
    $folder = cacheOn();

    try {
        addrGet('/keyfile-literal.txt')->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS');

        rootFilesEnable($this->manifest);

        expect(cacheEntry('/keyfile-literal.txt'))->toBeNull();
        addrGet('/keyfile-literal.txt')->assertOk();

        app(DisablePlugin::class)->execute(new DisablePluginInput('tests/keyfile', null));
        addrGet('/keyfile-literal.txt')->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS');

        rootFilesEnable($this->manifest, register: false);
        expect(cacheEntry('/keyfile-literal.txt'))->toBeNull();

        app(DisablePlugin::class)->execute(new DisablePluginInput('tests/keyfile', null));
        expect(cacheEntry('/keyfile-literal.txt'))->toBeNull();
    } finally {
        cacheOff($folder);
    }
});

it('passes another .txt address, and a path no file could have, to the "not found" page', function (): void {
    rootFilesEnable($this->manifest);
    rootFilesSaveKey('keyfile', 'abcd1234ef', $this->admin);

    $this->get('/zzzz9999yy.txt')->assertNotFound();
    $this->get('/short.txt')->assertNotFound();
    $this->get('/abcd1234ef.txt/more')->assertNotFound();
});

it('still redirects a root-file-shaped address that no plugin answers', function (): void {
    rootFilesEnable($this->manifest);
    app(CreateRedirect::class)->execute(new CreateRedirectInput('/moved12345.txt', 301, '/elsewhere/', actorId: (string) $this->admin->id));

    $this->get('/moved12345.txt')->assertStatus(301);
});

it('answers nothing for a plugin not granted routes.public, a disabled one, or on a safe page', function (string $how): void {
    rootFilesEnable($this->manifest);
    rootFilesSaveKey('keyfile', 'abcd1234ef', $this->admin);
    $this->get('/abcd1234ef.txt')->assertOk();

    match ($how) {
        'not granted' => PluginRecord::query()->where('name', 'tests/keyfile')->update(['consent' => json_encode(['capabilities' => [], 'hosts' => [], 'hooks' => [], 'routes' => Consent::routeLines(new Manifest($this->manifest))])]),
        'disabled' => app(DisablePlugin::class)->execute(new DisablePluginInput('tests/keyfile', null)),
        'safe mode' => null,
    };
    Cache::memo()->forget('teme.plugins.enabled');

    if ($how === 'safe mode') {
        PluginFixtures::serving('admin/plugins', fn () => expect(app(RootFiles::class)->find('abcd1234ef.txt'))->toBeNull());

        return;
    }

    $this->get('/abcd1234ef.txt')->assertNotFound();
})->with(['not granted', 'disabled', 'safe mode']);

it('refuses root files the manifest declares wrongly', function (array $rootFiles, ?array $capabilities, string $error): void {
    $manifest = [...$this->manifest, 'rootFiles' => $rootFiles, ...($capabilities === null ? [] : ['capabilities' => $capabilities])];

    expect(app(ManifestValidator::class)->errors($manifest, PluginFixtures::path('keyfile'), null))->toContain($error);
})->with([
    'without routes.public' => [[['id' => 'a', 'name' => 'abcd1234.txt', 'body' => 'x']], ['jobs'], 'It declares root files but doesn’t ask for routes.public.'],
    'three files' => [[['id' => 'a', 'name' => 'aaaa1234.txt', 'body' => 'x'], ['id' => 'b', 'name' => 'bbbb1234.txt', 'body' => 'x'], ['id' => 'c', 'name' => 'cccc1234.txt', 'body' => 'x']], null, 'It declares 3 root files; a plugin may declare at most 2.'],
    'a short name' => [[['id' => 'a', 'name' => 'abc.txt', 'body' => 'x']], null, 'rootFiles "a" name must be 8 to 128 letters, digits and hyphens, then .txt, or {setting}.txt.'],
    'not .txt' => [[['id' => 'a', 'name' => 'abcd1234.html', 'body' => 'x']], null, 'rootFiles "a" name must be 8 to 128 letters, digits and hyphens, then .txt, or {setting}.txt.'],
    'a name from a secret' => [[['id' => 'a', 'name' => '{api_token}.txt', 'body' => 'x']], null, 'rootFiles "a" takes its name from api_token, which isn’t one of its text settings.'],
    'a body from an unknown setting' => [[['id' => 'a', 'name' => 'abcd1234.txt', 'body' => '{nothing}']], null, 'rootFiles "a" takes its body from nothing, which isn’t one of its text settings.'],
    'a body over 1 KB' => [[['id' => 'a', 'name' => 'abcd1234.txt', 'body' => str_repeat('x', 1025)]], null, 'rootFiles "a" body must be 1 to 1024 bytes, or {setting}.'],
    'one name twice' => [[['id' => 'a', 'name' => 'abcd1234.txt', 'body' => 'x'], ['id' => 'b', 'name' => 'abcd1234.txt', 'body' => 'y']], null, 'rootFiles name abcd1234.txt is listed more than once.'],
    'not a list' => [['id' => 'a'], null, 'rootFiles must be a list of root file objects.'],
]);

it('refuses to enable a plugin whose literal name another enabled plugin answers, and writes nothing', function (): void {
    rootFilesEnable($this->manifest);

    expect(fn () => rootFilesEnable($this->otherManifest))
        ->toThrow(ValidationException::class, 'The root file /keyfile-literal.txt belongs to tests/keyfile.');

    expect(PluginRecord::query()->where('name', 'tests/keyfile-two')->where('state', PluginRecord::ENABLED)->exists())->toBeFalse();
});

it('refuses a saved value whose name another plugin answers now, and writes nothing', function (): void {
    rootFilesEnable($this->manifest);
    rootFilesSaveKey('keyfile', 'abcd1234ef', $this->admin);

    $other = [...$this->otherManifest, 'rootFiles' => [['id' => 'key', 'name' => '{site_key}.txt', 'body' => '{site_key}']]];
    file_put_contents($this->other.'/teme-plugin.json', json_encode($other, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    PluginFixtures::use('keyfile', $this->other);
    rootFilesEnable($other);
    $before = DB::table('settings')->where('group_key', 'plugin.keyfile-two')->first();

    expect(fn () => rootFilesSaveKey('keyfile-two', 'abcd1234ef', $this->admin))
        ->toThrow(ValidationException::class, 'Another plugin already answers /abcd1234ef.txt.');

    expect(DB::table('settings')->where('group_key', 'plugin.keyfile-two')->first())->toEqual($before)
        ->and(app(SettingsRepository::class)->get('plugin.keyfile-two', 'site_key'))->toBe('');

    rootFilesSaveKey('keyfile-two', 'zyxw9876vu', $this->admin);
    expect($this->get('/zyxw9876vu.txt')->assertOk()->getContent())->toBe('zyxw9876vu');
});

it('lets the plugin enabled first answer when two still meet', function (): void {
    rootFilesEnable($this->manifest);
    rootFilesSaveKey('keyfile', 'abcd1234ef', $this->admin);

    $other = [...$this->otherManifest, 'rootFiles' => [['id' => 'key', 'name' => '{site_key}.txt', 'body' => 'from the second']]];
    file_put_contents($this->other.'/teme-plugin.json', json_encode($other, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    PluginFixtures::use('keyfile', $this->other);
    rootFilesEnable($other);
    // Written straight to the table, as a value saved before the first plugin took the name would stand.
    cmSettings(['site_key' => 'abcd1234ef'], 'plugin.keyfile-two');

    expect($this->get('/abcd1234ef.txt')->assertOk()->getContent())->toBe('abcd1234ef');
});

it('lists each root file in the consent, and asks again for an update that adds one', function (): void {
    $manifest = new Manifest($this->manifest);

    expect(Consent::routeLines($manifest))->toBe([
        'GET /{site_key}.txt (named by its setting “Site key”)',
        'GET /keyfile-literal.txt',
    ]);

    rootFilesEnable($this->manifest);
    $consent = PluginRecord::query()->where('name', 'tests/keyfile')->sole()->consent;

    expect(Consent::covers($consent, $manifest))->toBeTrue();

    $updated = new Manifest([...$this->manifest, 'rootFiles' => [...$this->manifest['rootFiles'], ['id' => 'more', 'name' => 'another-file.txt', 'body' => 'x']]]);

    expect(Consent::covers($consent, $updated))->toBeFalse()
        ->and(Consent::added($consent, $updated)['routes'])->toBe(['GET /another-file.txt']);
});

it('says what root files a plugin answers when it is enabled from the console', function (): void {
    $this->artisan('teme:plugins:enable', ['name' => 'tests/keyfile'])
        ->expectsOutputToContain('GET /{site_key}.txt (named by its setting “Site key”)')
        ->expectsOutputToContain('GET /keyfile-literal.txt')
        ->assertFailed();
});

it('registers the root-file check and the cache tag with the plugin’s settings as it starts', function (): void {
    $manifest = new Manifest($this->manifest);

    expect(app(Contributions::class)->add('tests/keyfile', 'keyfile', 'Keyfile', '1.0.0', $manifest->contributes(), new class implements Plugin
    {
        public function register(Hooks $hooks): void {}
    }, rootFiles: true))->toBe([]);

    $checks = array_filter(iterator_to_array(app()->tagged(SettingsCheck::TAG)), fn (object $check): bool => $check instanceof RootFileNames);

    expect(app(SettingsRegistry::class)->find('plugin.keyfile')?->cacheTags)->toContain(PageTags::ROOT_FILES)
        ->and(array_map(fn (RootFileNames $check): string => $check->group(), array_values($checks)))->toBe(['plugin.keyfile']);
});
