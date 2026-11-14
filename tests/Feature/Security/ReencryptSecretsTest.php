<?php

declare(strict_types=1);

use App\Domain\Backups\BackupSettings;
use App\Domain\Security\OldKeys;
use App\Domain\Security\Operations\Input\ReencryptSecretsInput;
use App\Domain\Security\Operations\ReencryptSecrets;
use App\Domain\Security\ReencryptedColumn;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Security\OldKey;
use Tests\Fixtures\Security\Reencrypt;
use Tests\Fixtures\Security\SealedSecrets;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    SealedSecrets::serverPreviousKeys([]);

    // One value in each registered column, sealed under a key that isn't
    // this server's, as a restored backup's database holds them.
    $this->old = OldKey::make();
    $this->person = User::factory()->create();
    SealedSecrets::twoFactor($this->person, $this->old->encrypter, 'JBSWY3DPEHPK3PXP', ['AAAAAAAAAA-BBBBBBBBBB', 'CCCCCCCCCC-DDDDDDDDDD']);
    SealedSecrets::settings(BackupSettings::GROUP, ['machine_key' => str_repeat('b', 64)], $this->old->encrypter);
    $this->place = SealedSecrets::place($this->old->encrypter, ['access_key_id' => 'AKIAOLD', 'secret_access_key' => 'old-secret']);

    $this->column = fn ($result, string $name): ReencryptedColumn => collect($result->columns)
        ->first(fn (ReencryptedColumn $column): bool => $column->column->name() === $name);
});

it('writes nothing when authorization fails', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    $before = [SealedSecrets::stored(), DB::table('security_events')->count()];

    expect(fn () => app(ReencryptSecrets::class)->execute(new ReencryptSecretsInput($this->old->keys(), actorId: (string) $admin->id)))
        ->toThrow(AuthorizationException::class, 'Only the operator re-encrypts secrets.')
        ->and(fn () => app(ReencryptSecrets::class)->execute(new ReencryptSecretsInput($this->old->keys())))
        ->toThrow(AuthorizationException::class, 'Only the operator re-encrypts secrets.')
        ->and([SealedSecrets::stored(), DB::table('security_events')->count()])->toBe($before);
});

it('re-encrypts every registered value an old key opens, so each opens with this server’s key alone', function (): void {
    $before = SealedSecrets::stored();

    $result = Reencrypt::asOperator($this->old->keys());
    $after = SealedSecrets::stored();

    expect([$result->rewritten(), $result->current(), $result->unopenedCount()])->toBe([4, 0, 0])
        ->and($result->isComplete())->toBeTrue()
        ->and(array_keys($after))->toBe(array_keys($before));

    foreach ($after as $name => $stored) {
        expect($stored)->not->toBe($before[$name], $name)
            ->and(SealedSecrets::underServerKeyAlone($stored))->not->toBeNull($name)
            ->and(fn () => $this->old->encrypter->decrypt($stored, false))->toThrow(DecryptException::class);
    }
});

it('keeps what each value holds byte for byte, so each keeps its own form', function (): void {
    $before = array_map(fn (string $stored): string => (string) $this->old->encrypter->decrypt($stored, false), SealedSecrets::stored());

    Reencrypt::asOperator($this->old->keys());
    $after = array_map(fn (string $stored): ?string => SealedSecrets::underServerKeyAlone($stored), SealedSecrets::stored());

    expect($after)->toBe($before)
        ->and($after['users.two_factor_secret:'.$this->person->id])->toBe('s:16:"JBSWY3DPEHPK3PXP";')
        ->and($after['settings.secrets:backups'])->toBe('{"machine_key":"'.str_repeat('b', 64).'"}');
});

it('leaves a value already under this server’s key exactly as it was, so a second run rewrites nothing', function (): void {
    Reencrypt::asOperator($this->old->keys());
    $first = SealedSecrets::stored();

    $again = Reencrypt::asOperator($this->old->keys());
    $own = Reencrypt::asOperator(OldKeys::fromText((string) config('app.key')));

    expect(SealedSecrets::stored())->toBe($first)
        ->and([$again->rewritten(), $again->current()])->toBe([0, 4])
        ->and([$own->rewritten(), $own->current()])->toBe([0, 4]);
});

