<?php

declare(strict_types=1);

namespace Tests\Fixtures\Api;

use App\Domain\Api\Delivery\DeliverySwitches;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * The API's and llms.txt's switches, set as an administrator sets them:
 * through UpdateSettings on Search and social, with the group's current
 * version, elevated. Kept here, not in tests/Pest.php, which other slices
 * change at the same time.
 */
final class Switches
{
    /**
     * Turns the API on.
     */
    public static function apiOn(): void
    {
        self::set([DeliverySwitches::API => true]);
    }

    /**
     * Turns the API off.
     */
    public static function apiOff(): void
    {
        self::set([DeliverySwitches::API => false]);
    }

    /**
     * Turns llms.txt and the .md pages on.
     */
    public static function llmsOn(): void
    {
        self::set([DeliverySwitches::LLMS => true]);
    }

    /**
     * @param  array<string, bool>  $values
     */
    private static function set(array $values): void
    {
        if (! Role::query()->where('name', 'administrator')->exists()) {
            test()->seed(RoleSeeder::class);
        }

        $admin = User::factory()->create()->assignRole('administrator');
        $version = (int) DB::table('settings')->where('group_key', SearchAndSocial::KEY)->value('version');

        scElevated(fn (): mixed => app(UpdateSettings::class)->execute(new UpdateSettingsInput(SearchAndSocial::KEY, $values, [], [], $version, (string) $admin->id)));
    }
}
