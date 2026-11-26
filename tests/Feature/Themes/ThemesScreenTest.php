<?php

declare(strict_types=1);

use App\Domain\Design\Operations\CreateDesignPreview;
use App\Domain\Design\Operations\Input\CreateDesignPreviewInput;
use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Design\Operations\SaveDesignDraft;
use App\Domain\Navigation\Queries\AdminNavigation;
use App\Domain\Settings\Queries\SettingsNavigation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    // Fixture Good declares a region, settings and two menu places; Fixture
    // Plain declares none; Fixture Broken asks for another Teme. Teme Default,
    // a Composer package, is always installed too.
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/plain'), base_path('tests/Fixtures/themes/broken')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('draws Site › Themes at its own address for whoever may change the design, and refuses it to anyone else', function (): void {
    $this->actingAs($this->admin)->get('/admin/themes')->assertInertia(fn (Assert $page) => $page
        ->component('themes/index')
        ->where('themeGroup', 'theme.good')
        ->has('slots', 2));

    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->get('/admin/themes')->assertForbidden();
});

it('sends the old address, and each theme group\'s own, to Themes', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/design')->assertRedirect('/admin/themes');
    $this->actingAs($this->admin)->get('/admin/settings/theme.good')->assertRedirect('/admin/themes');
});

it('lists Themes under Site for whoever may change the design, and Settings no longer lists Design', function (): void {
    $designer = User::factory()->create();
    $designer->givePermissionTo('settings.design.manage');

    $nav = app(AdminNavigation::class)->forUser($designer->id);

    expect(array_column($nav, 'key'))->toBe(['dashboard', 'themes'])
        ->and($nav[1])->toMatchArray(['label' => 'Themes', 'icon' => 'brush', 'section' => 'site', 'href' => '/admin/themes'])
        ->and(app(SettingsNavigation::class)->forUser($designer->id))->toBe([]);

    $this->actingAs($designer)->get('/admin/settings/design')->assertRedirect('/admin/themes');
});

it('leads the public preview bar back to Themes', function (): void {
    $link = app(CreateDesignPreview::class)->execute(new CreateDesignPreviewInput('/', (string) $this->admin->id));

    addrGet($link)->assertOk()
        ->assertSee('Back to Themes')
        ->assertSee('href="'.route('themes.index').'"', false);
});

it('names each theme\'s state, the theme in use first, and a previewed theme as Previewing', function (): void {
    $states = fn (): array => collect($this->actingAs($this->admin)->get('/admin/themes')->viewData('page')['props']['themes'])->pluck('state', 'code')->all();

    // Teme Default, a Composer package, is found first and listed after the theme in use.
    expect($states())->toBe(['good' => 'active', 'default' => 'available', 'plain' => 'available', 'broken' => 'incompatible']);

    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['theme' => 'plain']], (string) $this->admin->id));

    expect($states()['plain'])->toBe('previewing');
});

it('lists a theme with any other error as Broken, with every error, and with no theme Active when the chosen one can\'t be used', function (): void {
    $folder = sys_get_temp_dir().'/teme-themes-'.getmypid().'-'.uniqid().'/cracked';
    mkdir($folder, 0777, true);
    file_put_contents($folder.'/teme-theme.json', (string) json_encode(pageManifest(['code' => 'cracked', 'name' => 'Cracked'])));
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), $folder]]);
    themeActivate('cracked');

    $this->actingAs($this->admin)->get('/admin/themes')->assertInertia(fn (Assert $page) => $page
        ->where('themes.0.code', 'cracked')
        ->where('themes.0.state', 'broken')
        ->where('themes.0.live', true)
        ->where('themes.0.errors', fn ($errors): bool => collect($errors)->contains('The theme has no views/templates/index.blade.php, which every theme needs.'))
        ->where('themes', fn ($themes): bool => ! collect($themes)->contains('state', 'active'))
        ->where('preview', null));

    File::deleteDirectory(dirname($folder));
});

it('describes the theme being previewed, and says when its own settings hold changes', function (): void {
    themeActivate('plain');

    $this->actingAs($this->admin)->get('/admin/themes')->assertInertia(fn (Assert $page) => $page->where('preview', null));

    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['theme' => 'good']], (string) $this->admin->id));

    $this->actingAs($this->admin)->get('/admin/themes')->assertInertia(fn (Assert $page) => $page
        ->where('preview', ['code' => 'good', 'name' => 'Fixture Good', 'version' => '0.1.0', 'changed' => false])
        ->where('themeGroup', 'theme.good'));

    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['feed_layout' => 'grid']], (string) $this->admin->id));

    $this->actingAs($this->admin)->get('/admin/themes')->assertInertia(fn (Assert $page) => $page->where('preview.changed', true));
});

