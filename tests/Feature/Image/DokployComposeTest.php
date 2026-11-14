<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\Fixtures\Deployment\ImageFiles;

/*
 * The Dokploy Compose file, read as data (I-3333): the suite starts no container.
 */

/** @return array<string, mixed> */
function dokployCompose(): array
{
    return Yaml::parse(ImageFiles::text('docker/compose.dokploy.yml'));
}

it('names the Dokploy token, address and compose ID in the updater alone', function (): void {
    $compose = dokployCompose();

    foreach ($compose['services'] as $name => $service) {
        $names = array_keys($service['environment'] ?? []);

        foreach (['TEME_DOKPLOY_TOKEN', 'TEME_DOKPLOY_URL', 'TEME_DOKPLOY_COMPOSE_ID'] as $variable) {
            expect(in_array($variable, $names, true))->toBe($name === 'updater', "{$name} and {$variable}");
        }
    }

    expect($compose['x-teme-environment'])->not->toHaveKeys(['TEME_DOKPLOY_TOKEN', 'TEME_DOKPLOY_URL', 'TEME_DOKPLOY_COMPOSE_ID'])
        ->and($compose['services']['updater']['environment'])->toMatchArray([
            'TEME_UPDATER_PLATFORM' => 'dokploy',
            'TEME_DOKPLOY_URL' => '${TEME_DOKPLOY_URL:-}',
            'TEME_DOKPLOY_TOKEN' => '${TEME_DOKPLOY_TOKEN:-}',
            'TEME_DOKPLOY_COMPOSE_ID' => '${TEME_DOKPLOY_COMPOSE_ID:-}',
            'TEME_GITHUB_TOKEN' => '${TEME_GITHUB_TOKEN}',
        ]);
});

it('reads the updater’s own image setting, never TEME_IMAGE', function (): void {
    $services = dokployCompose()['services'];

    expect($services['updater']['image'])->toStartWith('${TEME_UPDATER_IMAGE')
        ->and($services['updater']['command'])->toBe(['php', 'artisan', 'teme:updater']);

    foreach (['deploy', 'web', 'worker', 'worker-long', 'scheduler'] as $role) {
        expect($services[$role]['image'])->toBe('${TEME_IMAGE}');
    }
});

it('gives the updater a limit, no volumes, no domain network and no health check, and has it wait for the deploy', function (): void {
    $updater = dokployCompose()['services']['updater'];

    expect($updater['deploy']['resources']['limits'])->toBe(['cpus' => '0.25', 'memory' => '256m'])
        ->and($updater)->not->toHaveKey('volumes')
        ->and($updater['networks'])->toBe(['default'])
        ->and($updater['healthcheck'])->toBe(['disable' => true])
        ->and($updater['stop_grace_period'])->toBe('1m')
        ->and($updater['restart'])->toBe('unless-stopped')
        ->and($updater['depends_on'])->toBe(['deploy' => ['condition' => 'service_completed_successfully']]);
});

it('shares the image repository with every Teme part, and no tag is latest', function (): void {
    $compose = dokployCompose();

    expect($compose['x-teme-environment']['TEME_IMAGE_REPOSITORY'])->toBe('${TEME_IMAGE_REPOSITORY:-}')
        ->and(ImageFiles::text('docker/compose.dokploy.yml'))->not->toContain(':latest');
});
