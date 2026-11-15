<?php

declare(strict_types=1);

use App\Domain\Plugins\ManifestValidator;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\PublicRoutes\DeclaredRoute;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

afterEach(fn () => PluginFixtures::forget());

beforeEach(function (): void {
    config(['teme.version' => PluginFixtures::TEME]);
    $this->errors = function (array $routes, array $extra = []): array {
        $manifest = [
            ...PluginFixtures::manifest('hello'),
            'capabilities' => ['content.read', 'routes.public'],
            'routes' => $routes,
            ...$extra,
        ];

        return app(ManifestValidator::class)->errors($manifest, PluginFixtures::path('hello'), PluginFixtures::composer('hello'));
    };
});

it('accepts a valid routes list under the plugin’s code', function (): void {
    expect(($this->errors)([
        [
            'id' => 'ping',
            'method' => 'GET',
            'path' => '/{code}/ping',
            'handler' => 'Tests\\Fixtures\\Plugins\\Hello\\Hello',
        ],
    ]))->toBe([]);
});

it('refuses each D3 path and collision with its sentence', function (array $routes, string $sentence, array $extra = []): void {
    expect(($this->errors)($routes, $extra))->toContain($sentence);
})->with([
    'reserved prefix' => [
        [['id' => 'x', 'method' => 'GET', 'path' => '/admin/x', 'handler' => 'Tests\\Fixtures\\Plugins\\Hello\\Hello']],
        'The path /admin/x is reserved.',
    ],
    'unconditional outside the plugin and well-known' => [
        [['id' => 'x', 'method' => 'GET', 'path' => '/elsewhere', 'handler' => 'Tests\\Fixtures\\Plugins\\Hello\\Hello']],
        'An unconditional path must be under /{code}/ or /.well-known/.',
    ],
    'core well-known name' => [
        [['id' => 'x', 'method' => 'GET', 'path' => '/.well-known/webfinger', 'handler' => 'Tests\\Fixtures\\Plugins\\Hello\\Hello']],
        'The .well-known name webfinger belongs to core.',
    ],
    'core public name' => [
        [['id' => 'x', 'method' => 'GET', 'path' => '/robots.txt', 'handler' => 'Tests\\Fixtures\\Plugins\\Hello\\Hello']],
        'The path /robots.txt is a core public address.',
    ],
    'POST without verify' => [
        [['id' => 'cb', 'method' => 'POST', 'path' => '/{code}/callback', 'handler' => 'Tests\\Fixtures\\Plugins\\Hello\\Hello']],
        'A POST route needs a verify block.',
    ],
    'verify.secret naming no declared secret' => [
        [[
            'id' => 'cb',
            'method' => 'POST',
            'path' => '/{code}/callback',
            'handler' => 'Tests\\Fixtures\\Plugins\\Hello\\Hello',
            'verify' => ['kind' => 'secret', 'from' => 'header:X-Token', 'secret' => 'missing_token'],
        ]],
        'verify.secret "missing_token" isn’t a declared secret setting.',
        ['contributes' => ['settings' => [['key' => 'other', 'type' => 'secret', 'label' => 'Other', 'description' => 'Another secret.']]]],
    ],
    'two entries that can match one request' => [
        [
            [
                'id' => 'a',
                'method' => 'GET',
                'path' => '/{code}/ping',
                'handler' => 'Tests\\Fixtures\\Plugins\\Hello\\Hello',
            ],
            [
                'id' => 'b',
                'method' => 'GET',
                'path' => '/{code}/ping',
                'handler' => 'Tests\\Fixtures\\Plugins\\Hello\\Hello',
            ],
        ],
        'This route collides with hello’s a.',
    ],
    'a cached Accept condition without varyAccept' => [
        [[
            'id' => 'actor',
            'method' => 'GET',
            'path' => '/',
            'when' => ['accept' => ['application/activity+json']],
            'handler' => 'Tests\\Fixtures\\Plugins\\Hello\\Hello',
            'cache' => ['query' => [], 'lifetime' => 60],
        ]],
        'A cached route that matches on Accept needs cache.varyAccept.',
    ],
    'a cached query condition cache.query leaves out' => [
        [[
            'id' => 'actor',
            'method' => 'GET',
            'path' => '/',
            'when' => ['query' => ['author' => '^[0-9]+$', 'page' => '^[0-9]+$']],
            'handler' => 'Tests\\Fixtures\\Plugins\\Hello\\Hello',
            'cache' => ['query' => ['author'], 'lifetime' => 60],
        ]],
        'cache.query must name every when.query parameter; it leaves out page.',
    ],
]);

it('accepts routes.public as a capability', function (): void {
    $errors = app(ManifestValidator::class)->errors(
        [...PluginFixtures::manifest('hello'), 'capabilities' => ['content.read', 'routes.public']],
        PluginFixtures::path('hello'),
        PluginFixtures::composer('hello'),
    );

    expect($errors)->not->toContain('Teme doesn’t offer routes.public to plugins yet.')
        ->and($errors)->toBe([]);
});

