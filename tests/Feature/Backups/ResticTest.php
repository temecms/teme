<?php

declare(strict_types=1);

use App\Domain\Backups\Tools\BackupTools;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticOutcome;
use App\Domain\Backups\Tools\ResticRepository;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\Backups\ScriptedRestic;
use Tests\Fixtures\Backups\TemporaryRepository;

beforeEach(function (): void {
    config(['teme.backups.cache_path' => '']);
    $this->made = [];
    $this->repository = fn (): TemporaryRepository => $this->made[] = TemporaryRepository::make();
    $this->elsewhere = new ResticRepository(TemporaryRepository::freshFolder(), TemporaryRepository::KEY);
});

afterEach(function (): void {
    foreach ($this->made as $made) {
        $made->delete();
    }
});

it('makes a repository in a folder, and reads its ID back (I-2400)', function (): void {
    $made = ($this->repository)();
    $config = app(Restic::class)->run($made->repository, ['cat', 'config']);

    expect($made->id)->toMatch('/^[0-9a-f]{64}$/')
        ->and($config->succeeded())->toBeTrue()
        ->and($config->outcome)->toBe(ResticOutcome::Ok)
        ->and($config->json()['id'])->toBe($made->id)
        ->and($config->sentence('Off site'))->toBe('')
        ->and($config->detail())->toBe('')
        ->and(app(Restic::class)->run($made->repository, ['snapshots'])->json())->toBe([]);
});

it('says the key didn\'t open the backups, by restic\'s exit status 12', function (): void {
    $made = ($this->repository)();
    $result = app(Restic::class)->run(new ResticRepository($made->folder, 'not-the-key'), ['snapshots']);

    expect($result->outcome)->toBe(ResticOutcome::WrongKey)
        ->and($result->exitCode)->toBe(12)
        ->and($result->succeeded())->toBeFalse()
        ->and($result->sentence('Off site'))->toBe('The key didn’t open the backups at Off site.')
        ->and($result->detail())->toContain('wrong password')
        ->and($result->detail())->not->toContain('not-the-key');
});

it('says there are no backups at a place that has none, by exit status 10', function (): void {
    $result = app(Restic::class)->run($this->elsewhere, ['snapshots']);

    expect($result->outcome)->toBe(ResticOutcome::NoRepository)
        ->and($result->exitCode)->toBe(10)
        ->and($result->sentence('Off site'))->toBe('Teme found no backups at Off site. Check its address, and that the storage is still there.')
        ->and($result->detail())->toContain('repository does not exist');
});

it('says the backups were locked while another task holds them, by exit status 11', function (): void {
    $made = ($this->repository)();
    // A backup reading from a slow program holds a lock for as long as it runs.
    $holder = Process::env($made->repository->variables('RESTIC_'))->start([
        app(BackupTools::class)->restic(), '--json', '--no-cache',
        'backup', '--stdin-filename', 'slow.txt', '--stdin-from-command', '--', PHP_BINARY, '-r', 'sleep(60);',
    ]);
    $deadline = microtime(true) + 20;

    while ((glob($made->folder.'/locks/*') ?: []) === [] && microtime(true) < $deadline) {
        usleep(100_000);
    }

    $locked = (glob($made->folder.'/locks/*') ?: []) !== [];
    $result = app(Restic::class)->run($made->repository, ['prune']);
    // 9 is SIGKILL; PHP has no SIGKILL constant without pcntl, as on Windows.
    $holder->signal(9);
    $holder->wait();

    expect($locked)->toBeTrue()
        ->and($result->outcome)->toBe(ResticOutcome::Locked)
        ->and($result->exitCode)->toBe(11)
        ->and($result->sentence('On this server'))->toBe('The backups at On this server were locked by another task, so this didn’t run.');
});

it('passes the repository and its key in the environment only, never on the command line', function (): void {
    ScriptedRestic::exits(0, output: '[]');
    $repository = new ResticRepository(
        's3:https://s3.example.com/teme-backups/site',
        'the-repository-key',
        ['AWS_ACCESS_KEY_ID' => 'AKIATEST', 'AWS_SECRET_ACCESS_KEY' => 'the-s3-secret'],
        ['s3.region=eu-west-1'],
    );

    expect(app(Restic::class)->run($repository, ['snapshots'])->json())->toBe([]);

    Process::assertRan(function (PendingProcess $process): bool {
        $line = implode(' ', $process->command);

        return $process->command[0] === app(BackupTools::class)->restic()
            && array_slice($process->command, 1) === ['--json', '--no-cache', '-o', 's3.region=eu-west-1', 'snapshots']
            && ! str_contains($line, 'the-repository-key') && ! str_contains($line, 'the-s3-secret') && ! str_contains($line, 's3:https')
            && $process->environment['RESTIC_REPOSITORY'] === 's3:https://s3.example.com/teme-backups/site'
            && $process->environment['RESTIC_PASSWORD'] === 'the-repository-key'
            && $process->environment['AWS_ACCESS_KEY_ID'] === 'AKIATEST'
            && $process->environment['AWS_SECRET_ACCESS_KEY'] === 'the-s3-secret'
            && $process->environment['RESTIC_PROGRESS_FPS'] === '0.1';
    });
});

