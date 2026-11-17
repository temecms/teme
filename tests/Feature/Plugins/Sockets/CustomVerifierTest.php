<?php

declare(strict_types=1);

use App\Domain\Plugins\ManifestValidator;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/*
 * A custom verifier needs no secret (docs/plans/plugin-sockets-2.md, C1):
 * without one, its class is given '' and the secrets store isn't read.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance('inbound.hits', ['callback' => 0]);
    app()->instance('inbound.secret-given', null);

    $this->check = "<?php\n\nnamespace Tests\\Fixtures\\Plugins\\Inbound;\n\nuse Teme\\Contracts\\Routes\\InboundVerifier;\nuse Teme\\Contracts\\Routes\\PublicRequest;\n\nfinal class OpenCheck implements InboundVerifier\n{\n    public function verify(PublicRequest \$request, string \$secret): bool\n    {\n        app()->instance('inbound.secret-given', \$secret);\n\n        return (\$request->headers['x-open'] ?? '') === 'let-me-in';\n    }\n}\n";
    $this->route = fn (array $verify): array => [
        'id' => 'open',
        'method' => 'POST',
        'path' => '/{code}/open',
        'handler' => 'Tests\\Fixtures\\Plugins\\Inbound\\Callback',
        'verify' => $verify,
    ];
});

afterEach(fn () => PluginFixtures::forget());

it('gives a custom verifier without a secret an empty one, and answers with the handler', function (): void {
    PluginFixtures::use(PluginFixtures::copy('inbound', [
        'routes' => [($this->route)(['kind' => 'custom', 'class' => 'Tests\\Fixtures\\Plugins\\Inbound\\OpenCheck'])],
        'contributes' => (object) [],
    ], files: ['src/OpenCheck.php' => $this->check]));

    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/inbound', '1.0.0', ['routes.public'], [], [], null));
    app(PublicRouteRegistry::class)->flush();

    $this->post('/inbound/open', [], ['X-Open' => 'let-me-in'])
        ->assertOk()
        ->assertSee('ok', false);

    expect(app('inbound.hits')['callback'])->toBe(1)
        ->and(app('inbound.secret-given'))->toBe('');
});

it('answers a failed custom verification with 401, and the handler never runs', function (): void {
    PluginFixtures::use(PluginFixtures::copy('inbound', [
        'routes' => [($this->route)(['kind' => 'custom', 'class' => 'Tests\\Fixtures\\Plugins\\Inbound\\OpenCheck'])],
        'contributes' => (object) [],
    ], files: ['src/OpenCheck.php' => $this->check]));

    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/inbound', '1.0.0', ['routes.public'], [], [], null));
    app(PublicRouteRegistry::class)->flush();

    $this->post('/inbound/open', [], ['X-Open' => 'wrong'])->assertUnauthorized();

    expect(app('inbound.hits')['callback'])->toBe(0);
});

it('refuses a custom route that names a secret the plugin doesn’t declare', function (): void {
    $manifest = [
        ...PluginFixtures::manifest('inbound'),
        'routes' => [($this->route)(['kind' => 'custom', 'class' => 'Tests\\Fixtures\\Plugins\\Inbound\\CustomCheck', 'secret' => 'missing_token'])],
    ];
    config(['teme.version' => PluginFixtures::TEME]);

    expect(app(ManifestValidator::class)->errors($manifest, PluginFixtures::path('inbound'), PluginFixtures::composer('inbound')))
        ->toContain('verify.secret "missing_token" isn’t a declared secret setting.');

    $route = ($this->route)(['kind' => 'custom', 'class' => 'Tests\\Fixtures\\Plugins\\Inbound\\CustomCheck', 'secret' => 'missing_token']);
    PluginFixtures::use(PluginFixtures::copy('inbound', ['routes' => [$route]]));

    expect(fn () => app(EnablePlugin::class)->execute(new EnablePluginInput('tests/inbound', '1.0.0', ['routes.public'], [], [], null)))
        ->toThrow(ValidationException::class, 'verify.secret "missing_token" isn’t a declared secret setting.')
        ->and(PluginRecord::query()->count())->toBe(0);
});
