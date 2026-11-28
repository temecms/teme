<?php

declare(strict_types=1);

use App\Domain\Deployment\StartRefused;
use App\Domain\Deployment\StartRules;
use App\Providers\DeploymentServiceProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Deployment\Edge;

beforeEach(function (): void {
    $this->argv = $_SERVER['argv'] ?? [];
});

afterEach(function (): void {
    $_SERVER['argv'] = $this->argv;
});

it('starts a production site configured as the image configures it (I-375)', function (): void {
    expect(StartRules::problems(Edge::startableConfig()))->toBe([]);
});

it('refuses production without APP_KEY, and says how to make one without Teme', function (): void {
    expect(StartRules::problems(Edge::startableConfig(['app.key' => ''])))->toBe([
        'APP_KEY is empty. Teme never makes one: set APP_KEY, or APP_KEY_FILE, in the server\'s environment. To make a key: echo "base64:$(openssl rand -base64 32)"',
    ]);
});

it('refuses an APP_KEY that isn’t a key for the cipher, without repeating it', function (string $key): void {
    $problems = StartRules::problems(Edge::startableConfig(['app.key' => $key]));

    expect($problems)->toBe([
        "APP_KEY isn't a key for AES-256-CBC: write base64: followed by 32 random bytes in base64, as echo \"base64:\$(openssl rand -base64 32)\" makes one.",
    ])->and(implode("\n", $problems))->not->toContain($key);
})->with([
    'sixteen bytes' => ['base64:'.base64_encode('sixteen-bytes-ok')],
    'not base64' => ['base64:***not-base64***'],
    'raw, the wrong length' => ['a-raw-key-that-is-too-short'],
]);

it('takes a key written in base64 or raw', function (string $key): void {
    expect(StartRules::problems(Edge::startableConfig(['app.key' => $key])))->toBe([]);
})->with([
    'base64' => ['base64:'.base64_encode(random_bytes(32))],
    'raw' => [str_repeat('r', 32)],
]);

it('refuses a session driver other than the database', function (string $driver): void {
    expect(StartRules::problems(Edge::startableConfig(['session.driver' => $driver])))->toBe([
        "SESSION_DRIVER is {$driver}. A production site keeps sessions in the database (SESSION_DRIVER=database), so ending a person's sessions ends every one of them.",
    ]);
})->with(['file', 'array', 'redis', 'cookie']);

it('refuses an APP_URL with no host', function (): void {
    expect(StartRules::problems(Edge::startableConfig(['app.url' => ''])))->toBe([
        "APP_URL has no host. Set it to the site's address, such as https://example.com.",
    ]);
});

it('over https, refuses a session cookie that isn’t Secure', function (?bool $secure): void {
    expect(StartRules::problems(Edge::startableConfig(['session.secure' => $secure])))->toBe([
        "SESSION_SECURE_COOKIE isn't true, but APP_URL is https: the session cookie must be Secure. Remove SESSION_SECURE_COOKIE, or set it to true.",
    ]);
})->with(['false' => [false], 'unset' => [null]]);

it('over https, refuses a session cookie whose name doesn’t begin __Host-', function (): void {
    expect(StartRules::problems(Edge::startableConfig(['session.cookie' => 'teme-session'])))->toBe([
        "SESSION_COOKIE is teme-session. Over https the session cookie's name begins __Host-: remove SESSION_COOKIE, or begin it with __Host-.",
    ]);
});

it('over https, refuses a session cookie that names a domain or a path other than /', function (): void {
    expect(StartRules::problems(Edge::startableConfig(['session.domain' => 'example.com', 'session.path' => '/admin'])))->toBe([
        'SESSION_DOMAIN is example.com. A __Host- cookie names no domain: remove SESSION_DOMAIN.',
        "SESSION_PATH is /admin. A __Host- cookie's path is /: remove SESSION_PATH.",
    ]);
});

it('refuses an HSTS value Teme wouldn’t send, and takes those it would', function (string $value, bool $refused): void {
    expect(StartRules::problems(Edge::startableConfig(['teme.deployment.hsts' => $value])))->toBe($refused ? [
        "TEME_HSTS is \"{$value}\", which isn't a Strict-Transport-Security value: write max-age= and a number of seconds, then ; includeSubDomains and ; preload if wanted, or leave it empty to send none.",
    ] : []);
})->with([
    'a year' => ['max-age=31536000', false],
    'everything' => ['max-age=63072000; includeSubDomains; preload', false],
    'forget it, in capitals' => ['MAX-AGE=0', false],
    'none' => ['', false],
    'words for a number' => ['max-age=1 year', true],
    'no max-age' => ['includeSubDomains', true],
    'a comma' => ['max-age=31536000, preload', true],
]);

it('asks nothing of cookies or HSTS when APP_URL is http', function (): void {
    expect(StartRules::problems(Edge::startableConfig([
        'app.url' => 'http://example.com',
        'session.secure' => false,
        'session.cookie' => 'teme-session',
        'teme.deployment.hsts' => 'nonsense',
    ])))->toBe([]);
});

