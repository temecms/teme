<?php

declare(strict_types=1);

use App\Domain\Settings\Events\SettingsChanged;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Domain\Settings\Models\SettingsChange;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\Operations\Input\TranslateSettingsInput;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\TranslateSettings;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\TranslatableSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Languages\Languages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    siteSetLanguage($this->admin, 'en-GB');
    Languages::set(['fr', 'es'], hidden: ['es']);
    updateSettingsTo($this->admin, 'general', ['tagline' => 'Notes on plants']);
});

/**
 * Sets a group's default-language values through UpdateSettings.
 *
 * @param  array<string, mixed>  $values
 */
function updateSettingsTo(User $actor, string $group, array $values): void
{
    app(UpdateSettings::class)->execute(new UpdateSettingsInput(
        $group, $values, [], [], tsVersion($group), (string) $actor->id,
    ));
}

function tsVersion(string $group): int
{
    return (int) DB::table('settings')->where('group_key', $group)->value('version');
}

/**
 * @param  array<string, mixed>  $values
 * @param  list<string>  $reset
 *
 * @return array{group: string, language: string, version: int}
 */
function translateSettings(User $actor, string $group, string $language, array $values, array $reset = [], ?int $version = null): array
{
    return app(TranslateSettings::class)->execute(new TranslateSettingsInput(
        $group, $language, $values, $reset, $version ?? tsVersion($group), (string) $actor->id,
    ));
}

/**
 * @return array<string, mixed>
 */
function tsWords(string $group, string $language): array
{
    return SettingsGroupRecord::query()->find($group)?->translations[$language] ?? [];
}

it('saves French words for a group and leaves the default\'s as they were', function (): void {
    $result = translateSettings($this->admin, 'general', 'fr', ['site_title' => 'Notes de jardin', 'tagline' => 'Des notes sur les plantes']);

    expect($result['language'])->toBe('fr')
        ->and(tsWords('general', 'fr'))->toEqual(['site_title' => 'Notes de jardin', 'tagline' => 'Des notes sur les plantes'])
        ->and(app(SettingsReader::class)->string('general', 'tagline'))->toBe('Notes on plants')
        ->and(SettingsGroupRecord::query()->find('general')->overrides['tagline'])->toBe('Notes on plants');
});

it('resets a French word to the default\'s', function (): void {
    translateSettings($this->admin, 'general', 'fr', ['site_title' => 'Notes de jardin', 'tagline' => 'Des notes']);
    translateSettings($this->admin, 'general', 'fr', [], ['tagline']);

    expect(tsWords('general', 'fr'))->toEqual(['site_title' => 'Notes de jardin']);

    // An empty word is the same as none: the default's shows.
    translateSettings($this->admin, 'general', 'fr', ['site_title' => '']);

    expect(SettingsGroupRecord::query()->find('general')->translations)->toBeNull();
});

it('refuses a setting with no words per language', function (): void {
    $before = DB::table('settings')->where('group_key', 'general')->first();

    expect(fn () => translateSettings($this->admin, 'general', 'fr', ['timezone' => 'Europe/Paris']))
        ->toThrow(ValidationException::class, 'This setting has no words per language.');

    expect(fn () => translateSettings($this->admin, 'seo', 'fr', ['title_separator' => '|']))
        ->toThrow(ValidationException::class, 'This setting has no words per language.');

    expect(DB::table('settings')->where('group_key', 'general')->first())->toEqual($before);
});

it('marks the site title, the tagline, the title templates and the menu slots as having words per language, and nothing else', function (): void {
    expect(TranslatableSettings::has('general', 'site_title'))->toBeTrue()
        ->and(TranslatableSettings::has('general', 'tagline'))->toBeTrue()
        ->and(TranslatableSettings::has('general', 'timezone'))->toBeFalse()
        ->and(TranslatableSettings::has('general', 'language'))->toBeFalse()
        ->and(TranslatableSettings::has('seo', 'title_home'))->toBeTrue()
        ->and(TranslatableSettings::has('seo', 'title_type_post'))->toBeTrue()
        ->and(TranslatableSettings::has('seo', 'title_archive_post'))->toBeTrue()
        ->and(TranslatableSettings::has('seo', 'title_taxonomy_category'))->toBeTrue()
        ->and(TranslatableSettings::has('seo', 'title_not_found'))->toBeTrue()
        ->and(TranslatableSettings::has('seo', 'title_separator'))->toBeFalse()
        ->and(TranslatableSettings::has('seo', 'hide_type_post'))->toBeFalse()
        ->and(TranslatableSettings::has('theme.default', 'nav_primary'))->toBeTrue()
        ->and(TranslatableSettings::has('theme.default', 'accent'))->toBeFalse()
        ->and(TranslatableSettings::has('reading', 'posts_per_page'))->toBeFalse();
});

