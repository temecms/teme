<?php

declare(strict_types=1);

use App\Domain\Languages\PageLanguage;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Regions\Regions;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Settings\Operations\Input\TranslateSettingsInput;
use App\Domain\Settings\Operations\TranslateSettings;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Settings\SettingsTranslations;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

/**
 * Writes a group's French words through TranslateSettings, at its current version.
 *
 * @param  array<string, mixed>  $values
 */
function wplFrench(User $actor, string $group, array $values): void
{
    $version = (int) DB::table('settings')->where('group_key', $group)->value('version');
    app(TranslateSettings::class)->execute(new TranslateSettingsInput($group, 'fr', $values, [], $version, (string) $actor->id));
}

it('shows a French page the French site title, and the default language\'s page its own', function (): void {
    cmSettings(['site_title' => 'The Harbour', 'tagline' => 'News from the quay'], 'general');
    wplFrench($this->site->admin, 'general', ['site_title' => 'Le Port']);

    expect(headValue(headDom('/fr/bonjour/'), '//head/title'))->toBe('Bonjour – Le Port')
        ->and(headValue(headDom('/hello/'), '//head/title'))->toBe('Hello – The Harbour');
});

it('shows the default language\'s words where French has none', function (): void {
    cmSettings(['site_title' => 'The Harbour', 'tagline' => 'News from the quay'], 'general');
    wplFrench($this->site->admin, 'general', ['site_title' => 'Le Port']);
    app(PageLanguage::class)->use('fr');
    $settings = app(SettingsReader::class);

    expect($settings->string('general', 'site_title'))->toBe('Le Port')
        ->and($settings->string('general', 'tagline'))->toBe('News from the quay');
});

it('fills the French title template on a French page', function (): void {
    wplFrench($this->site->admin, SearchAndSocial::KEY, ['title_type_post' => '{title} — en français']);

    expect(headValue(headDom('/fr/bonjour/'), '//head/title'))->toBe('Bonjour — en français')
        ->and(headValue(headDom('/hello/'), '//head/title'))->toStartWith('Hello –');
});

it('reads translations only on a page in another language', function (): void {
    wplFrench($this->site->admin, 'general', ['site_title' => 'Le Port']);
    $key = SettingsRepository::translationsKey('general');
    Cache::forget($key);

    addrGet('/hello/')->assertOk();

    expect(Cache::has($key))->toBeFalse();

    addrGet('/fr/bonjour/')->assertOk();

    expect(Cache::has($key))->toBeTrue();
});

it('forgets the words when they are written', function (): void {
    wplFrench($this->site->admin, 'general', ['site_title' => 'Le Port']);
    app(PageLanguage::class)->use('fr');

    expect(app(SettingsReader::class)->string('general', 'site_title'))->toBe('Le Port');

    wplFrench($this->site->admin, 'general', ['site_title' => 'Le Havre']);

    expect(app(SettingsReader::class)->string('general', 'site_title'))->toBe('Le Havre');
});

it('gives the screens a group\'s words in a language, the default\'s beside them, and the version', function (): void {
    cmSettings(['site_title' => 'The Harbour'], 'general');
    wplFrench($this->site->admin, 'general', ['site_title' => 'Le Port']);
    $words = app(SettingsTranslations::class)->of('general', 'fr');

    expect($words['values'])->toBe(['site_title' => 'Le Port'])
        ->and($words['defaults'])->toBe(['site_title' => 'The Harbour', 'tagline' => app(SettingsReader::class)->string('general', 'tagline')])
        ->and($words['version'])->toBe((int) DB::table('settings')->where('group_key', 'general')->value('version'));
});

it('draws a slot\'s French menu on a French page, and the default language\'s where French has none', function (): void {
    $english = navCreate($this->site->admin, 'Main');
    navSave($this->site->admin, $english, [['kind' => 'home'], ['kind' => 'url', 'url' => 'https://example.org/en', 'label' => 'In English']]);
    $french = navCreate($this->site->admin, 'Principal');
    navSave($this->site->admin, $french, [['kind' => 'home'], ['kind' => 'url', 'url' => 'https://example.org/fr', 'label' => 'En français']]);
    $bind = fn (string $slot, string $menu, ?string $language = null) => app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $menu, (string) $this->site->admin->id, $language));
    $bind('footer', $english->id);
    $bind('footer', $french->id, 'fr');
    $footer = fn (int $place): string => "(//footer//nav//a)[{$place}]";

    $frenchPage = headDom('/fr/bonjour/');
    $englishPage = headDom('/hello/');

    expect(headValue($frenchPage, $footer(2)))->toBe('En français')
        ->and(headValue($frenchPage, $footer(1).'/@href'))->toBe('/fr/')
        ->and(headValue($englishPage, $footer(2)))->toBe('In English')
        ->and(headValue($englishPage, $footer(1).'/@href'))->toBe('/');

    // French bound only to the footer: the header shows the default language's.
    $bind('primary', $english->id);

    expect((string) addrGet('/fr/bonjour/')->getContent())->toContain('In English');
});

it('draws a region\'s French version on a French page, the default\'s where French has none, and never an unpublished one', function (): void {
    $region = regionCreate($this->site->editor, 'default');
    regionSave($region, $this->site->editor, [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Join the newsletter']]]]);
    listsPublish($region->refresh(), $this->site->editor);

    // Its French version, written as a translation would be, unpublished at first.
    $row = (array) DB::table('contents')->where('id', $region->id)->first();
    $revision = (array) DB::table('content_revisions')->where('id', $row['published_revision_id'])->first();
    $frId = strtolower((string) Str::ulid());
    $frRevision = strtolower((string) Str::ulid());
    DB::table('contents')->insert([...$row, 'id' => $frId, 'language' => 'fr', 'status' => 'draft', 'working_revision_id' => null, 'published_revision_id' => null]);
    DB::table('content_revisions')->insert([...$revision, 'id' => $frRevision, 'content_id' => $frId, 'document' => json_encode(docWith(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Rejoignez la lettre']]]))]);
    DB::table('contents')->where('id', $frId)->update(['published_revision_id' => $frRevision, 'working_revision_id' => $frRevision]);
    app()->forgetInstance(Regions::class);

    expect((string) addrGet('/fr/bonjour/')->assertOk()->getContent())->toContain('Join the newsletter')->not->toContain('Rejoignez la lettre');

    DB::table('contents')->where('id', $frId)->update(['status' => 'published']);
    app()->forgetInstance(Regions::class);

    expect((string) addrGet('/fr/bonjour/')->assertOk()->getContent())->toContain('Rejoignez la lettre')->not->toContain('Join the newsletter')
        ->and((string) addrGet('/hello/')->assertOk()->getContent())->toContain('Join the newsletter')->not->toContain('Rejoignez la lettre');
});