it('gives Themes the author, authorUrl and homepage when the manifest sets them', function (): void {
    $folder = sys_get_temp_dir().'/teme-themes-'.getmypid().'-'.uniqid().'/linked';
    mkdir($folder.'/views/templates', 0777, true);
    file_put_contents($folder.'/views/templates/index.blade.php', '<html></html>');
    file_put_contents($folder.'/teme-theme.json', (string) json_encode(pageManifest([
        'code' => 'linked',
        'name' => 'Linked',
        'author' => 'Ada Example',
        'author_url' => 'https://example.com/ada',
        'homepage' => 'https://example.com/theme',
    ])));
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), $folder]]);

    $themes = collect($this->actingAs($this->admin)->get('/admin/themes')->assertOk()->viewData('page')['props']['themes'])->keyBy('code');

    expect($themes['linked'])->toMatchArray([
        'author' => 'Ada Example',
        'authorUrl' => 'https://example.com/ada',
        'homepage' => 'https://example.com/theme',
    ])->and($themes['good'])->toMatchArray([
        'author' => '',
        'authorUrl' => '',
        'homepage' => '',
    ]);

    File::deleteDirectory(dirname($folder));
});

it('gives Themes the regions of the theme in use, each with its cards, limit and state, and what is unplaced', function (): void {
    regionCreate($this->admin);

    $this->actingAs($this->admin)->get('/admin/themes')->assertInertia(fn (Assert $page) => $page
        ->where('regions.theme', ['code' => 'good', 'name' => 'Fixture Good'])
        ->where('regions.regions.0.key', 'after-content')
        ->where('regions.regions.0.cardLabels', ['Callout', 'Button'])
        ->where('regions.regions.0.limit', 'At most 5 blocks')
        ->where('regions.regions.0.state', 'draft')
        ->where('regions.others', [])
        ->where('previewRegions', null)
        ->where('canEditRegions', true));
});

it('gives a previewed theme\'s regions in the state they were kept in, and what it would leave unplaced', function (): void {
    $region = regionCreate($this->admin);
    themeActivate('plain');
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['theme' => 'good']], (string) $this->admin->id));

    // Fixture Plain declares no region, so Fixture Good's is unplaced today; it comes back with its theme.
    $this->actingAs($this->admin)->get('/admin/themes')->assertInertia(fn (Assert $page) => $page
        ->where('regions.theme.code', 'plain')
        ->where('regions.regions', [])
        ->where('regions.others.0.slug', 'good--after-content')
        ->where('previewRegions.theme.code', 'good')
        ->where('previewRegions.regions.0.state', 'draft')
        ->where('previewRegions.regions.0.contentId', $region->id)
        ->where('previewRegions.others', []));
});

it('says no theme is in use when the chosen one can\'t be used, every region unplaced', function (): void {
    regionCreate($this->admin);
    themeActivate('broken');

    $this->actingAs($this->admin)->get('/admin/themes')->assertInertia(fn (Assert $page) => $page
        ->where('regions.theme', null)
        ->where('regions.regions', [])
        ->where('regions.others.0.slug', 'good--after-content'));
});

it('draws the theme in use\'s settings at its own address, and refuses it to anyone who may not change the design', function (): void {
    $this->actingAs($this->admin)->get('/admin/themes/good/settings')->assertInertia(fn (Assert $page) => $page
        ->component('themes/settings')
        ->where('themeGroup', 'theme.good')
        ->has('slots', 2)
        ->has('regions')
        ->has('hiddenCards'));
    $this->actingAs($this->admin)->get('/admin/themes')->assertInertia(fn (Assert $page) => $page
        ->component('themes/index')
        ->where('themeGroup', 'theme.good')
        ->has('slots', 2));

    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->get('/admin/themes/good/settings')->assertForbidden();
});

it('sends another theme\'s settings address back to the list, and an unknown theme\'s to nothing', function (): void {
    $this->actingAs($this->admin)->get('/admin/themes/plain/settings')->assertRedirect('/admin/themes');
    $this->actingAs($this->admin)->get('/admin/themes/broken/settings')->assertRedirect('/admin/themes');
    $this->actingAs($this->admin)->get('/admin/themes/nosuch/settings')->assertNotFound();
});

it('draws the previewed theme\'s settings once it is previewed, and sends another theme\'s address back to the list', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['theme' => 'default']], (string) $this->admin->id));

    $this->actingAs($this->admin)->get('/admin/themes/default/settings')->assertInertia(fn (Assert $page) => $page
        ->component('themes/settings')
        ->where('preview.code', 'default'));
    $this->actingAs($this->admin)->get('/admin/themes')->assertInertia(fn (Assert $page) => $page
        ->where('preview.code', 'default'));

    $this->actingAs($this->admin)->get('/admin/themes/good/settings')->assertRedirect('/admin/themes');
});

it('wraps the theme card\'s Preview through the catalogue (#519)', function (): void {
    $source = file_get_contents(resource_path('js/components/themes/theme-list.tsx'));

    expect($source)->toContain("{__('Preview')}")
        ->and($source)->not->toMatch('/>(\s*)Preview(\s*)<span className="sr-only">/');
});
