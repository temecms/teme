<?php

declare(strict_types=1);

namespace Tests\Fixtures\Security;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Security\EncryptedValues;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Backups\BackupRecords;

/**
 * Values written straight into their columns under a given encrypter, each
 * in its column's own form, as their real writers would have written them
 * on a server with that key; and what the columns hold afterwards.
 */
final class SealedSecrets
{
    /**
     * Fortify's form: encrypt(), serialized inside.
     *
     * @param  list<string>  $codes
     */
    public static function twoFactor(User $person, Encrypter $under, string $secret, array $codes): void
    {
        DB::table('users')->where('id', $person->id)->update([
            'two_factor_secret' => $under->encrypt($secret),
            'two_factor_recovery_codes' => $under->encrypt((string) json_encode($codes)),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * The encrypted:array cast's form: JSON, through encryptString().
     *
     * @param  array<string, string>  $secrets
     */
    public static function settings(string $group, array $secrets, Encrypter $under): void
    {
        DB::table('settings')->updateOrInsert(
            ['group_key' => $group],
            ['secrets' => $under->encryptString((string) json_encode($secrets))],
        );
    }

    /**
     * An S3 place whose credentials were sealed under $under.
     *
     * @param  array<string, string>  $credentials
     */
    public static function place(Encrypter $under, array $credentials): BackupDestination
    {
        $place = BackupRecords::destination(['kind' => BackupDestinationKind::S3]);

        DB::table('backup_destinations')->where('id', $place->id)
            ->update(['credentials' => $under->encryptString((string) json_encode($credentials))]);

        return $place;
    }

    /**
     * Every registered column's stored values, keyed `table.column:key`.
     *
     * @return array<string, string>
     */
    public static function stored(): array
    {
        $stored = [];

        foreach (app(EncryptedValues::class)->all() as $column) {
            $rows = DB::table($column->table)->whereNotNull($column->column)->orderBy($column->key)->get([$column->key, $column->column]);

            foreach ($rows as $row) {
                $stored[$column->name().':'.$row->{$column->key}] = $row->{$column->column};
            }
        }

        return $stored;
    }

    /**
     * What a stored value holds, opened with APP_KEY alone (never the
     * previous keys); null when it doesn't open.
     */
    public static function underServerKeyAlone(string $stored): ?string
    {
        try {
            return (string) (new Encrypter(Crypt::getKey(), (string) config('app.cipher')))->decrypt($stored, false);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Gives this server APP_PREVIOUS_KEYS, as a restart with them set would.
     *
     * @param  list<string>  $typed
     */
    public static function serverPreviousKeys(array $typed): void
    {
        config(['app.previous_keys' => $typed]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }
}
