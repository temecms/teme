<?php

declare(strict_types=1);

use Tests\Fixtures\Deployment\ImageFiles;
use Tests\Fixtures\Guides\OperatorGuide;

/*
 * The proxy overlay for a server with no panel, and the platform pages that
 * say how to run the recipe's roles where a panel starts the containers.
 */

it('puts Traefik in front of web from a pinned image, on ports 80 and 443 only', function (): void {
    $overlay = OperatorGuide::overlay();
    $traefik = $overlay['services']['traefik'];

    expect($traefik['image'])->toMatch('/^traefik:v\d+\.\d+\.\d+@sha256:[0-9a-f]{64}$/')
        ->and($traefik['ports'])->toBe(['80:80', '443:443'])
        ->and($traefik['restart'])->toBe('unless-stopped')
        ->and($overlay['services']['web']['labels']['traefik.http.services.teme.loadbalancer.server.port'])->toBe('8080')
        ->and(array_keys($overlay['services']))->toEqualCanonicalizing(['traefik', 'web']);
});

it('lets Traefik read the Docker socket and nothing else of the host, and only to read', function (): void {
    $volumes = OperatorGuide::overlay()['services']['traefik']['volumes'];
    $host = array_values(array_filter($volumes, fn (string $volume): bool => str_starts_with($volume, '/')));

    expect($host)->toBe(['/var/run/docker.sock:/var/run/docker.sock:ro']);
});

it('keeps the proxy off the web role\'s own port: nothing but Traefik publishes a port', function (): void {
    $published = array_filter(OperatorGuide::recipe()['services'], fn (array $service): bool => isset($service['ports']));

    expect($published)->toBe([])
        ->and(array_keys(array_filter(OperatorGuide::overlay()['services'], fn (array $service): bool => isset($service['ports']))))->toBe(['traefik']);
});

it('names the overlay\'s two variables, and how to switch it on', function (): void {
    $page = OperatorGuide::page('platforms/plain-vps.md');

    expect(OperatorGuide::variables($page))->toContain('TEME_HOST', 'TEME_ACME_EMAIL', 'COMPOSE_FILE')
        ->and($page->text())->toContain('compose.proxy.yml');
});

it('gives Dokploy a health check that asks web\'s own address, with the program the image has', function (): void {
    $page = OperatorGuide::page('platforms/dokploy.md')->text();

    expect(ImageFiles::runtimeEnvironment()['HEALTHCHECK_PATH'])->toBe('/up')
        ->and($page)->toContain('http://localhost:8080/up')
        ->and($page)->toContain('curl')
        ->and(implode("\n", ImageFiles::stage('php')))->toContain('curl');
});

it('gives each platform the commands the recipe runs for the roles, and the time each is given to stop', function (string $name): void {
    $page = OperatorGuide::page($name)->text();
    $services = OperatorGuide::recipe()['services'];

    foreach (['deploy', 'worker', 'worker-long', 'scheduler'] as $role) {
        expect($page)->toContain(implode(' ', $services[$role]['command']));
    }

    foreach (['worker', 'worker-long', 'scheduler'] as $role) {
        expect($page)->toContain((ImageFiles::seconds($services[$role]['stop_grace_period']) / 60).' minutes');
    }
})->with(['platforms/dokploy.md', 'platforms/coolify.md']);

it('says which version of the platform it was written for, in its text as well as its front matter', function (string $name): void {
    $front = OperatorGuide::page($name)->frontMatter();

    expect($front['written_for'])->toMatch('/\d+\.\d+\.\d+/')
        ->and(OperatorGuide::page($name)->text())->toContain((string) $front['written_for']);
})->with(['platforms/dokploy.md', 'platforms/coolify.md']);
