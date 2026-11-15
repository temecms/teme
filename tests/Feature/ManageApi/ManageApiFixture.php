<?php

declare(strict_types=1);

namespace Tests\Feature\ManageApi;

use App\Domain\ManageApi\ManageApiSettings;
use App\Domain\ManageApi\Operations\CreateApiKey;
use App\Domain\ManageApi\Operations\Input\CreateApiKeyInput;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * The management API as an administrator sets it up: the switch and the
 * limit through UpdateSettings, a key through CreateApiKey, both elevated.
 */
final class ManageApiFixture
{
    /**
     * Turns the management API on, with this many requests a minute per key.
     */
    public static function on(int $perMinute = 60): void
    {
        self::set([ManageApiSettings::ENABLED => true, ManageApiSettings::PER_MINUTE => $perMinute]);
    }

    /**
     * Turns the management API off.
     */
    public static function off(): void
    {
        self::set([ManageApiSettings::ENABLED => false]);
    }

    /**
     * A key's secret, for this person, with these abilities.
     *
     * @param  list<string>  $abilities
     */
    public static function key(User $person, array $abilities = ['site.read'], string $name = 'Importer', ?string $expiresAt = null): string
    {
        $admin = self::admin();

        return scElevated(fn () => app(CreateApiKey::class)->execute(
            new CreateApiKeyInput($name, (string) $person->id, $abilities, $expiresAt, (string) $admin->id),
        ))->secret;
    }

    /**
     * The headers that sign in with this secret.
     *
     * @return array<string, string>
     */
    public static function headers(string $secret): array
    {
        return ['Authorization' => 'Bearer '.$secret, 'Accept' => 'application/json'];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function set(array $values): void
    {
        $admin = self::admin();
        $version = (int) DB::table('settings')->where('group_key', ManageApiSettings::KEY)->value('version');

        scElevated(fn (): mixed => app(UpdateSettings::class)->execute(new UpdateSettingsInput(ManageApiSettings::KEY, $values, [], [], $version, (string) $admin->id)));
    }

    private static function admin(): User
    {
        if (! Role::query()->where('name', 'administrator')->exists()) {
            test()->seed(RoleSeeder::class);
        }

        return User::factory()->create()->assignRole('administrator');
    }
}
