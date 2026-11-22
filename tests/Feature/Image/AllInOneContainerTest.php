<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * The container that runs every role (TEME_ROLE=all), run for real: Teme's
 * start files exactly as the Dockerfile places them, on the same base image,
 * under a stand-in for the application that records what ran, as whom, and
 * with which environment (tests/Fixtures/Image). It needs Docker, so it runs
 * in CI, or where TEME_CONTAINER_TESTS=1 asks for it.
 */

const AIO_IMAGE = 'teme-all-in-one-test';

/** Whether this machine can run the container tests, and has been asked to. */
function aioAvailable(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    if (getenv('TEME_CONTAINER_TESTS') !== '1' && getenv('CI') === false) {
        return $available = false;
    }

    // A busy Docker Desktop takes a while to answer.
    $docker = new Process(['docker', 'version', '--format', '{{.Server.Os}}'], timeout: 180);
    $docker->run();

    return $available = $docker->isSuccessful() && trim($docker->getOutput()) === 'linux';
}

/**
 * Runs a docker command and returns it, finished.
 *
 * @param  list<string>  $arguments
 */
function aioDocker(array $arguments, int $timeout = 120): Process
{
    $process = new Process(['docker', ...$arguments], dirname(__DIR__, 3), null, null, $timeout);
    $process->run();

    return $process;
}

/**
 * Runs a docker command that must succeed, and returns it; a failure says what docker said.
 *
 * @param  list<string>  $arguments
 */
function aioMust(array $arguments, int $timeout = 120): Process
{
    $process = aioDocker($arguments, $timeout);

    if (! $process->isSuccessful()) {
        throw new RuntimeException('docker '.implode(' ', array_slice($arguments, 0, 2)).' failed: '.$process->getErrorOutput());
    }

    return $process;
}

/**
 * Starts the all-in-one container under a name of the test's own and returns the name.
 *
 * @param  array<string, string>  $environment
 * @param  list<string>  $options  further docker run options
 */
function aioStart(array $environment = [], array $options = []): string
{
    $name = 'teme-aio-'.bin2hex(random_bytes(4));
    $arguments = ['run', '--detach', '--name', $name];

    foreach (['TEME_ROLE' => 'all', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)), 'DB_HOST' => 'db.internal', 'DB_USERNAME' => 'given-user', 'DB_PASSWORD' => 'given-password-canary', ...$environment] as $key => $value) {
        // A setting given as null is left out.
        if ($value === null) {
            continue;
        }

        $arguments[] = '--env';
        $arguments[] = "{$key}={$value}";
    }

    aioMust([...$arguments, ...$options, AIO_IMAGE]);

    return $name;
}

/** Waits until the container reports the health status, or the container has stopped; returns the last status. */
function aioWait(string $name, string $status, int $seconds = 90): string
{
    $current = '';

    for ($waited = 0; $waited < $seconds; $waited++) {
        $current = trim(aioDocker(['inspect', '--format', '{{if .State.Running}}{{.State.Health.Status}}{{else}}stopped{{end}}', $name])->getOutput());

        if ($current === $status || $current === 'stopped') {
            return $current;
        }

        sleep(1);
    }

    return $current;
}

/** Runs a shell command in the container, as root, and returns its output. */
function aioExec(string $name, string $script, string $user = 'root'): string
{
    $process = aioDocker(['exec', '--user', $user, $name, 'sh', '-c', $script]);

    return $process->getOutput().$process->getErrorOutput();
}

/** Runs a shell command in the container as $user, with an environment of nothing but a path, and returns its output and status. */
function aioAs(string $name, string $user, string $script): string
{
    $process = aioDocker(['exec', $name, 'setpriv', "--reuid={$user}", "--regid={$user}", '--clear-groups', 'env', '-i', 'PATH=/usr/local/sbin:/usr/local/bin:/usr/bin:/bin', 'sh', '-c', "{$script} 2>&1"]);

    return $process->getOutput().$process->getErrorOutput();
}

function aioRemove(string $name): void
{
    aioDocker(['rm', '--force', $name], 60);
}

