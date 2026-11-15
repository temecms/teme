<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->folder = cacheOn();

    PluginFixtures::use('inbound');
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance('inbound.hits', ['ping' => 0, 'actor' => 0, 'callback' => 0]);
    app(PublicRouteRegistry::class)->flush();

    $settings = PluginFixtures::manifest('inbound')['contributes']['settings'];
    app(SettingsRegistry::class)->group(
        DeclaredSettings::group('inbound', 'Inbound', 'Inbound secrets.', '1.0.0', $settings),
        'tests/inbound',
    );
    app(SettingsWriter::class)->write(new SettingsWrite('plugin.inbound', [], secrets: ['connection_token' => 'shared-secret']));

    app(EnablePlugin::class)->execute(new EnablePluginInput(
        name: 'tests/inbound',
        version: '1.0.0',
        capabilities: ['routes.public'],
        hosts: [],
        hooks: [],
        actorId: null,
    ));
    app(PublicRouteRegistry::class)->flush();
});

afterEach(function (): void {
    cacheOff($this->folder);
    PluginFixtures::forget();
});

it('does not serve a stored home page for /?author=0 with the activity Accept', function (): void {
    addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    $request = Request::create('/?author=0', 'GET', server: ['HTTP_ACCEPT' => 'application/activity+json']);
    $activity = TestResponse::fromBaseResponse(app(Kernel::class)->handle($request), $request)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/activity+json')
        ->assertJson(['type' => 'Person', 'id' => '0']);

    expect($activity->headers->get('X-Teme-Cache'))->not->toBe('HIT');
});

it('stores a cacheable declared answer under its own key and serves it without running the handler again', function (): void {
    $first = addrGet('/inbound/ping')->assertOk()->assertSee('pong', false);
    expect($first->headers->get('X-Teme-Cache'))->toBe('MISS')
        ->and(app('inbound.hits')['ping'])->toBe(1);

    $second = addrGet('/inbound/ping')->assertOk()->assertSee('pong', false);
    expect($second->headers->get('X-Teme-Cache'))->toBe('HIT')
        ->and(app('inbound.hits')['ping'])->toBe(1);
});

it('still draws the home page for /?author=0 in a browser', function (): void {
    $request = Request::create('/?author=0', 'GET', server: ['HTTP_ACCEPT' => 'text/html']);
    TestResponse::fromBaseResponse(app(Kernel::class)->handle($request), $request)
        ->assertOk();

    expect(app('inbound.hits')['actor'])->toBe(0);
});

it('purges plugin pages when the plugin is disabled', function (): void {
    addrGet('/inbound/ping')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/inbound/ping')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/inbound', null));
    app(PublicRouteRegistry::class)->flush();

    addrGet('/inbound/ping')->assertNotFound();
});

it('never stores a POST', function (): void {
    $response = $this->post('/inbound/callback', [], ['X-Plugin-Token' => 'shared-secret'])
        ->assertOk();

    $header = (string) $response->headers->get('X-Teme-Cache');

    expect($header === '' || str_starts_with($header, 'BYPASS'))->toBeTrue();
});