it('opens values under a backup’s previous keys, and under this server’s APP_PREVIOUS_KEYS', function (): void {
    $older = OldKey::make();
    $serverPrevious = OldKey::make();
    $other = User::factory()->create();
    SealedSecrets::twoFactor($other, $older->encrypter, 'KRUGS4ZANFZSA5DI', ['EEEEEEEEEE-FFFFFFFFFF']);
    SealedSecrets::settings('ai', ['anthropic_key' => 'sk-test'], $serverPrevious->encrypter);
    SealedSecrets::serverPreviousKeys([$serverPrevious->typed]);

    $keys = OldKeys::fromAppKeyJson((string) json_encode(['key' => $this->old->typed, 'previous_keys' => [$older->typed]]));
    $result = Reencrypt::asOperator($keys);

    expect([$result->rewritten(), $result->unopenedCount()])->toBe([7, 0]);

    foreach (SealedSecrets::stored() as $name => $stored) {
        expect(SealedSecrets::underServerKeyAlone($stored))->not->toBeNull($name);
    }
});

it('reports a value no key opens by its row, leaves it as it was, and still re-encrypts the rest', function (): void {
    $lost = OldKey::make();
    $stranger = User::factory()->create();
    SealedSecrets::twoFactor($stranger, $lost->encrypter, 'MFRGGZDFMZTWQ2LK', ['GGGGGGGGGG-HHHHHHHHHH']);
    $sealed = DB::table('users')->where('id', $stranger->id)->first(['two_factor_secret', 'two_factor_recovery_codes']);

    $result = Reencrypt::asOperator($this->old->keys());
    $secrets = ($this->column)($result, 'users.two_factor_secret');

    expect([$result->rewritten(), $result->unopenedCount()])->toBe([4, 2])
        ->and($result->isComplete())->toBeFalse()
        ->and($result->touchesTwoFactor())->toBeTrue()
        ->and($secrets->unopened)->toBe([(string) $stranger->id])
        ->and($secrets->line())->toBe("Two-factor secrets: 1 re-encrypted, 1 opens with no key given (person {$stranger->id})")
        ->and(DB::table('users')->where('id', $stranger->id)->first(['two_factor_secret', 'two_factor_recovery_codes']))->toEqual($sealed)
        ->and(print_r($result, true))->not->toContain('MFRGGZDFMZTWQ2LK')->not->toContain('GGGGGGGGGG');
});

it('counts a value that isn’t encrypted at all as one no key opens', function (): void {
    DB::table('backup_destinations')->where('id', $this->place->id)->update(['credentials' => '{"access_key_id": "typed by hand"}']);

    $result = Reencrypt::asOperator($this->old->keys());
    $places = ($this->column)($result, 'backup_destinations.credentials');

    expect($places->unopened)->toBe([$this->place->id])
        ->and($places->line())->toBe("Backup places’ credentials: 1 opens with no key given (backup place {$this->place->id})")
        ->and(DB::table('backup_destinations')->where('id', $this->place->id)->value('credentials'))->toBe('{"access_key_id": "typed by hand"}');
});

it('works through more rows than one page holds', function (): void {
    foreach (User::factory()->count(4)->create() as $index => $person) {
        SealedSecrets::twoFactor($person, $this->old->encrypter, 'JBSWY3DPEHPK3PX'.chr(65 + $index), ['IIIIIIIIII-JJJJJJJJJJ']);
    }

    $result = Reencrypt::asOperator($this->old->keys(), chunk: 2);

    expect([$result->rewritten(), $result->unopenedCount()])->toBe([12, 0])
        ->and(($this->column)($result, 'users.two_factor_recovery_codes')->rewritten)->toBe(5);

    foreach (SealedSecrets::stored() as $name => $stored) {
        expect(SealedSecrets::underServerKeyAlone($stored))->not->toBeNull($name);
    }
});

it('changes nothing when it fails half way', function (): void {
    $second = User::factory()->create();
    SealedSecrets::twoFactor($second, $this->old->encrypter, 'KRUGS4ZANFZSA5DI', ['KKKKKKKKKK-LLLLLLLLLL']);
    $before = [SealedSecrets::stored(), DB::table('security_events')->count()];

    // The second person's recovery codes refuse to be written. By then the
    // settings, both secrets and the first person's codes are rewritten.
    DB::unprepared(sprintf(<<<'SQL'
        CREATE FUNCTION reencrypt_test_refuse() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF NEW.id = %d THEN
                RAISE EXCEPTION 'a failure half way';
            END IF;
            RETURN NEW;
        END
        $$;
        CREATE TRIGGER reencrypt_test_refuse BEFORE UPDATE OF two_factor_recovery_codes ON users
            FOR EACH ROW EXECUTE FUNCTION reencrypt_test_refuse();
        SQL, $second->id));

    expect(fn () => Reencrypt::asOperator($this->old->keys()))->toThrow(QueryException::class, 'a failure half way')
        ->and([SealedSecrets::stored(), DB::table('security_events')->count()])->toBe($before);
});