beforeAll(function (): void {
    if (! aioAvailable()) {
        return;
    }

    $image = (string) file_get_contents(dirname(__DIR__, 3).'/Dockerfile');
    preg_match('/^ARG SERVERSIDEUP_IMAGE=(\S+)$/m', $image, $base);

    aioMust(['build', '--quiet', '--file', 'tests/Fixtures/Image/Dockerfile', '--build-arg', "SERVERSIDEUP_IMAGE={$base[1]}", '--tag', AIO_IMAGE, '.'], 900);
});

beforeEach(function (): void {
    if (! aioAvailable()) {
        $this->markTestSkipped('The container tests need Docker, and run in CI or with TEME_CONTAINER_TESTS=1.');
    }

    $this->containers = [];
    $this->start = function (array $environment = [], array $options = []): string {
        return $this->containers[] = aioStart($environment, $options);
    };
});

afterEach(function (): void {
    foreach ($this->containers ?? [] as $name) {
        aioRemove($name);
    }
});

it('starts every process, each as its own user, and passes its one health check', function (): void {
    $name = ($this->start)();

    expect(aioWait($name, 'healthy'))->toBe('healthy');

    $processes = aioExec($name, 'ps -eo user,args');

    expect($processes)->toMatch('/^www-data\s+.*php artisan queue:work --tries=3 --max-time=3600 --memory=512$/m')
        ->and($processes)->toMatch('/^www-data\s+.*php artisan queue:work database-long --queue=long --tries=3 --max-time=3600 --memory=512$/m')
        ->and($processes)->toMatch('/^www-data\s+.*php artisan schedule:work$/m')
        ->and($processes)->toMatch('/^www-data\s+.*php artisan teme:updater$/m')
        ->and($processes)->toMatch('/^teme-in\S*\s+.*php artisan teme:installer$/m')
        ->and($processes)->toMatch('/^root\s+nginx: master process/m')
        ->and($processes)->toMatch('/^root\s+php-fpm: master process/m')
        ->and($processes)->toMatch('/^www-data\s+php-fpm: pool www/m')
        ->and(aioExec($name, 'curl --silent --show-error http://localhost:8080/up'))->toBe('ok');
});

it('runs the deploy step, then the installer’s check, before web answers', function (): void {
    $name = ($this->start)();
    aioWait($name, 'healthy');

    $lines = array_map('trim', explode("\n", trim(aioExec($name, 'cat /tmp/fake-artisan-*.log | sort -n'))));
    $ran = array_map(fn (string $line): string => explode(' ', $line, 2)[1], $lines);
    $order = array_map(fn (string $line): string => explode(' ', $line)[1], $ran);

    // The database step first, then the caches as the login it made, then the deploy; the installer's check follows it.
    expect(array_slice($order, 0, 2))->toBe(['config:cache', 'route:cache'])
        ->and(array_values(array_filter($order, fn (string $command): bool => in_array($command, ['teme:database:prepare', 'teme:deploy', 'teme:installer'], true))))
        ->toBe(['teme:database:prepare', 'teme:deploy', 'teme:installer', 'teme:installer'])
        ->and($ran)->toContain('www-data teme:deploy --no-interaction')
        ->and($ran)->toContain('teme-installer teme:installer --prepare-only');
});

it('stops every process when it is told to, and exits cleanly', function (): void {
    $name = ($this->start)();
    aioWait($name, 'healthy');
    $started = microtime(true);

    aioMust(['stop', '--time', '60', $name], 90);

    expect(microtime(true) - $started)->toBeLessThan(20.0)
        ->and(trim(aioDocker(['inspect', '--format', '{{.State.ExitCode}}', $name])->getOutput()))->toBe('0');
});

it('fails its health check when a worker, the scheduler or the installer is down', function (string $service): void {
    $name = ($this->start)();
    aioWait($name, 'healthy');

    aioExec($name, "/command/s6-svc -d /run/service/{$service}");

    expect(aioWait($name, 'unhealthy', 40))->toBe('unhealthy')
        ->and(aioExec($name, 'teme-healthcheck'))->toContain("{$service} is not running");
})->with(['teme-worker', 'teme-worker-long', 'teme-scheduler', 'teme-installer']);