it('refuses a language that is not one of the other languages', function (string $language): void {
    expect(fn () => translateSettings($this->admin, 'general', $language, ['tagline' => 'Notas']))
        ->toThrow(ValidationException::class);

    expect(SettingsGroupRecord::query()->find('general')->translations)->toBeNull();
})->with(['the default' => 'en-GB', 'not added' => 'it', 'unknown' => 'xx']);

it('names the language it refuses', function (): void {
    try {
        translateSettings($this->admin, 'general', 'it', ['tagline' => 'Note']);
        $this->fail('Refused nothing.');
    } catch (ValidationException $refusal) {
        expect($refusal->errors()['language'])->toBe(["Italian isn't one of the site's other languages."]);
    }
});

it('refuses a form opened on an older version', function (): void {
    expect(fn () => translateSettings($this->admin, 'general', 'fr', ['tagline' => 'Des notes'], version: tsVersion('general') - 1))
        ->toThrow(StaleSettingsException::class);

    expect(SettingsGroupRecord::query()->find('general')->translations)->toBeNull();
});

it('logs the change with its language in each key', function (): void {
    $before = tsVersion('general');
    translateSettings($this->admin, 'general', 'fr', ['tagline' => 'Des notes']);

    $change = SettingsChange::query()->latest('created_at')->latest('id')->first();

    expect(tsVersion('general'))->toBe($before + 1)
        ->and($change->changed_keys)->toBe(['fr.tagline'])
        ->and($change->before)->toEqual([])
        ->and($change->after)->toEqual(['fr.tagline' => 'Des notes'])
        ->and((int) $change->actor_id)->toBe($this->admin->id);
});

it('purges the group\'s pages after commit', function (): void {
    $folder = cacheOn();
    cacheShown(['/fr/reads-settings/' => ['settings'], '/fr/reads-the-design/' => ['theme']]);
    $translate = fn () => translateSettings($this->admin, 'general', 'fr', ['tagline' => 'Des notes']);

    cacheRolledBack($translate);

    expect(cacheKept('/fr/reads-settings/', '/fr/reads-the-design/'))->toHaveCount(2);

    $translate();

    expect(cacheKept('/fr/reads-settings/', '/fr/reads-the-design/'))->toBe(['/fr/reads-the-design/']);

    cacheOff($folder);
});

it('forgets the group\'s words and tells the site after commit, with the language', function (): void {
    Event::fake([SettingsChanged::class]);

    DB::transaction(function (): void {
        translateSettings($this->admin, 'general', 'fr', ['tagline' => 'Des notes']);
        Event::assertNotDispatched(SettingsChanged::class);
    });

    Event::assertDispatched(SettingsChanged::class, fn (SettingsChanged $change): bool => $change->group === 'general'
        && $change->keys === ['tagline']
        && $change->language === 'fr'
        && $change->actorId === (string) $this->admin->id);
});

it('writes nothing when the person lacks the group\'s ability', function (): void {
    $before = [
        'settings' => DB::table('settings')->orderBy('group_key')->get()->all(),
        'changes' => DB::table('settings_changes')->count(),
    ];

    expect(fn () => translateSettings($this->editor, 'general', 'fr', ['tagline' => 'Des notes']))
        ->toThrow(AuthorizationException::class);

    expect([
        'settings' => DB::table('settings')->orderBy('group_key')->get()->all(),
        'changes' => DB::table('settings_changes')->count(),
    ])->toEqual($before);
});