it('refuses routes without the routes.public capability', function (): void {
    $errors = app(ManifestValidator::class)->errors(
        [
            ...PluginFixtures::manifest('hello'),
            'routes' => [[
                'id' => 'ping',
                'method' => 'GET',
                'path' => '/{code}/ping',
                'handler' => 'Tests\\Fixtures\\Plugins\\Hello\\Hello',
            ]],
        ],
        PluginFixtures::path('hello'),
        PluginFixtures::composer('hello'),
    );

    expect($errors)->toContain('It declares routes but doesn’t ask for routes.public.');
});

describe('across enabled plugins and core', function (): void {
    beforeEach(function (): void {
        $this->seed(RoleSeeder::class);
        app()->instance(Elevation::class, new OperatorElevation);
        app(PublicRouteRegistry::class)->flush();

        $this->rival = function (string $code, array $routes): string {
            return PluginFixtures::copy('inbound', [
                'name' => 'tests/'.$code,
                'code' => $code,
                'label' => ucfirst($code),
                'routes' => $routes,
            ], ['name' => 'tests/'.$code]);
        };

        $this->enableNamed = function (string $name, string $folder): void {
            $manifest = json_decode((string) file_get_contents($folder.'/teme-plugin.json'), true, flags: JSON_THROW_ON_ERROR);

            app(EnablePlugin::class)->execute(new EnablePluginInput(
                name: $name,
                version: $manifest['version'],
                capabilities: $manifest['capabilities'],
                hosts: [],
                hooks: [],
                actorId: null,
            ));
            app(PublicRouteRegistry::class)->flush();
        };

        $this->refusal = function (Closure $enable): string {
            try {
                $enable();
            } catch (ValidationException $refused) {
                return (string) collect($refused->errors())->flatten()->first();
            }

            return '';
        };
    });

    it('refuses a plugin whose conditional entry overlaps another enabled plugin’s, and writes nothing', function (): void {
        $rival = ($this->rival)('rival', [[
            'id' => 'person',
            'method' => 'GET',
            'path' => '/',
            'when' => ['query' => ['author' => '^[0-9]+$']],
            'handler' => 'Tests\\Fixtures\\Plugins\\Inbound\\Actor',
        ]]);
        PluginFixtures::use(PluginFixtures::path('inbound'), $rival);
        ($this->enableNamed)('tests/inbound', PluginFixtures::path('inbound'));

        expect(($this->refusal)(fn () => ($this->enableNamed)('tests/rival', $rival)))->toBe('This route collides with inbound’s actor.')
            ->and(PluginRecord::query()->where('name', 'tests/rival')->exists())->toBeFalse();
    });

    it('allows a conditional entry on the same path whose conditions cannot overlap', function (): void {
        $rival = ($this->rival)('rival', [[
            'id' => 'calendar',
            'method' => 'GET',
            'path' => '/',
            'when' => ['accept' => ['text/calendar']],
            'handler' => 'Tests\\Fixtures\\Plugins\\Inbound\\Actor',
        ], [
            'id' => 'poster',
            'method' => 'POST',
            'path' => '/',
            'when' => ['query' => ['author' => '^[0-9]+$']],
            'handler' => 'Tests\\Fixtures\\Plugins\\Inbound\\Callback',
            'verify' => ['kind' => 'secret', 'from' => 'header:X-Plugin-Token', 'secret' => 'connection_token'],
        ]]);
        PluginFixtures::use(PluginFixtures::path('inbound'), $rival);
        ($this->enableNamed)('tests/inbound', PluginFixtures::path('inbound'));
        ($this->enableNamed)('tests/rival', $rival);

        expect(PluginRecord::query()->where('name', 'tests/rival')->value('state'))->toBe(PluginRecord::ENABLED);
    });

    it('refuses a .well-known name another enabled plugin holds', function (): void {
        $route = [
            'id' => 'shared',
            'method' => 'GET',
            'path' => '/.well-known/shared-name',
            'handler' => 'Tests\\Fixtures\\Plugins\\Inbound\\Ping',
        ];
        $first = ($this->rival)('first', [$route]);
        $second = ($this->rival)('second', [[...$route, 'id' => 'other', 'method' => 'POST', 'verify' => ['kind' => 'secret', 'from' => 'header:X-Plugin-Token', 'secret' => 'connection_token']]]);
        PluginFixtures::use($first, $second);
        ($this->enableNamed)('tests/first', $first);

        expect(($this->refusal)(fn () => ($this->enableNamed)('tests/second', $second)))->toBe('The .well-known name shared-name belongs to first.')
            ->and(PluginRecord::query()->where('name', 'tests/second')->exists())->toBeFalse();
    });

    it('refuses any overlap with a core entry, and writes nothing', function (): void {
        PluginFixtures::use('inbound');
        app(PublicRouteRegistry::class)->registerCore(new DeclaredRoute(
            owner: DeclaredRoute::OWNER_CORE,
            id: 'actor',
            method: 'GET',
            path: '/',
            handler: 'App\\Missing',
            when: ['accept' => ['application/activity+json']],
        ));

        expect(($this->refusal)(fn () => ($this->enableNamed)('tests/inbound', PluginFixtures::path('inbound'))))->toBe('This route collides with core’s actor.')
            ->and(PluginRecord::query()->where('name', 'tests/inbound')->exists())->toBeFalse();
    });
});