it('gives the installer none of the site’s secrets, only what it needs', function (): void {
    $secrets = ['TEME_DOKPLOY_TOKEN' => 'dokploy-canary', 'MEDIA_BUCKET_SECRET_ACCESS_KEY' => 'bucket-canary', 'TEME_GITHUB_TOKEN' => 'github-canary', 'MAIL_PASSWORD' => 'mail-canary'];
    $name = ($this->start)($secrets);
    aioWait($name, 'healthy');

    $installer = json_decode(aioExec($name, 'cat /tmp/installer-env-teme-installer'), true, flags: JSON_THROW_ON_ERROR);

    expect($installer)->toBeArray()
        ->and(array_keys($installer))->not->toContain(...array_keys($secrets))
        ->and(json_encode($installer))->not->toContain('canary')
        // The login Teme runs as, never the one it was given.
        ->and($installer['DB_USERNAME'] ?? null)->toBe('teme_app')
        ->and($installer['DB_PASSWORD'] ?? null)->toBe('derived')
        ->and($installer)->toHaveKey('APP_KEY')
        ->and($installer['TEME_EXTENSIONS_ROLE'] ?? null)->toBe('installer')
        // The configuration cache holds every secret, so it isn't in what it reads.
        ->and($installer)->not->toHaveKey('APP_CONFIG_CACHE');
});

it('keeps the site’s files and processes out of the installer’s reach, and the extensions out of the site’s', function (): void {
    $name = ($this->start)();
    aioWait($name, 'healthy');
    $drop = fn (string $user, string $script): string => aioAs($name, $user, $script);

    // The installer can't read the configuration, which holds every secret, nor a site process's environment.
    expect($drop('teme-installer', 'cat /var/www/html/storage/framework/cache/config.php'))->toContain('Permission denied')
        ->and($drop('teme-installer', 'cat /proc/$(pgrep -f "queue:work --tries" | head -1)/environ'))->toContain('Permission denied')
        // The site can't write what the installer builds, or read its environment.
        ->and($drop('www-data', 'touch /var/teme/extensions/planted'))->toContain('Permission denied')
        ->and($drop('www-data', 'cat /proc/$(pgrep -f "teme:installer" | head -1)/environ'))->toContain('Permission denied');
});

it('keeps the database login it was given out of every process it supervises', function (): void {
    $name = ($this->start)();
    aioWait($name, 'healthy');

    $found = [];

    foreach (['www-data', 'teme-installer'] as $user) {
        $found[$user] = trim(aioAs($name, $user, 'grep -lsa given-password-canary /proc/[0-9]*/environ 2>/dev/null'));
    }

    expect($found)->toBe(['www-data' => '', 'teme-installer' => ''])
        ->and(aioExec($name, 'ls -l /run/teme'))->toMatch('/-rw-------\s+1 root root\s+\d+.* admin\.env/')
        ->and(aioExec($name, 'ls -l /run/teme'))->toMatch('/-rw-------\s+1 root root\s+\d+.* service\.env/');
});

it('caches the configuration for the site alone, after the login Teme runs as is known', function (): void {
    $name = ($this->start)();
    aioWait($name, 'healthy');

    expect(aioExec($name, 'stat -c "%U %a" /var/www/html/storage/framework/cache/config.php'))->toBe("www-data 600\n")
        ->and(aioExec($name, 'cat /var/www/html/storage/framework/cache/config.php'))->toContain("'db' => 'teme_app'");
});

it('lets nginx read what PHP wrote, since root runs its workers as nginx', function (): void {
    $name = ($this->start)();
    aioWait($name, 'healthy');

    // Readable by the site's group alone, as a file PHP writes under a strict umask is.
    aioExec($name, 'umask 027 && echo shown > /var/www/html/storage/app/public/a.txt', 'www-data');

    expect(aioExec($name, 'curl --silent http://localhost:8080/storage/a.txt'))->toBe("shown\n");
});

it('keeps media, the page cache and the installed plugins and themes in the one volume mounted at /var/teme', function (): void {
    $volume = 'teme-aio-volume-'.bin2hex(random_bytes(4));
    $name = ($this->start)([], ['--volume', "{$volume}:/var/teme"]);
    aioWait($name, 'healthy');

    $layout = aioExec($name, 'readlink /var/www/html/storage/app /var/www/html/storage/page-cache; stat -c "%n %U %a" /var/teme/media /var/teme/page-cache /var/teme/extensions; ls /var/teme/media');
    $logs = aioDocker(['logs', $name]);

    expect($layout)->toBe("/var/teme/media\n/var/teme/page-cache\n/var/teme/media www-data 755\n/var/teme/page-cache www-data 755\n/var/teme/extensions teme-installer 755\npublic\n")
        ->and($logs->getOutput().$logs->getErrorOutput())->not->toContain('Nothing is mounted at /var/teme')
        // The installer was told where its folder is without being asked.
        ->and(json_decode(aioExec($name, 'cat /tmp/installer-env-teme-installer'), true)['TEME_EXTENSIONS_PATH'] ?? null)->toBe('/var/teme/extensions');

    aioRemove($name);
    aioDocker(['volume', 'rm', '--force', $volume]);
});

