<?php

declare(strict_types=1);

use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Contents\Models\Content;
use App\Domain\Languages\Operations\AddSiteLanguage;
use App\Domain\Languages\Operations\ChangeSiteLanguage;
use App\Domain\Languages\Operations\Input\AddSiteLanguageInput;
use App\Domain\Languages\Operations\Input\ChangeSiteLanguageInput;
use App\Domain\Languages\Operations\Input\RemoveSiteLanguageInput;
use App\Domain\Languages\Operations\RemoveSiteLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    siteSetLanguage($this->admin, 'en-GB');
});

/**
 * The languages group's version now, as a form opened now carries it.
 */
function slVersion(): int
{
    return (int) DB::table('settings')->where('group_key', SiteLanguages::GROUP)->value('version');
}

/**
 * The site's languages as a fresh request reads them.
 */
function slLanguages(): SiteLanguages
{
    app()->forgetScopedInstances();

    return app(SiteLanguages::class);
}

function slAdd(User $actor, string $tag, string $prefix, bool $shown = true, ?int $version = null): void
{
    app(AddSiteLanguage::class)->execute(new AddSiteLanguageInput($tag, $prefix, $shown, $version ?? slVersion(), (string) $actor->id));
}

function slChange(User $actor, string $tag, string $prefix, bool $shown = true): void
{
    app(ChangeSiteLanguage::class)->execute(new ChangeSiteLanguageInput($tag, $prefix, $shown, slVersion(), (string) $actor->id));
}

function slRemove(User $actor, string $tag): void
{
    app(RemoveSiteLanguage::class)->execute(new RemoveSiteLanguageInput($tag, slVersion(), (string) $actor->id));
}

/**
 * What a refused or unauthorised call must leave as it was.
 *
 * @return array{settings: mixed, changes: int, jobs: int}
 */
function slWritten(): array
{
    return [
        'settings' => DB::table('settings')->where('group_key', SiteLanguages::GROUP)->first(),
        'changes' => DB::table('settings_changes')->count(),
        'jobs' => count(Queue::pushedJobs()),
    ];
}

it('adds French with its prefix, shown, and lists it after the default', function (): void {
    slAdd($this->admin, 'fr', 'fr');
    $languages = slLanguages();

    expect($languages->all())->toBe(['en-GB', 'fr'])
        ->and($languages->prefix('fr'))->toBe('fr')
        ->and($languages->isShown('fr'))->toBeTrue();
});

it('adds a language hidden from readers', function (): void {
    slAdd($this->admin, 'es', 'espanol', shown: false);
    $languages = slLanguages();

    expect($languages->others())->toBe(['es'])
        ->and($languages->prefix('es'))->toBe('espanol')
        ->and($languages->isShown('es'))->toBeFalse();
});

it('refuses a language the site already has, the default, and one it does not know', function (string $tag, string $words): void {
    slAdd($this->admin, 'fr', 'fr');

    expect(fn () => slAdd($this->admin, $tag, 'xx'))->toThrow(ValidationException::class, $words);
})->with([
    'already added' => ['fr', 'French is already one of your site\'s languages.'],
    'the default' => ['en-GB', 'English (United Kingdom) is your site\'s default language.'],
    'unknown' => ['xx-YY', 'Choose a language.'],
]);

it('refuses a prefix that isn\'t lower-case letters, digits and hyphens, or is longer than 20', function (string $prefix): void {
    expect(fn () => slAdd($this->admin, 'fr', $prefix))->toThrow(ValidationException::class, 'Use lower-case letters, digits and hyphens, at most 20.');
})->with(['Fr', 'f r', 'fr-', '-fr', 'fr--ca', 'fr/ca', '', 'abcdefghijklmnopqrstu']);

it('moves a language\'s addresses when its prefix changes, and the old ones redirect', function (): void {
    $site = LanguageSite::build();
    app()->instance(Elevation::class, new DeniedElevation);

    slChange($site->admin, 'fr', 'francais');

    expect(addrCurrent('content', $site->postFr))->toBe('francais/bonjour')
        ->and(addrCurrent('term', $site->newsFr))->toBe('francais/category/actualites')
        ->and(addrCurrent('content', $site->post))->toBe('hello')
        ->and(addrLocation(addrGet('/fr/bonjour/')->assertStatus(301)))->toBe('/francais/bonjour/');

    addrGet('/francais/bonjour/')->assertOk()->assertHeader('Content-Language', 'fr');
});

it('rebuilds only that language\'s addresses when its prefix changes', function (): void {
    $site = LanguageSite::build();
    $french = DB::table('contents')->where('language', 'fr')->count() + DB::table('terms')->where('language', 'fr')->count();
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        if (str_contains($query->sql, 'select "id" from "contents"') || str_contains($query->sql, 'select "id" from "terms"') || str_contains($query->sql, 'from "users"')) {
            $statements[] = $query->sql;
        }
    });

    slChange($site->admin, 'fr', 'francais');

    expect(Cache::get(RecomputeAddresses::PROGRESS))->toMatchArray(['done' => $french, 'total' => $french, 'running' => false])
        ->and(collect($statements)->filter(fn (string $sql): bool => str_contains($sql, 'select "id" from'))->every(fn (string $sql): bool => str_contains($sql, '"language" = ?')))->toBeTrue()
        ->and(collect($statements)->contains(fn (string $sql): bool => str_contains($sql, 'select "id" from "users"')))->toBeFalse();
});

