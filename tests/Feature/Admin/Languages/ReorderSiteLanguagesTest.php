<?php

declare(strict_types=1);

use App\Domain\Languages\Operations\Input\ReorderSiteLanguagesInput;
use App\Domain\Languages\Operations\ReorderSiteLanguages;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Languages\Languages;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    siteSetLanguage($this->admin, 'en-GB');
    Languages::set(['fr', 'es', 'de'], prefixes: ['es' => 'espanol'], hidden: ['es']);
});

/**
 * The languages group's version now, as a form opened now carries it.
 */
function reorderVersion(): int
{
    return (int) DB::table('settings')->where('group_key', SiteLanguages::GROUP)->value('version');
}

/**
 * Reorders the site's other languages as this person.
 *
 * @param  list<string>  $tags
 */
function reorderAs(User $actor, array $tags, ?int $version = null): void
{
    app(ReorderSiteLanguages::class)->execute(new ReorderSiteLanguagesInput($tags, $version ?? reorderVersion(), (string) $actor->id));
}

/**
 * What a refused call must leave as it was: the group's row and the log.
 *
 * @return array{settings: mixed, changes: int}
 */
function reorderWritten(): array
{
    return [
        'settings' => DB::table('settings')->where('group_key', SiteLanguages::GROUP)->first(),
        'changes' => DB::table('settings_changes')->count(),
    ];
}

it('reorders the other languages, keeping each one\'s prefix and whether it is shown, and logs one settings change', function (): void {
    $changes = DB::table('settings_changes')->count();

    reorderAs($this->admin, ['de', 'fr', 'es']);
    app()->forgetScopedInstances();
    $languages = app(SiteLanguages::class);

    expect($languages->others())->toBe(['de', 'fr', 'es'])
        ->and($languages->all())->toBe(['en-GB', 'de', 'fr', 'es'])
        ->and($languages->prefix('es'))->toBe('espanol')
        ->and($languages->isShown('es'))->toBeFalse()
        ->and($languages->isShown('fr'))->toBeTrue()
        ->and(DB::table('settings_changes')->count())->toBe($changes + 1)
        ->and(json_decode((string) DB::table('settings_changes')->latest('id')->value('changed_keys'), true))->toContain('prefixes');
});

it('refuses a set that isn\'t exactly the site\'s other languages, writing nothing', function (array $tags): void {
    $before = reorderWritten();

    expect(fn () => reorderAs($this->admin, $tags))->toThrow(ValidationException::class, 'Put each of the site\'s other languages in the order once.')
        ->and(reorderWritten())->toEqual($before);
})->with([
    'one missing' => [['de', 'fr']],
    'one more' => [['de', 'fr', 'es', 'it']],
    'a repeat' => [['de', 'fr', 'fr']],
    'the default' => [['en-GB', 'de', 'fr', 'es']],
    'none' => [[]],
]);

it('refuses an order made on an older version of the languages, writing nothing', function (): void {
    $stale = reorderVersion();
    Languages::set(['fr', 'es', 'de'], prefixes: ['es' => 'espanol'], hidden: []);
    $before = reorderWritten();

    expect(fn () => reorderAs($this->admin, ['de', 'fr', 'es'], $stale))->toThrow(StaleSettingsException::class)
        ->and(reorderWritten())->toEqual($before);
});

it('refuses an editor, who may not manage the General settings, writing nothing', function (): void {
    $before = reorderWritten();

    expect(fn () => reorderAs($this->editor, ['de', 'fr', 'es']))->toThrow(AuthorizationException::class)
        ->and(reorderWritten())->toEqual($before);
});

it('purges the pages that draw the language switcher after commit', function (): void {
    $site = LanguageSite::build();
    $folder = cacheOn();

    try {
        addrGet('/fr/bonjour/')->assertOk();
        expect(cacheEntry('/fr/bonjour/'))->not->toBeNull();

        reorderAs($site->admin, ['es', 'fr']);

        expect(cacheEntry('/fr/bonjour/'))->toBeNull();
    } finally {
        cacheOff($folder);
    }
});
