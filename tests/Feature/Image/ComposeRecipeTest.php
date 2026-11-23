<?php

declare(strict_types=1);

use App\Domain\Deployment\SecretFiles;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use Illuminate\Support\Str;
use Tests\Fixtures\Deployment\ImageFiles;

/*
 * The Compose recipe, read as data: the suite starts no container. CI's
 * image workflow starts it.
 */

it('runs every Teme role from one image, named in .env with its exact tag', function (): void {
    $services = ImageFiles::compose()['services'];

    foreach (ImageFiles::TEME_ROLES as $role) {
        expect($services[$role]['image'])->toBe("\${TEME_IMAGE:?Set TEME_IMAGE in .env to Teme's image and its exact tag, such as ghcr.io/example/teme:0.1.0}")
            ->and($services[$role]['env_file'])->toBe([['path' => '.env', 'required' => false]]);
    }

    expect(ImageFiles::text('docker/compose.yml'))->not->toContain(':latest');
});

it('starts every role once deploy has succeeded and the installer has checked the extensions, and deploy once PostgreSQL is ready', function (): void {
    $services = ImageFiles::compose()['services'];

    expect($services['deploy']['depends_on'])->toBe(['postgres' => ['condition' => 'service_healthy']])
        ->and($services['deploy']['restart'])->toBe('no')
        ->and($services['installer']['depends_on'])->toBe(['deploy' => ['condition' => 'service_completed_successfully']]);

    foreach (['web', 'worker', 'worker-long', 'scheduler'] as $role) {
        expect($services[$role]['depends_on'])->toBe(['deploy' => ['condition' => 'service_completed_successfully'], 'installer' => ['condition' => 'service_healthy']]);
    }
});

it('mounts the media, the page cache and, read-only, the extensions in every role (I-533)', function (string $role): void {
    expect(ImageFiles::compose()['services'][$role]['volumes'])->toBe(['media:/var/www/html/storage/app', 'page-cache:/var/www/html/storage/page-cache', 'extensions:/var/teme/extensions:ro'])
        ->and(ImageFiles::compose()['services'][$role]['environment']['TEME_EXTENSIONS_PATH'])->toBe('/var/teme/extensions');
})->with(ImageFiles::TEME_ROLES);

it('runs the installer from the site’s own image, as its own user, alone with the extensions volume writable (I-211)', function (): void {
    $compose = ImageFiles::compose();
    $installer = $compose['services']['installer'];

    expect($installer['image'])->toBe($compose['services']['web']['image'])
        ->and($installer['user'])->toBe('9990:9990')
        ->and($installer['entrypoint'])->toBe(['php', 'artisan', 'teme:installer'])
        ->and($installer['init'])->toBeTrue()
        ->and($installer['environment']['TEME_EXTENSIONS_ROLE'])->toBe('installer')
        ->and($installer['environment']['TEME_EXTENSIONS_PATH'])->toBe('/var/teme/extensions')
        ->and($installer['volumes'])->toBe(['extensions:/var/teme/extensions'])
        ->and($installer['healthcheck']['test'])->toBe(['CMD', 'test', '-f', '/tmp/teme-installer-ready'])
        ->and($compose['volumes'])->toHaveKey('extensions');

    foreach ($compose['services'] as $name => $service) {
        if ($name !== 'installer') {
            expect(array_filter($service['volumes'] ?? [], fn (string $volume): bool => str_starts_with($volume, 'extensions:') && ! str_ends_with($volume, ':ro')))->toBe([]);
        }
    }
});

it('publishes no port', function (): void {
    foreach (ImageFiles::compose()['services'] as $service) {
        expect($service)->not->toHaveKey('ports')
            ->and($service)->not->toHaveKey('expose');
    }
});

it('gives each role time to finish its longest work before it is stopped', function (): void {
    $services = ImageFiles::compose()['services'];
    $longestJob = (new ReflectionProperty(GenerateMediaVariants::class, 'timeout'))->getDefaultValue();

    expect(ImageFiles::seconds($services['worker']['stop_grace_period']))->toBeGreaterThanOrEqual($longestJob + 60)
        ->and(ImageFiles::seconds($services['worker-long']['stop_grace_period']))->toBeGreaterThanOrEqual($longestJob + 60)
        ->and(ImageFiles::seconds($services['scheduler']['stop_grace_period']))->toBe(1800)
        ->and(ImageFiles::seconds($services['postgres']['stop_grace_period']))->toBe(60)
        ->and($services['worker']['stop_signal'])->toBe('SIGTERM')
        ->and($services['worker-long']['stop_signal'])->toBe('SIGTERM')
        ->and($services['scheduler']['stop_signal'])->toBe('SIGTERM');
});

