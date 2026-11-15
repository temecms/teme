<?php

declare(strict_types=1);

use App\Domain\Plugins\ManifestValidator;
use Tests\Fixtures\Plugins\PluginFixtures;

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
