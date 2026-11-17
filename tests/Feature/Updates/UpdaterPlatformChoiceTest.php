<?php

declare(strict_types=1);

use App\Domain\Updates\Updater\DokployApplicationPlatform;
use App\Domain\Updates\Updater\DokployPlatform;
use App\Domain\Updates\Updater\NoPlatform;
use App\Domain\Updates\Updater\Platform;

it('moves Teme through the platform TEME_UPDATER_PLATFORM names', function (string $name, string $class): void {
    config(['teme.updater.platform' => $name, 'teme.role' => null, 'teme.updater.dokploy.app_name' => null]);

    expect(app(Platform::class))->toBeInstanceOf($class);
})->with([
    'a Compose service on Dokploy' => ['dokploy', DokployPlatform::class],
    'one Application on Dokploy' => ['dokploy-application', DokployApplicationPlatform::class],
    'a name it doesn’t know' => ['elsewhere', NoPlatform::class],
]);

it('moves a container that runs every role through Dokploy’s Application once it is given a Dokploy App Name', function (): void {
    config(['teme.updater.platform' => null, 'teme.role' => 'all', 'teme.updater.dokploy.app_name' => 'app-teme-x1y2z3']);

    expect(app(Platform::class))->toBeInstanceOf(DokployApplicationPlatform::class);
});

it('has no platform in a container that runs every role until it is given a Dokploy App Name', function (?string $appName): void {
    config(['teme.updater.platform' => null, 'teme.role' => 'all', 'teme.updater.dokploy.app_name' => $appName]);

    expect(app(Platform::class))->toBeInstanceOf(NoPlatform::class);
})->with([null, '']);

it('has no platform for a container that runs one role, whatever else is set', function (): void {
    config(['teme.updater.platform' => null, 'teme.role' => null, 'teme.updater.dokploy.app_name' => 'app-teme-x1y2z3']);

    expect(app(Platform::class))->toBeInstanceOf(NoPlatform::class);
});