it('drops restic\'s, S3\'s and PostgreSQL\'s variables Teme itself was started with', function (): void {
    $made = ($this->repository)();
    // Inherited, either file variable makes restic 0.19.1 refuse to run.
    $stray = [
        'RESTIC_PASSWORD_FILE' => TemporaryRepository::freshFolder().'.password',
        'RESTIC_REPOSITORY_FILE' => TemporaryRepository::freshFolder().'.repository',
        'AWS_PROFILE' => 'someone-else',
        'PGPASSWORD' => 'not-teme-s',
    ];

    foreach ($stray as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        $result = app(Restic::class)->run($made->repository, ['snapshots']);
    } finally {
        foreach (array_keys($stray) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    expect($result->succeeded())->toBeTrue($result->detail())
        ->and($result->json())->toBe([]);
});

it('hands each JSON message to the caller as restic prints it, and keeps the summary', function (): void {
    $made = ($this->repository)();
    $types = [];

    $result = app(Restic::class)->run(
        $made->repository,
        ['backup', '--stdin-filename', 'teme.txt', '--stdin-from-command', '--', PHP_BINARY, '-r', 'echo str_repeat(chr(120),100000);'],
        onMessage: function (array $message) use (&$types): void {
            $types[] = $message['message_type'] ?? null;
        },
    );

    expect($result->succeeded())->toBeTrue($result->detail())
        ->and($types)->not->toBe([])
        ->and($types[array_key_last($types)])->toBe('summary')
        ->and($result->message('summary')['snapshot_id'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($result->message('summary')['total_bytes_processed'])->toBe(100000)
        ->and(app(Restic::class)->run($made->repository, ['snapshots'])->json())->toHaveCount(1);
});

it('counts a backup that couldn\'t read some files as failed, by exit status 3', function (): void {
    ScriptedRestic::exits(3, ScriptedRestic::exitError(3, 'Warning: at least one source file could not be read'));

    $result = app(Restic::class)->run($this->elsewhere, ['backup', '--stdin-filename', 'teme.dump', '--stdin']);

    expect($result->outcome)->toBe(ResticOutcome::Incomplete)
        ->and($result->succeeded())->toBeFalse()
        ->and($result->sentence('On this server'))->toBe('Restic couldn’t finish everything at On this server: some files couldn’t be read, or some old backups couldn’t be removed.')
        ->and($result->detail())->toBe('Warning: at least one source file could not be read');
});

it('treats a crash, a cancel, and a status restic doesn\'t document, as failures', function (): void {
    $cases = [
        1 => [ResticOutcome::Failed, 'The backup tool couldn’t finish at Off site.'],
        2 => [ResticOutcome::Crashed, 'The backup tool crashed at Off site.'],
        130 => [ResticOutcome::Cancelled, 'The backup tool was stopped at Off site before it finished.'],
        42 => [ResticOutcome::Failed, 'The backup tool couldn’t finish at Off site.'],
    ];

    foreach ($cases as $code => [$outcome, $sentence]) {
        ScriptedRestic::exits($code, "panic: something\n");
        $result = app(Restic::class)->run($this->elsewhere, ['check']);

        expect($result->outcome)->toBe($outcome)
            ->and($result->exitCode)->toBe($code)
            ->and($result->succeeded())->toBeFalse()
            ->and($result->sentence('Off site'))->toBe($sentence)
            ->and($result->detail())->toBe('panic: something');
    }
});

it('stops restic at its time limit, and says so in minutes', function (): void {
    ScriptedRestic::timesOut();

    $hour = app(Restic::class)->run($this->elsewhere, ['check'], timeout: 3600);
    $short = app(Restic::class)->run($this->elsewhere, ['check'], timeout: 45);

    expect($hour->outcome)->toBe(ResticOutcome::TimedOut)
        ->and($hour->exitCode)->toBeNull()
        ->and($hour->succeeded())->toBeFalse()
        ->and($hour->sentence('Off site'))->toBe('The backup tool took longer than 60 minutes at Off site, so Teme stopped it.')
        ->and($short->sentence('Off site'))->toBe('The backup tool took longer than 1 minute at Off site, so Teme stopped it.');
});

it('hides every secret of the call from restic\'s own words, and nothing that isn\'t one', function (): void {
    ScriptedRestic::exits(1, ScriptedRestic::exitError(1, 'Fatal: the-repository-key, the-s3-secret, the-new-key and the-pg-password at /srv/teme/backups'));
    $repository = new ResticRepository('s3:https://s3.example.com/b', 'the-repository-key', ['AWS_SECRET_ACCESS_KEY' => 'the-s3-secret', 'AWS_DEFAULT_REGION' => 'eu-west-1']);

    $result = app(Restic::class)->run(
        $repository,
        ['key', 'add'],
        environment: ['PGPASSWORD' => 'the-pg-password', 'PGUSER' => 'teme'],
        input: "the-new-key\n",
    );

    // PGUSER is no secret, so the path that holds its value stays readable.
    expect($result->detail())->toBe('Fatal: [hidden], [hidden], [hidden] and [hidden] at /srv/teme/backups')
        ->and($result->sentence('Off site'))->toBe('The backup tool couldn’t finish at Off site.');

    Process::assertRan(fn (PendingProcess $process): bool => $process->input === "the-new-key\n"
        && ! str_contains(implode(' ', $process->command), 'the-new-key'));
});

it('refuses two repositories that need different values of one S3 variable or option, running nothing', function (): void {
    ScriptedRestic::exits(0);
    $first = new ResticRepository('s3:https://s3.example.com/one', 'key-one', ['AWS_ACCESS_KEY_ID' => 'AKIAONE']);
    $second = new ResticRepository('s3:https://s3.example.com/two', 'key-two', ['AWS_ACCESS_KEY_ID' => 'AKIATWO']);
    $east = new ResticRepository('s3:https://s3.example.com/one', 'key-one', [], ['s3.region=us-east-1']);
    $west = new ResticRepository('s3:https://s3.example.com/two', 'key-two', [], ['s3.region=eu-west-1']);

    expect(fn () => app(Restic::class)->run($second, ['copy'], from: $first))
        ->toThrow(LogicException::class, 'restic reads one AWS_ACCESS_KEY_ID for both repositories, and these two need different ones.')
        ->and(fn () => app(Restic::class)->run($west, ['copy'], from: $east))
        ->toThrow(LogicException::class, 'restic reads one s3.region for both repositories, and these two need different ones.');

    Process::assertNothingRan();

    $same = new ResticRepository('s3:https://s3.example.com/two', 'key-two', ['AWS_ACCESS_KEY_ID' => 'AKIAONE']);
    app(Restic::class)->run($same, ['copy'], from: $first);

    Process::assertRan(fn (PendingProcess $process): bool => $process->environment['RESTIC_FROM_REPOSITORY'] === 's3:https://s3.example.com/one'
        && $process->environment['RESTIC_FROM_PASSWORD'] === 'key-one'
        && $process->environment['RESTIC_PASSWORD'] === 'key-two');
});

it('never serializes a repository, nor shows its key when dumped', function (): void {
    $repository = new ResticRepository('/srv/backups', 'the-repository-key', ['AWS_SECRET_ACCESS_KEY' => 'the-s3-secret']);

    expect(fn () => serialize($repository))->toThrow(LogicException::class, 'A repository’s key is never queued or stored: carry the place’s ID and build the repository inside the job.')
        ->and(print_r($repository, true))->toContain('/srv/backups')->not->toContain('the-repository-key')->not->toContain('the-s3-secret')
        ->and((string) json_encode($repository))->not->toContain('the-repository-key');
});

it('refuses a repository without a key, with a location over two lines, or with variables that aren\'t its backend\'s', function (): void {
    expect(fn () => new ResticRepository('/srv/backups', ''))->toThrow(InvalidArgumentException::class, 'A restic repository needs a key.')
        ->and(fn () => new ResticRepository("/srv/backups\n-o x", 'key'))->toThrow(InvalidArgumentException::class, 'A restic repository needs a location on one line.')
        ->and(fn () => new ResticRepository('/srv/backups', 'key', ['RESTIC_PASSWORD_FILE' => '/tmp/x']))
        ->toThrow(InvalidArgumentException::class, 'A repository’s own variables are its backend’s AWS_ ones: [RESTIC_PASSWORD_FILE] isn’t one.')
        ->and(fn () => new ResticRepository('/srv/backups', 'key', [], ['--insecure-tls']))
        ->toThrow(InvalidArgumentException::class, 'A restic option is key=value: [--insecure-tls].');
});

it('runs restic without a cache when none is configured, and with the configured folder otherwise', function (): void {
    ScriptedRestic::exits(0, output: '[]');
    $repository = new ResticRepository('/srv/backups', 'key');

    app(Restic::class)->run($repository, ['snapshots']);
    config(['teme.backups.cache_path' => '/var/cache/teme-restic']);
    app(Restic::class)->run($repository, ['snapshots']);

    Process::assertRan(fn (PendingProcess $process): bool => in_array('--no-cache', $process->command, true));
    Process::assertRan(fn (PendingProcess $process): bool => in_array('--cache-dir=/var/cache/teme-restic', $process->command, true));
});

it('gives a result that never started when restic isn\'t there, with the words to fix it', function (): void {
    config(['teme.backups.restic' => 'teme-no-such-program']);

    $result = app(Restic::class)->run(new ResticRepository('/srv/backups', 'key'), ['snapshots']);

    expect($result->outcome)->toBe(ResticOutcome::NotStarted)
        ->and($result->exitCode)->toBeNull()
        ->and($result->sentence('Off site'))->toBe('Teme couldn’t start restic. Run php artisan teme:backup:tools at the server to see why.')
        ->and($result->detail())->toBe('restic isn’t on the PATH. Install restic 0.19.1, or set TEME_RESTIC_BINARY to where it is.');
});