it('says, and goes on, when no volume is mounted at /var/teme', function (): void {
    $name = ($this->start)();
    aioWait($name, 'healthy');
    $logs = aioDocker(['logs', $name]);

    expect($logs->getOutput().$logs->getErrorOutput())->toContain('Nothing is mounted at /var/teme, so the media, the page cache and the plugins and themes installed from the admin are lost whenever this container is replaced.');
});

it('listens on the port a host sets in PORT, and its health check asks there', function (): void {
    $name = ($this->start)(['PORT' => '3000']);

    expect(aioWait($name, 'healthy'))->toBe('healthy')
        ->and(aioExec($name, 'curl --silent http://localhost:3000/up'))->toBe('ok')
        ->and(aioExec($name, 'curl --silent --max-time 2 http://localhost:8080/up'))->toBe('');
});

it('leaves a mounted volume at storage/app or storage/page-cache as it is, and never empties it', function (): void {
    $media = 'teme-aio-media-'.bin2hex(random_bytes(4));
    $cache = 'teme-aio-cache-'.bin2hex(random_bytes(4));
    // The Compose recipe mounts both; a volume with a file in it is what a site switching layouts brings.
    aioMust(['run', '--rm', '--volume', "{$media}:/data", '--entrypoint', 'sh', AIO_IMAGE, '-c', 'mkdir -p /data/public && echo precious > /data/public/kept.txt && chown -R 33:33 /data']);
    aioMust(['run', '--rm', '--volume', "{$cache}:/data", '--entrypoint', 'sh', AIO_IMAGE, '-c', 'echo cached > /data/kept.txt && chown -R 33:33 /data']);

    $name = ($this->start)([], ['--volume', "{$media}:/var/www/html/storage/app", '--volume', "{$cache}:/var/www/html/storage/page-cache"]);

    expect(aioWait($name, 'healthy'))->toBe('healthy')
        ->and(aioExec($name, 'cat /var/www/html/storage/app/public/kept.txt /var/www/html/storage/page-cache/kept.txt'))->toBe("precious\ncached\n")
        // Neither is replaced by a link into /var/teme.
        ->and(aioExec($name, 'test -L /var/www/html/storage/app || test -L /var/www/html/storage/page-cache; echo $?'))->toBe("1\n");

    aioRemove($name);

    expect(aioDocker(['run', '--rm', '--volume', "{$media}:/data", '--entrypoint', 'cat', AIO_IMAGE, '/data/public/kept.txt'])->getOutput())->toContain('precious');

    aioDocker(['volume', 'rm', '--force', $media, $cache]);
});

it('reads the login it was given from DB_USERNAME_FILE and DB_PASSWORD_FILE, and gives no process the files’ names', function (): void {
    $name = ($this->start)(['DB_USERNAME' => null, 'DB_PASSWORD' => null, 'DB_USERNAME_FILE' => '/run/aio-secrets/db_username', 'DB_PASSWORD_FILE' => '/run/aio-secrets/db_password']);

    expect(aioWait($name, 'healthy'))->toBe('healthy');

    $prepare = json_decode(aioAs($name, 'root', 'cat /tmp/prepare-env.json'), true, flags: JSON_THROW_ON_ERROR);
    $installer = json_decode(aioExec($name, 'cat /tmp/installer-env-teme-installer'), true, flags: JSON_THROW_ON_ERROR);

    // The step that makes Teme's login got the given one as the values, a trailing line break less, and no file names.
    expect($prepare['DB_USERNAME'] ?? null)->toBe('file-user')
        ->and($prepare['DB_PASSWORD'] ?? null)->toBe('file-password-canary')
        ->and($prepare)->not->toHaveKeys(['DB_USERNAME_FILE', 'DB_PASSWORD_FILE'])
        // The installer gets the login Teme runs as, and neither a file name nor the given password.
        ->and($installer['DB_USERNAME'] ?? null)->toBe('teme_app')
        ->and($installer)->not->toHaveKeys(['DB_USERNAME_FILE', 'DB_PASSWORD_FILE'])
        ->and(json_encode($installer))->not->toContain('file-password-canary');
});

