<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Contents\Models\Content;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

it('lists the latest French items at /fr/ when the home page is the latest posts', function (): void {
    addrGet('/fr/')->assertOk()
        ->assertHeader('Content-Language', 'fr')
        ->assertSee('Bonjour')
        ->assertDontSee('Hello')
        ->assertDontSee('Alone');

    addrGet('/')->assertOk()
        ->assertHeader('Content-Language', 'en-GB')
        ->assertSee('Hello')
        ->assertDontSee('Bonjour');
});

it('shows the home page\'s French version at /fr/ and redirects its own address there', function (): void {
    addrChange($this->site->admin, ['patterns' => ['page' => '/{parent}/{slug}/'], 'frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $this->site->page]);
    listsPublish(Content::query()->findOrFail($this->site->pageFr), $this->site->editor);
    DB::table('paths')->insert(['path' => 'fr/a-propos', 'target_type' => 'content', 'target_id' => $this->site->pageFr, 'is_current' => false, 'created_at' => now()]);

    addrGet('/fr/')->assertOk()
        ->assertHeader('Content-Language', 'fr')
        ->assertSee('À propos, in a few words.');

    expect(addrLocation(addrGet('/fr/a-propos/')->assertStatus(301)))->toBe('/fr/');
});

it('lists the latest French items at /fr/ while the home page has no live French version', function (): void {
    addrChange($this->site->admin, ['patterns' => ['page' => '/{parent}/{slug}/'], 'frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $this->site->page]);

    addrGet('/fr/')->assertOk()
        ->assertHeader('Content-Language', 'fr')
        ->assertSee('Bonjour')
        ->assertDontSee('About, in a few words.');
});

it('lists the latest French items at the posts page\'s French version', function (): void {
    $blog = LanguageSite::version($this->site->editor, 'Blog', null, null, [], publish: true, type: 'page');
    $journal = LanguageSite::version($this->site->editor, 'Journal', 'fr', null, [], publish: true, type: 'page');
    DB::table('contents')->whereIn('id', [$blog, $journal])->update(['translation_group' => strtolower((string) Str::ulid())]);
    addrChange($this->site->admin, ['patterns' => ['page' => '/{parent}/{slug}/'], 'frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $this->site->page, 'postsPageId' => $blog]);

    addrGet('/fr/journal/')->assertOk()
        ->assertHeader('Content-Language', 'fr')
        ->assertSee('Bonjour')
        ->assertDontSee('Hello');

    addrGet('/blog/')->assertOk()
        ->assertSee('Hello')
        ->assertDontSee('Bonjour');
});

it('serves /fr/page/2/ and redirects /fr/page/1/ to /fr/', function (): void {
    $version = (int) DB::table('settings')->where('group_key', 'reading')->value('version');
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('reading', ['posts_per_page' => 1], [], [], $version, (string) $this->site->admin->id));

    foreach (['Salut', 'Coucou', 'Bonsoir'] as $title) {
        LanguageSite::version($this->site->editor, $title, 'fr', null, [], publish: true);
    }

    addrGet('/fr/page/2/')->assertOk()->assertHeader('Content-Language', 'fr');

    expect(addrLocation(addrGet('/fr/page/1/')->assertStatus(301)))->toBe('/fr/');
});
