<?php

declare(strict_types=1);

use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

/*
 * Every word the default theme shows a reader goes through the translator,
 * and the active theme's own word files are read after core's on public
 * pages only (I-3360). Stand-in files hold the words, so the test reads only
 * what it names.
 */

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->scratch = sys_get_temp_dir().'/theme-words-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->scratch.'/core');
});

afterEach(function (): void {
    File::deleteDirectory($this->scratch);
});

/**
 * A stand-in word file for one locale in a folder of the scratch space.
 *
 * @param  array<string, string>  $words
 */
function twWords(string $folder, string $locale, array $words): void
{
    File::ensureDirectoryExists($folder);
    File::put("{$folder}/{$locale}.json", (string) json_encode($words, JSON_UNESCAPED_UNICODE));
}

it('draws the default theme\'s labels through the translator', function (): void {
    // Dutch ships no words, so the stand-in is the only Dutch file read.
    twWords($this->scratch.'/core', 'nl', [
        'We couldn’t find that page' => 'Pagina niet gevonden',
        'It may have moved, or the address may have a typo. Try a search, or start from the home page.' => 'Misschien is ze verplaatst.',
        'Go to the home page' => 'Naar de homepage',
        'Menu' => 'Hoofdmenu',
        'Search' => 'Zoeken',
        'Previous' => 'Vorige',
        'Next' => 'Volgende',
        'Pages' => 'Pagina’s van de lijst',
    ]);
    Lang::addJsonPath($this->scratch.'/core');
    $admin = User::factory()->create()->assignRole('administrator');
    siteSetLanguage($admin, 'nl');
    $version = (int) DB::table('settings')->where('group_key', 'reading')->value('version');
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('reading', ['posts_per_page' => 1], [], [], $version, (string) $admin->id));

    foreach (['Hallo', 'Hoi', 'Goedenavond', 'Dag'] as $title) {
        LanguageSite::version($admin, $title, null, null, [], publish: true);
    }

    $missing = (string) addrGet('/nothing-here/')->assertNotFound()->getContent();
    $listing = (string) addrGet('/page/2/')->assertOk()->getContent();

    expect($missing)->toContain('Pagina niet gevonden')->toContain('Misschien is ze verplaatst.')->toContain('Naar de homepage')
        ->toContain('Hoofdmenu')->toContain('>Zoeken</button>')
        ->not->toContain('We couldn’t find that page')
        ->and($listing)->toContain('>Vorige</a>')->toContain('aria-label="Pagina’s van de lijst"');
});

it('loads the active theme\'s words after core\'s, so a theme may word core\'s keys its own way', function (): void {
    File::copyDirectory(base_path('tests/Fixtures/themes/good'), $this->scratch.'/themes/good');
    twWords($this->scratch.'/themes/good/lang', 'fr', ['Skip to content' => 'Passer au contenu']);
    twWords($this->scratch.'/core', 'fr', ['Skip to content' => 'Aller au contenu']);
    Lang::addJsonPath($this->scratch.'/core');
    config(['teme.theme_paths' => [$this->scratch.'/themes/good']]);
    $admin = User::factory()->create()->assignRole('administrator');
    themeActivate('good');
    siteSetLanguage($admin, 'fr');

    expect((string) addrGet('/nothing-here/')->assertNotFound()->getContent())->toContain('Passer au contenu')->not->toContain('Aller au contenu');
});

it('loads no theme\'s words in the admin', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($admin)->get('/admin')->assertOk();

    expect(app('translator')->getLoader()->paths())->not->toContain(base_path('themes/default/lang'));
});

it('reads English where a language ships no words', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    siteSetLanguage($admin, 'nl');
    LanguageSite::version($admin, 'Hallo', null, null, [], publish: true);

    $html = (string) addrGet('/hallo/')->assertOk()->getContent();

    expect(headValue(dtDom($html), '/html/@lang'))->toBe('nl')
        ->and($html)->toContain('Leave a comment')->toContain('Post comment');
});