it('stops, in a sentence, when a login is given both as a value and as a file', function (): void {
    $name = ($this->start)(['DB_PASSWORD_FILE' => '/run/aio-secrets/db_password']);

    expect(aioWait($name, 'healthy', 40))->toBe('stopped');

    $logs = aioDocker(['logs', $name]);

    expect($logs->getOutput().$logs->getErrorOutput())->toContain('DB_PASSWORD and DB_PASSWORD_FILE are both set. Set one.');
});

it('gives the installer the log channel and level, and no other LOG_ setting: one can carry a webhook’s secret', function (): void {
    $name = ($this->start)(['LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'info', 'LOG_SLACK_WEBHOOK_URL' => 'https://hooks.example/webhook-canary']);
    aioWait($name, 'healthy');

    $installer = json_decode(aioExec($name, 'cat /tmp/installer-env-teme-installer'), true, flags: JSON_THROW_ON_ERROR);

    expect($installer)->toHaveKeys(['LOG_CHANNEL', 'LOG_LEVEL'])
        ->and(array_keys($installer))->not->toContain('LOG_SLACK_WEBHOOK_URL')
        ->and(json_encode($installer))->not->toContain('webhook-canary');
});

it('runs a terminal’s PHP as www-data, never as root', function (): void {
    $name = ($this->start)();
    aioWait($name, 'healthy');

    expect(aioExec($name, 'php -r \'echo posix_getpwuid(posix_geteuid())["name"];\''))->toBe('www-data');
});

it('stops before web answers when the deploy fails, and says nothing of the database login', function (): void {
    $name = ($this->start)(['FAKE_DEPLOY_EXIT' => '4']);

    expect(aioWait($name, 'healthy', 60))->toBe('stopped')
        ->and(trim(aioDocker(['inspect', '--format', '{{.State.ExitCode}}', $name])->getOutput()))->not->toBe('0');

    $log = aioDocker(['logs', $name])->getOutput().aioDocker(['logs', $name])->getErrorOutput();

    expect($log)->not->toContain('given-password-canary')->and($log)->not->toContain('nginx: master process');
});

it('stops, saying why, when the database step can’t make Teme’s login, and never repeats the login it was given', function (): void {
    $name = ($this->start)(['FAKE_PREPARE_FAIL' => '1']);

    expect(aioWait($name, 'healthy', 60))->toBe('stopped');

    $logs = aioDocker(['logs', $name]);

    expect($logs->getOutput().$logs->getErrorOutput())->toContain('The database refused.')
        ->and($logs->getOutput().$logs->getErrorOutput())->not->toContain('given-password-canary');
});

it('refuses a role it doesn’t know, in one sentence', function (): void {
    $name = ($this->start)(['TEME_ROLE' => 'everything']);

    expect(aioWait($name, 'healthy', 30))->toBe('stopped');

    $logs = aioDocker(['logs', $name]);

    expect($logs->getOutput().$logs->getErrorOutput())->toContain('TEME_ROLE is "everything", and the only role Teme knows is all.');
});

it('runs one role as www-data with no role named, as every compose service always did', function (): void {
    $result = aioDocker(['run', '--rm', '--env', 'LOG_OUTPUT_LEVEL=error', AIO_IMAGE, 'sh', '-c', 'id -un; echo $HOME']);

    expect($result->getOutput())->toEndWith("www-data\n/var/www\n");
});

it('starts web with no role named: PHP-FPM and nginx, dropped to www-data, can open the container’s output', function (): void {
    $name = ($this->start)(['TEME_ROLE' => null]);

    $status = aioWait($name, 'healthy');
    $logs = aioDocker(['logs', $name]);

    expect($status)->toBe('healthy')
        ->and($logs->getOutput().$logs->getErrorOutput())->not->toContain('failed to open error_log')
        ->and(aioExec($name, 'ps -o user= -C php-fpm,nginx | sort -u'))->toBe("www-data\n");
});