it('keeps a value someone wrote meanwhile, rather than writing over it', function (): void {
    $theirs = Crypt::encrypt('NBSWY3DPO5XXE3DE');
    $wrote = false;

    // Just before the run writes this person's secret, someone else does,
    // under this server's key, as Fortify would.
    DB::connection()->beforeExecuting(function (string $query) use (&$wrote, $theirs): void {
        if (! $wrote && str_starts_with($query, 'update "users" set "two_factor_secret"')) {
            $wrote = true;
            DB::table('users')->where('id', $this->person->id)->update(['two_factor_secret' => $theirs]);
        }
    });

    $result = Reencrypt::asOperator($this->old->keys());
    $secrets = ($this->column)($result, 'users.two_factor_secret');

    expect($wrote)->toBeTrue()
        ->and([$secrets->rewritten, $secrets->current])->toBe([0, 1])
        ->and(DB::table('users')->where('id', $this->person->id)->value('two_factor_secret'))->toBe($theirs);
});

it('judges a value written meanwhile afresh: rewrites one an old key opens, lists one no key opens', function (Closure $theirs, array $counts, bool $opensAlone): void {
    $written = $theirs($this->old->encrypter);
    $wrote = false;

    DB::connection()->beforeExecuting(function (string $query) use (&$wrote, $written): void {
        if (! $wrote && str_starts_with($query, 'update "users" set "two_factor_secret"')) {
            $wrote = true;
            DB::table('users')->where('id', $this->person->id)->update(['two_factor_secret' => $written]);
        }
    });

    $result = Reencrypt::asOperator($this->old->keys());
    $secrets = ($this->column)($result, 'users.two_factor_secret');
    $stored = (string) DB::table('users')->where('id', $this->person->id)->value('two_factor_secret');

    expect($wrote)->toBeTrue()
        ->and([$secrets->rewritten, $secrets->current, count($secrets->unopened)])->toBe($counts)
        ->and(SealedSecrets::underServerKeyAlone($stored) === 's:16:"NBSWY3DPO5XXE3DE";')->toBe($opensAlone)
        ->and($opensAlone || $stored === $written)->toBeTrue();
})->with([
    'under the old key' => [fn (Encrypter $old): string => $old->encrypt('NBSWY3DPO5XXE3DE'), [1, 0, 0], true],
    'under a key nobody gave' => [fn (Encrypter $old): string => OldKey::make()->encrypter->encrypt('NBSWY3DPO5XXE3DE'), [0, 0, 1], false],
]);

it('refuses to carry bytes that aren’t in their column’s form, listing them and leaving them as they were', function (string $column, Closure $planted): void {
    [$table, $field] = explode('.', $column);
    [$keyColumn, $key] = $table === 'users' ? ['id', (string) $this->person->id] : ['group_key', BackupSettings::GROUP];
    $bytes = $planted($this->old->encrypter);
    DB::table($table)->where($keyColumn, $key)->update([$field => $bytes]);

    $result = Reencrypt::asOperator($this->old->keys());

    expect(($this->column)($result, $column)->unopened)->toBe([$key])
        ->and(DB::table($table)->where($keyColumn, $key)->value($field))->toBe($bytes)
        ->and($result->rewritten())->toBe(3);
})->with([
    // encrypt() serializes: this is serialize(new stdClass), sealed.
    'a serialized object where Fortify keeps a string' => ['users.two_factor_secret', fn (Encrypter $old): string => $old->encrypt(new stdClass)],
    'a serialized string whose length lies' => ['users.two_factor_secret', fn (Encrypter $old): string => $old->encryptString('s:99:"short";')],
    'text that isn’t JSON where a cast keeps JSON' => ['settings.secrets', fn (Encrypter $old): string => $old->encryptString('machine_key=abc')],
]);

it('records one security event for the run, with its counts and nothing else', function (): void {
    $before = DB::table('security_events')->count();

    Reencrypt::asOperator($this->old->keys());
    $event = DB::table('security_events')->orderByDesc('id')->first();

    expect(DB::table('security_events')->count())->toBe($before + 1)
        ->and($event->type)->toBe('secrets.reencrypted')
        ->and($event->user_id)->toBeNull()
        ->and($event->counted)->toBeFalse()
        ->and(json_decode($event->context, true))->toEqual(['rewritten' => 4, 'current' => 0, 'unopened' => 0]);
});

it('refuses old keys that don’t fit the cipher, writing nothing', function (): void {
    $before = [SealedSecrets::stored(), DB::table('security_events')->count()];

    expect(fn () => Reencrypt::asOperator(OldKeys::fromText('short-raw-key')))
        ->toThrow(ValidationException::class, 'That key doesn’t fit this server’s cipher, AES-256-CBC.')
        ->and([SealedSecrets::stored(), DB::table('security_events')->count()])->toBe($before);
});