it('shows and hides a language without moving an address', function (): void {
    $site = LanguageSite::build();
    $paths = DB::table('paths')->orderBy('path')->get()->all();
    Queue::fake();

    slChange($site->admin, 'es', 'es', shown: true);
    expect(slLanguages()->isShown('es'))->toBeTrue();
    slChange($site->admin, 'fr', 'fr', shown: false);

    expect(slLanguages()->isShown('fr'))->toBeFalse()
        ->and(DB::table('paths')->orderBy('path')->get()->all())->toEqual($paths)
        ->and(Queue::pushed(RecomputeAddresses::class))->toHaveCount(0);
});

it('shows a language\'s published items the moment it is shown', function (bool $cached): void {
    $site = LanguageSite::build();
    $folder = $cached ? cacheOn() : null;

    addrGet('/es/hola/')->assertNotFound();
    slChange($site->admin, 'es', 'es', shown: true);
    addrGet('/es/hola/')->assertOk()->assertHeader('Content-Language', 'es')->assertSee('Hola, in a few words.');

    slChange($site->admin, 'es', 'es', shown: false);
    addrGet('/es/hola/')->assertNotFound();

    if ($folder !== null) {
        cacheOff($folder);
    }
})->with(['page cache off' => [false], 'page cache on' => [true]]);

it('refuses to remove a language while items or terms are in it, counting them', function (): void {
    $site = LanguageSite::build();
    pageTrash(Content::query()->findOrFail($site->pageFr), $site->editor);

    expect(fn () => slRemove($site->admin, 'fr'))
        ->toThrow(ValidationException::class, '2 posts and pages and 1 term are in French. Move them to another language, or delete them, first.');

    DB::table('terms')->where('language', 'fr')->update(['language' => null, 'translation_group' => null]);
    DB::table('contents')->where('id', $site->postFr)->update(['language' => null, 'translation_group' => null]);

    expect(fn () => slRemove($site->admin, 'fr'))
        ->toThrow(ValidationException::class, '1 post or page is in French. Move them to another language, or delete them, first.');
});

it('removes a language nothing is in', function (): void {
    slAdd($this->admin, 'fr', 'fr');
    slAdd($this->admin, 'de', 'de', shown: false);

    slRemove($this->admin, 'de');
    $languages = slLanguages();

    expect($languages->others())->toBe(['fr'])
        ->and($languages->hidden())->toBe([])
        ->and($languages->byPrefix('de'))->toBeNull();
});

it('refuses a form opened on an older version of the languages', function (): void {
    $version = slVersion();
    slAdd($this->admin, 'fr', 'fr');

    expect(fn () => slAdd($this->admin, 'de', 'de', version: $version))->toThrow(StaleSettingsException::class);
});

it('writes nothing when someone without the General settings ability adds, changes or removes a language', function (Closure $call): void {
    slAdd($this->admin, 'fr', 'fr');
    Queue::fake();
    $before = slWritten();

    expect(fn () => $call($this->editor))->toThrow(AuthorizationException::class)
        ->and(slWritten())->toEqual($before);
})->with([
    'add' => [fn (User $editor) => slAdd($editor, 'de', 'de')],
    'change' => [fn (User $editor) => slChange($editor, 'fr', 'francais')],
    'remove' => [fn (User $editor) => slRemove($editor, 'fr')],
]);

it('writes nothing when a language is refused', function (): void {
    slAdd($this->admin, 'fr', 'fr');
    Queue::fake();
    $before = slWritten();

    expect(fn () => slChange($this->admin, 'fr', 'admin'))->toThrow(ValidationException::class, 'The address /admin/ is used by the site itself.')
        ->and(fn () => slAdd($this->admin, 'de', 'fr'))->toThrow(ValidationException::class, 'Another language already uses /fr/.')
        ->and(fn () => slChange($this->admin, 'de', 'de'))->toThrow(ValidationException::class, 'German isn\'t one of your site\'s languages.')
        ->and(slWritten())->toEqual($before);
});

it('answers each language route for an administrator and refuses an editor', function (string $method, string $uri, array $data): void {
    slAdd($this->admin, 'fr', 'fr');
    $data = [...$data, 'expected_version' => slVersion()];

    $this->actingAs($this->editor)->{$method}($uri, $data)->assertForbidden();
    $this->actingAs($this->admin)->{$method}($uri, $data)->assertRedirect()->assertSessionHasNoErrors();
})->with([
    'store' => ['post', '/admin/settings/languages', ['tag' => 'de', 'prefix' => 'de', 'shown' => true]],
    'update' => ['put', '/admin/settings/languages/fr', ['prefix' => 'fr', 'shown' => false]],
    'destroy' => ['delete', '/admin/settings/languages/fr', []],
]);
