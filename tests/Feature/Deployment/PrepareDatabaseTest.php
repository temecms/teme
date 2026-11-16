<?php

declare(strict_types=1);

use App\Domain\Deployment\DatabaseLogin;
use App\Domain\Deployment\DatabasePrepareFailed;
use App\Domain\Deployment\PrepareDatabase;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\Deployment\DatabaseInit;

/*
 * The database step of a container that runs every role: given a login to
 * Dokploy's own PostgreSQL service, it makes Teme's own limited login. Run
 * on the test server, outside any transaction, under names of the test's
 * own, which afterEach removes.
 */

const PD_KEY = 'base64:a-key-the-login-password-is-derived-from';

beforeEach(function (): void {
    $suffix = Str::lower(Str::random(8));
    $this->site = "pd_{$suffix}";
    $this->given = "pd_given_{$suffix}";
    $this->givenPassword = bin2hex(random_bytes(8));
    $this->admin = DatabaseInit::admin();
    $this->base = config('database.connections.pgsql');
    $this->address = fn (string $username, string $password, ?string $database = null): array => [
        'host' => $this->base['host'], 'port' => (string) $this->base['port'], 'database' => $database ?? $this->site,
        'username' => $username, 'password' => $password,
    ];
    $this->as = function (string $username, string $password, string $database): ConnectionInterface {
        $name = "pd_as_{$username}_{$database}";
        DB::purge($name);
        DB::connectUsing($name, [...$this->base, 'database' => $database, 'username' => $username, 'password' => $password, 'name' => $name]);

        return DB::connection($name);
    };
    // The site's database, made by the service as the superuser it gave, as Dokploy's PostgreSQL service makes one.
    $this->admin->statement("CREATE ROLE \"{$this->given}\" LOGIN SUPERUSER PASSWORD '{$this->givenPassword}'");
    $this->admin->statement("CREATE DATABASE \"{$this->site}\" OWNER \"{$this->given}\" TEMPLATE template0 ENCODING 'UTF8'");
    $this->roles = [$this->given];
});

afterEach(function (): void {
    foreach ([$this->site, "{$this->site}_verify", 'pd_named_verify'] as $database) {
        $this->admin->statement("DROP DATABASE IF EXISTS \"{$database}\" WITH (FORCE)");
    }

    foreach (array_reverse($this->roles) as $role) {
        $this->admin->statement("DROP ROLE IF EXISTS \"{$role}\"");
    }
});

it('makes a login of Teme’s own that owns the site’s database and a spare one, and is no superuser', function (): void {
    $login = (new PrepareDatabase)->run(($this->address)($this->given, $this->givenPassword), PD_KEY);
    $this->roles[] = $login->username;

    $role = (array) $this->admin->selectOne('SELECT rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls FROM pg_roles WHERE rolname = ?', [$login->username]);
    $owners = $this->admin->select('SELECT datname, pg_get_userbyid(datdba) AS owner FROM pg_database WHERE datname IN (?, ?) ORDER BY datname', [$this->site, "{$this->site}_verify"]);

    expect($login->created)->toBeTrue()
        ->and($login->username)->not->toBe($this->given)
        ->and($login->database)->toBe($this->site)
        ->and($login->verifyDatabase)->toBe("{$this->site}_verify")
        ->and($role)->toBe(['rolcanlogin' => true, 'rolsuper' => false, 'rolcreatedb' => false, 'rolcreaterole' => false, 'rolreplication' => false, 'rolbypassrls' => false])
        ->and(array_map(fn (object $row): array => (array) $row, $owners))->toBe([
            ['datname' => $this->site, 'owner' => $login->username],
            ['datname' => "{$this->site}_verify", 'owner' => $login->username],
        ]);
});

it('gives the login a password derived from the application key, so every start agrees on it and nothing is stored', function (): void {
    $first = (new PrepareDatabase)->run(($this->address)($this->given, $this->givenPassword), PD_KEY);
    $this->roles[] = $first->username;
    $again = (new PrepareDatabase)->run(($this->address)($this->given, $this->givenPassword), PD_KEY);

    expect($again->password)->toBe($first->password)
        ->and($first->password)->toMatch('/^[0-9a-f]{64}$/')
        ->and(($this->as)($again->username, $again->password, $this->site)->scalar('select current_user'))->toBe($first->username);

    $other = (new PrepareDatabase)->run(($this->address)($this->given, $this->givenPassword), 'base64:another-key');

    expect($other->password)->not->toBe($first->password)
        ->and(($this->as)($other->username, $other->password, $this->site)->scalar('select current_user'))->toBe($first->username);
});

it('lets the login create tables in the site’s database, and nothing beyond it', function (): void {
    $login = (new PrepareDatabase)->run(($this->address)($this->given, $this->givenPassword), PD_KEY);
    $this->roles[] = $login->username;
    $session = ($this->as)($login->username, $login->password, $this->site);

    $session->statement('CREATE TABLE teme_probe (id integer)');

    expect($session->scalar("SELECT to_regclass('public.teme_probe') IS NOT NULL"))->toBeTrue()
        ->and(fn () => $session->statement('CREATE DATABASE teme_probe_never'))->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => $session->statement('CREATE ROLE teme_probe_never'))->toThrow(QueryException::class, 'permission denied');
});