it('names every problem in one refusal', function (): void {
    $problems = StartRules::problems(Edge::startableConfig(['app.key' => '', 'session.driver' => 'file', 'session.secure' => false]));

    expect($problems)->toHaveCount(3)
        ->and(StartRefused::because($problems)->getMessage())->toBe("Teme cannot start in production:\n- ".implode("\n- ", $problems));
});

it('checks a production site’s web requests and the commands that start a role, never a build step', function (string $command, bool $checked): void {
    app()->instance('env', 'production');
    config(['app.key' => '']);
    $_SERVER['argv'] = ['artisan', $command];

    $checked
        ? expect(fn () => StartRules::enforce(app()))->toThrow(StartRefused::class)
        : expect(fn () => StartRules::enforce(app()))->not->toThrow(Throwable::class);
})->with([
    ['config:cache', true],
    ['queue:work', true],
    ['schedule:work', true],
    ['package:discover', false],
    ['route:cache', false],
    ['migrate', false],
]);

it('checks nothing outside production', function (): void {
    app()->instance('env', 'staging');
    config(['app.key' => '']);
    $_SERVER['argv'] = ['artisan', 'queue:work'];

    expect(fn () => StartRules::enforce(app()))->not->toThrow(Throwable::class);
});

it('runs as the deployment provider registers', function (): void {
    app()->instance('env', 'production');
    config(['app.key' => '']);
    $_SERVER['argv'] = ['artisan', 'queue:work'];

    expect(fn () => (new DeploymentServiceProvider(app()))->register())->toThrow(function (StartRefused $refusal): void {
        expect($refusal->getMessage())->toContain('APP_KEY is empty.');
    });
});

it('refuses a DB_HOST that holds an address, and never repeats the password in it', function (string $host): void {
    $problems = StartRules::problems(Edge::startableConfig(['database.connections.pgsql.host' => $host]));

    expect($problems)->toBe([
        'DB_HOST holds an address, not a host name. Set DB_HOST to the server\'s name alone, such as db.internal, and give the rest in DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD, or give the whole address as DATABASE_URL.',
    ])->and(implode("\n", $problems))->not->toContain('hunter2');
})->with([
    'a full address' => ['postgresql://teme:hunter2@db.internal:5432/teme'],
    'a login before the host' => ['teme:hunter2@db.internal'],
    'a path after it' => ['db.internal/teme?password=hunter2'],
]);

it('takes a host name, an IP address and an IPv6 address in DB_HOST', function (string $host): void {
    expect(StartRules::problems(Edge::startableConfig(['database.connections.pgsql.host' => $host])))->toBe([]);
})->with(['a name' => ['db.internal'], 'a short name' => ['teme-postgres'], 'IPv4' => ['10.0.0.5'], 'IPv6' => ['::1'], 'a socket folder' => ['/var/run/postgresql']]);

it('takes a DATABASE_URL for PostgreSQL', function (string $url): void {
    expect(StartRules::problems(Edge::startableConfig(['database.connections.pgsql.url' => $url])))->toBe([]);
})->with([
    'postgresql' => ['postgresql://teme:hunter2@db.internal:5432/teme'],
    'postgres' => ['postgres://teme:hunter2@db.internal/teme'],
    'a password with escaped characters' => ['postgresql://teme:p%40ss%2Fword@db.internal:5432/teme'],
]);

it('refuses a DATABASE_URL it can\'t read, in a sentence that never repeats it', function (string $url): void {
    $problems = StartRules::problems(Edge::startableConfig(['database.connections.pgsql.url' => $url]));

    expect($problems)->toBe([
        'DATABASE_URL isn\'t a PostgreSQL address Teme can read. Write it as postgresql://user:password@host:5432/database, with any special character in the password written as %XX.',
    ])->and(implode("\n", $problems))->not->toContain('hunter2');
})->with([
    'the wrong kind of database' => ['mysql://teme:hunter2@db.internal:3306/teme'],
    'no host' => ['postgresql://teme:hunter2@/teme'],
    'a port that isn\'t one' => ['postgresql://teme:hunter2@db.internal:notaport/teme'],
    'no database' => ['postgresql://teme:hunter2@db.internal:5432'],
    'not an address' => ['teme hunter2 db.internal'],
]);

it('reads DATABASE_URL as the database connection’s address when DB_URL is unset', function (): void {
    $process = new Process([PHP_BINARY, 'artisan', 'config:show', 'database.connections.pgsql.url', '--no-interaction'], base_path(), [
        'DATABASE_URL' => 'postgresql://teme:hunter2@db.internal:5432/teme',
        'DB_URL' => false,
        'APP_CONFIG_CACHE' => base_path('bootstrap/cache/none.php'),
    ]);
    $process->mustRun();

    expect($process->getOutput())->toContain('postgresql://teme:hunter2@db.internal:5432/teme');
});

it('refuses an unknown TEME_BACKUPS in a plain sentence, and takes teme, host or nothing', function (string $mode, array $expected): void {
    expect(StartRules::problems(Edge::startableConfig(['teme.backup_mode' => $mode])))->toBe($expected);
})->with([
    'an unknown mode' => ['hosted', ['TEME_BACKUPS is “hosted”, which Teme doesn’t know. Use teme or host.']],
    'teme' => ['teme', []],
    'host, in any case' => [' Host ', []],
    'nothing, which means teme' => ['', []],
]);