it('runs each role\'s command, with the health checks the image provides', function (): void {
    $services = ImageFiles::compose()['services'];

    expect($services['deploy']['command'])->toBe(['php', 'artisan', 'teme:deploy'])
        ->and($services['deploy']['healthcheck'])->toBe(['disable' => true])
        ->and($services['web'])->not->toHaveKey('command')
        ->and($services['worker']['command'])->toBe(['php', 'artisan', 'queue:work', '--tries=3', '--max-time=3600', '--memory=512'])
        ->and($services['worker']['healthcheck']['test'])->toBe(['CMD', 'healthcheck-queue'])
        ->and($services['scheduler']['command'])->toBe(['php', 'artisan', 'schedule:work'])
        ->and($services['scheduler']['healthcheck']['test'])->toBe(['CMD', 'healthcheck-schedule']);
});

it('gives every role but web Docker\'s init as its first process, and leaves web to S6', function (): void {
    $services = ImageFiles::compose()['services'];

    foreach (['deploy', 'worker', 'worker-long', 'scheduler'] as $role) {
        expect($services[$role]['init'])->toBeTrue();
    }

    expect($services['web'])->not->toHaveKey('init');
});

it('keeps worker-long off until the long queue has a connection of its own (issue #146)', function (): void {
    $service = ImageFiles::compose()['services']['worker-long'];

    expect($service['command'])->toBe(['php', 'artisan', 'queue:work', 'database-long', '--queue=long', '--tries=3', '--max-time=3600', '--memory=512'])
        ->and($service['healthcheck']['test'])->toBe(['CMD', 'healthcheck-queue']);

    if (config('queue.connections.database-long') === null) {
        expect($service['profiles'])->toBe(['long-queue']);
    } else {
        // The long queue has its connection now: remove worker-long's profiles line, so it runs with the others.
        expect($service)->not->toHaveKey('profiles');
    }
});

it('pins PostgreSQL 18.6 by digest, with its data at /var/lib/postgresql and a health check over TCP', function (): void {
    $compose = ImageFiles::compose();
    $postgres = $compose['services']['postgres'];

    expect($postgres['image'])->toBe('postgres:18.6-trixie@sha256:74935e72241653ca55e0414067e6d8763aceb8a810eb51b452253ec3dcfc4336')
        ->and($postgres['volumes'])->toBe(['pgdata:/var/lib/postgresql'])
        ->and($postgres['healthcheck']['test'])->toBe(['CMD', 'pg_isready', '--host', '127.0.0.1', '--username', 'postgres', '--dbname', 'postgres'])
        ->and($postgres['configs'][0]['source'])->toBe('teme-database')
        ->and($postgres['configs'][0]['target'])->toBe('/docker-entrypoint-initdb.d/10-teme-role.sh')
        ->and($compose['configs']['teme-database']['file'])->toBe('./postgres/initdb.d/10-teme-role.sh');
});

it('keeps Valkey behind a profile, for the page cache alone (I-3110)', function (): void {
    $valkey = ImageFiles::compose()['services']['valkey'];

    expect($valkey['image'])->toBe('valkey/valkey:9.1.2-trixie@sha256:418652cfb58ef879d4978c33553735d7147016032d5aefaa14c828e611eb9dfd')
        ->and($valkey['profiles'])->toBe(['valkey'])
        ->and($valkey['command'])->toBe(['valkey-server', '--save', '', '--appendonly', 'no', '--maxmemory', '256mb', '--maxmemory-policy', 'volatile-lru'])
        ->and(ImageFiles::text('docker/compose.yml').ImageFiles::text('Dockerfile'))->not->toMatch('/(CACHE_STORE|SESSION_DRIVER|QUEUE_CONNECTION)\W+redis/i');
});