it('is safe to run again: nothing is made twice and nothing breaks', function (): void {
    $first = (new PrepareDatabase)->run(($this->address)($this->given, $this->givenPassword), PD_KEY);
    $this->roles[] = $first->username;
    ($this->as)($first->username, $first->password, $this->site)->statement('CREATE TABLE kept (id integer)');

    $second = (new PrepareDatabase)->run(($this->address)($this->given, $this->givenPassword), PD_KEY);

    expect($second->username)->toBe($first->username)
        ->and(($this->as)($second->username, $second->password, $this->site)->scalar("SELECT to_regclass('public.kept') IS NOT NULL"))->toBeTrue();
});

it('hands the login the tables the given user had already made', function (): void {
    ($this->as)($this->given, $this->givenPassword, $this->site)->statement('CREATE TABLE made_earlier (id serial PRIMARY KEY)');

    $login = (new PrepareDatabase)->run(($this->address)($this->given, $this->givenPassword), PD_KEY);
    $this->roles[] = $login->username;

    $session = ($this->as)($this->given, $this->givenPassword, $this->site);

    expect($session->scalar("SELECT tableowner FROM pg_tables WHERE tablename = 'made_earlier'"))->toBe($login->username)
        ->and($session->scalar("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE relname = 'made_earlier_id_seq'"))->toBe($login->username);
});

it('lets no other login connect to the site’s database', function (): void {
    $login = (new PrepareDatabase)->run(($this->address)($this->given, $this->givenPassword), PD_KEY);
    $this->roles[] = $login->username;
    $stranger = 'pd_stranger_'.Str::lower(Str::random(6));
    $this->admin->statement("CREATE ROLE \"{$stranger}\" LOGIN PASSWORD 'stranger'");
    $this->roles[] = $stranger;

    expect(fn () => ($this->as)($stranger, 'stranger', $this->site)->select('select 1'))->toThrow(QueryException::class, 'permission denied for database');
});

it('runs Teme as the given login, making nothing, when that login is no superuser', function (): void {
    $plain = 'pd_plain_'.Str::lower(Str::random(6));
    $this->admin->statement("CREATE ROLE \"{$plain}\" LOGIN NOSUPERUSER PASSWORD 'plainpw'");
    $this->roles[] = $plain;
    $this->admin->statement("ALTER DATABASE \"{$this->site}\" OWNER TO \"{$plain}\"");
    $rolesBefore = $this->admin->scalar('SELECT count(*) FROM pg_roles');

    $login = (new PrepareDatabase)->run(($this->address)($plain, 'plainpw'), PD_KEY);

    expect($login->created)->toBeFalse()
        ->and($login->username)->toBe($plain)
        ->and($login->password)->toBe('plainpw')
        ->and($login->verifyDatabase)->toBeNull()
        ->and($this->admin->scalar('SELECT count(*) FROM pg_roles'))->toBe($rolesBefore);
});

it('leaves the spare database alone when the operator named one', function (): void {
    $login = (new PrepareDatabase)->run(($this->address)($this->given, $this->givenPassword), PD_KEY, 'pd_named_verify');
    $this->roles[] = $login->username;

    expect($login->verifyDatabase)->toBe('pd_named_verify')
        ->and($this->admin->scalar("SELECT count(*) FROM pg_database WHERE datname = 'pd_named_verify'"))->toBe(0);
});

it('refuses, in its own words and without the password, when the login is wrong', function (): void {
    try {
        (new PrepareDatabase)->run(($this->address)($this->given, 'not-the-password'), PD_KEY);
        $message = null;
    } catch (DatabasePrepareFailed $failure) {
        $message = $failure->getMessage();
    }

    expect($message)->toStartWith("Teme couldn't connect to the database at {$this->base['host']}:{$this->base['port']} as {$this->given}")
        ->and($message)->not->toContain('not-the-password');
});

it('names the login after the database, so two sites on one server never share one', function (): void {
    expect(PrepareDatabase::loginFor('teme'))->toBe('teme_app_teme')
        ->and(PrepareDatabase::loginFor('Blog-Site.2'))->toBe('teme_app_blog_site_2')
        ->and(strlen(PrepareDatabase::loginFor(str_repeat('x', 80))))->toBeLessThanOrEqual(63);
});

it('writes the login as lines a shell reads, each value quoted', function (): void {
    $login = new DatabaseLogin(host: 'h', port: '5432', database: 'd', username: 'u', password: "pa'ss", verifyDatabase: 'd_verify', created: true);

    expect($login->shell())->toBe("export DB_HOST='h'\nexport DB_PORT='5432'\nexport DB_DATABASE='d'\nexport DB_USERNAME='u'\nexport DB_PASSWORD='pa'\\''ss'\nexport TEME_VERIFY_DATABASE='d_verify'\n");
});
