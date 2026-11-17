<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Deployment\DatabaseInit;

/*
 * teme:database:prepare as the deploy step runs it: a process of its own,
 * with the login in its environment, whose output is read as a shell reads
 * it. What it prints is only the login Teme runs as; every sentence is on
 * the error output.
 */

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(8));
    $this->site = "pc_{$suffix}";
    $this->given = "pc_given_{$suffix}";
    $this->password = bin2hex(random_bytes(8));
    $this->admin = DatabaseInit::admin();
    $this->base = config('database.connections.pgsql');
    $this->admin->statement("CREATE ROLE \"{$this->given}\" LOGIN SUPERUSER PASSWORD '{$this->password}'");
    $this->admin->statement("CREATE DATABASE \"{$this->site}\" OWNER \"{$this->given}\" TEMPLATE template0 ENCODING 'UTF8'");
    $this->roles = [$this->given, 'teme_app_'.$this->site];
    $this->run = function (array $environment): Process {
        $process = new Process([PHP_BINARY, 'artisan', 'teme:database:prepare', '--shell', '--no-interaction'], base_path(), [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'APP_CONFIG_CACHE' => base_path('bootstrap/cache/none.php'),
            'DB_URL' => false,
            'DATABASE_URL' => false,
            'TEME_VERIFY_DATABASE' => false,
            'DB_HOST' => $this->base['host'],
            'DB_PORT' => (string) $this->base['port'],
            ...$environment,
        ]);
        $process->run();

        return $process;
    };
});

afterEach(function (): void {
    foreach ([$this->site, "{$this->site}_verify"] as $database) {
        $this->admin->statement("DROP DATABASE IF EXISTS \"{$database}\" WITH (FORCE)");
    }

    foreach (array_reverse($this->roles) as $role) {
        $this->admin->statement("DROP ROLE IF EXISTS \"{$role}\"");
    }
});

it('prints the login Teme runs as, as lines for a shell, and says the rest on the error output', function (): void {
    $process = ($this->run)(['DB_DATABASE' => $this->site, 'DB_USERNAME' => $this->given, 'DB_PASSWORD' => $this->password]);
    $login = 'teme_app_'.$this->site;

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toMatch("/^export DB_HOST='[^']*'\nexport DB_PORT='\\d+'\nexport DB_DATABASE='{$this->site}'\nexport DB_USERNAME='{$login}'\nexport DB_PASSWORD='[0-9a-f]{64}'\nexport TEME_VERIFY_DATABASE='{$this->site}_verify'\n$/")
        ->and($process->getErrorOutput())->toContain("Teme runs as its own database login, {$login}")
        ->and($process->getErrorOutput())->not->toContain($this->password);
});

it('takes the login as a DATABASE_URL, and prints it as separate settings', function (): void {
    $address = sprintf('postgresql://%s:%s@%s:%s/%s', $this->given, $this->password, $this->base['host'], $this->base['port'], $this->site);

    $process = ($this->run)(['DATABASE_URL' => $address]);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain("export DB_DATABASE='{$this->site}'", "export DB_USERNAME='teme_app_{$this->site}'")
        ->and($process->getOutput())->not->toContain('DATABASE_URL')
        ->and($process->getErrorOutput())->not->toContain($this->password);
});

it('refuses a DATABASE_URL it can\'t read, printing nothing and never the password', function (): void {
    $process = ($this->run)(['DATABASE_URL' => "mysql://{$this->given}:{$this->password}@db.internal/{$this->site}"]);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('DATABASE_URL isn\'t a PostgreSQL address Teme can read.')
        ->and($process->getErrorOutput())->not->toContain($this->password);
});

it('refuses a DB_HOST that holds an address, printing nothing and never the password', function (): void {
    $process = ($this->run)(['DB_HOST' => "postgresql://{$this->given}:{$this->password}@db.internal:5432/{$this->site}", 'DB_DATABASE' => $this->site, 'DB_USERNAME' => $this->given, 'DB_PASSWORD' => $this->password]);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('DB_HOST holds an address, not a host name.')
        ->and($process->getErrorOutput())->not->toContain($this->password);
});

it('prints nothing and exits when the login is wrong, without the password', function (): void {
    $process = ($this->run)(['DB_DATABASE' => $this->site, 'DB_USERNAME' => $this->given, 'DB_PASSWORD' => 'a-wrong-password']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain("Teme couldn't connect to the database")
        ->and($process->getErrorOutput())->not->toContain('a-wrong-password');
});

it('leaves a spare database the operator named as it is', function (): void {
    $process = ($this->run)(['DB_DATABASE' => $this->site, 'DB_USERNAME' => $this->given, 'DB_PASSWORD' => $this->password, 'TEME_VERIFY_DATABASE' => 'pc_elsewhere_verify']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain("export TEME_VERIFY_DATABASE='pc_elsewhere_verify'")
        ->and(DB::connection(DatabaseInit::admin()->getName())->scalar("SELECT count(*) FROM pg_database WHERE datname = '{$this->site}_verify'"))->toBe(0);
});