it('names only variables Teme or the image reads', function (): void {
    $known = ImageFiles::knownVariables();
    $compose = ImageFiles::compose();

    foreach (ImageFiles::TEME_ROLES as $role) {
        expect(array_diff(array_keys($compose['services'][$role]['environment']), $known, ImageFiles::ARRIVING_WITH_MAIN))->toBe([]);
    }

    expect(array_diff(array_keys(ImageFiles::runtimeEnvironment()), $known))->toBe([])
        // A name waiting for main leaves ARRIVING_WITH_MAIN the day config/ reads it.
        ->and(array_intersect(ImageFiles::ARRIVING_WITH_MAIN, $known))->toBe([]);
});

it('hands each secret to a role as a file it is given, never as a value', function (): void {
    $compose = ImageFiles::compose();

    foreach (ImageFiles::TEME_ROLES as $role) {
        $service = $compose['services'][$role];

        expect(array_intersect(array_keys($service['environment']), SecretFiles::NAMES))->toBe([]);

        foreach ($service['environment'] as $name => $value) {
            if (! str_ends_with((string) $name, '_FILE')) {
                continue;
            }

            $secret = Str::after((string) $value, '/run/secrets/');

            expect((string) $value)->toStartWith('/run/secrets/')
                ->and($service['secrets'])->toContain($secret)
                ->and($compose['secrets'][$secret]['file'])->toBe("./secrets/{$secret}");
        }
    }
});

it('never gives a Teme role the database\'s superuser', function (): void {
    $compose = ImageFiles::compose();

    foreach (ImageFiles::TEME_ROLES as $role) {
        $service = $compose['services'][$role];

        expect($service['secrets'])->not->toContain('postgres_password')
            ->and(array_filter(array_keys($service['environment']), fn (string $name): bool => str_starts_with($name, 'POSTGRES_')))->toBe([]);
    }

    expect($compose['services']['postgres']['environment']['POSTGRES_PASSWORD_FILE'])->toBe('/run/secrets/postgres_password')
        ->and($compose['services']['postgres']['secrets'])->toBe(['postgres_password', 'db_password']);
});

it('gives up on an unreachable database within 3 seconds, in every role', function (string $role): void {
    expect(ImageFiles::compose()['services'][$role]['environment']['PGCONNECT_TIMEOUT'] ?? null)->toBe('3');
})->with(ImageFiles::TEME_ROLES);

it('never takes settings from .env.example, which is for development', function (): void {
    $compose = ImageFiles::compose();
    $text = ImageFiles::text('docker/compose.yml');

    foreach (ImageFiles::TEME_ROLES as $role) {
        expect(array_column($compose['services'][$role]['env_file'], 'path'))->toBe(['.env']);
    }

    expect($text)->toContain('never copy .env.example, which is for development')
        ->and(substr_count($text, '.env.example'))->toBe(1);
});

it('leaves TRUSTED_PROXIES and TEME_HSTS to .env, so Teme\'s own defaults hold', function (): void {
    $environment = ImageFiles::compose()['services']['web']['environment'];

    expect($environment)->not->toHaveKey('TRUSTED_PROXIES')
        ->and($environment)->not->toHaveKey('TEME_HSTS')
        ->and($environment['PAGE_CACHE_DRIVER'])->toBe('${PAGE_CACHE_DRIVER:-static}')
        ->and($environment['PHP_FPM_PM_MAX_CHILDREN'])->toBe('${PHP_FPM_PM_MAX_CHILDREN:-10}');
});

it('gives the GitHub token to the scheduler alone, from a file that may be empty (I-?? draft)', function (): void {
    $compose = ImageFiles::compose();

    expect($compose['secrets']['github_token'])->toBe(['file' => './secrets/github_token'])
        ->and($compose['services']['scheduler']['secrets'])->toContain('github_token')
        ->and($compose['services']['scheduler']['environment']['TEME_GITHUB_TOKEN_FILE'])->toBe('/run/secrets/github_token');

    foreach (['deploy', 'web', 'worker', 'worker-long'] as $role) {
        expect($compose['services'][$role]['secrets'] ?? [])->not->toContain('github_token')
            ->and($compose['services'][$role]['environment'] ?? [])->not->toHaveKey('TEME_GITHUB_TOKEN_FILE');
    }

    expect(collect(ImageFiles::workflow()['jobs']['image']['steps'])->firstWhere('name', 'Start the recipe')['run'])
        ->toContain(': > secrets/github_token');
});
