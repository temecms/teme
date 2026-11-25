<?php

declare(strict_types=1);

use App\Domain\Cards\CardManifest;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\Exceptions\InvalidDocumentException;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\RenderContext;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Design\Operations\ActivateTheme;
use App\Domain\Design\Operations\Input\ActivateThemeInput;
use App\Domain\Regions\Queries\RegionScreens;
use App\Domain\Regions\RegionRules;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\ManifestValidator;
use App\Domain\Themes\ThemeRegistry;
use App\Domain\Themes\ThemeSwitchNotes;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
 * A theme's own cards (docs/guides/developers/themes/regions.md): declared
 * under cards.provides, stored as one node type, "theme-card", naming the
 * card as theme:id; drawn by the theme's view only while it is active, and
 * kept with its regions when another theme is.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/open'), base_path('tests/Fixtures/themes/good')]]);
    themeActivate('open');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->about = fn (array $values = ['heading' => 'Hello']): array => ['type' => 'theme-card', 'attrs' => ['id' => (string) Str::ulid(), 'card' => 'open:about-me', 'values' => $values]];
});

/**
 * The open fixture's manifest with this change, checked as a theme's is.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $change
 *
 * @return list<string>
 */
function themeCardErrors(callable $change): array
{
    $path = base_path('tests/Fixtures/themes/open');
    $manifest = json_decode((string) file_get_contents("{$path}/teme-theme.json"), true);

    return app(ManifestValidator::class)->errors($change($manifest), $path);
}

it('accepts a theme that brings a card with its view', function (): void {
    expect(themeCardErrors(fn (array $manifest): array => $manifest))->toBe([])
        ->and(app(ThemeRegistry::class)->all()['open']->usable())->toBeTrue();
});

it('refuses a brought card that is badly declared', function (callable $change, string $error): void {
    expect(implode("\n", themeCardErrors($change)))->toContain($error);
})->with([
    'no view' => [fn (array $m): array => [...$m, 'cards' => ['provides' => ['ghost' => ['label' => 'Ghost', 'description' => 'Boo.', 'fields' => []]]]], 'cards.provides.ghost needs views/cards/own/ghost.blade.php.'],
    'bad id' => [fn (array $m): array => [...$m, 'cards' => ['provides' => ['No_Way' => ['label' => 'x', 'description' => 'y']]]], 'cards.provides.No_Way needs an id'],
    'a core name' => [fn (array $m): array => [...$m, 'cards' => ['provides' => ['button' => ['label' => 'x', 'description' => 'y']]]], 'cards.provides.button has the name of one of Teme'],
    'a choice field' => [fn (array $m): array => [...$m, 'cards' => ['provides' => ['about-me' => ['label' => 'x', 'description' => 'y', 'fields' => [['key' => 'pick', 'type' => 'choice', 'label' => 'Pick']]]]]], 'can’t be of the type choice'],
    'no description' => [fn (array $m): array => [...$m, 'cards' => ['provides' => ['about-me' => ['label' => 'x']]]], 'cards.provides.about-me needs a description'],
    'a region naming an unknown card' => [fn (array $m): array => [...$m, 'regions' => ['own' => ['label' => 'Own', 'cards' => ['about-you']]]], 'allows "about-you", which isn\'t a registered card'],
]);

it('draws its card in its regions, filled from its defaults, only while the theme is active', function (): void {
    $region = regionCreate($this->editor, 'open', 'own');
    regionSave($region, $this->editor, [($this->about)()]);
    listsPublish($region, $this->editor);
    pageArticle($this->editor, 'A story');

    expect(addrGet('/a-story/')->assertOk()->getContent())
        ->toContain('<div class="card-theme card-theme-about-me" data-theme-card="open:about-me"><aside class="about" data-region="own">Hello with photo </aside>');

    themeActivate('good');
    $card = app(CardRegistry::class)->find('theme-card');

    expect($card?->renderHtml(($this->about)()['attrs'], new RenderContext(app(LinkResolver::class))))->toBe('');
});

it('lets a region that takes every card, or names it, take the theme’s card, and no other region', function (): void {
    regionSave(regionCreate($this->editor, 'open', 'sidebar'), $this->editor, [($this->about)()]);
    regionSave(regionCreate($this->editor, 'open', 'own'), $this->editor, [($this->about)()]);
    $narrow = regionCreate($this->editor, 'open', 'narrow');
    $before = ContentRevision::query()->count();

    expect(fn () => regionSave($narrow, $this->editor, [($this->about)()]))->toThrow(InvalidDocumentException::class, 'The card "open:about-me" can\'t be used in this region.')
        ->and(ContentRevision::query()->count())->toBe($before);
});

it('never lets another theme’s region take the card, even one that takes every card', function (): void {
    expect(fn () => (new RegionRules('*', 50, theme: 'good'))->check(docWith(($this->about)())))
        ->toThrow(InvalidDocumentException::class, 'The card "open:about-me" can\'t be used in this region.');
});

it('refuses a value its field doesn’t allow, and writes nothing', function (): void {
    $region = regionCreate($this->editor, 'open', 'own');
    $before = ContentRevision::query()->count();

    expect(fn () => regionSave($region, $this->editor, [($this->about)(['heading' => str_repeat('a', 61)])]))->toThrow(InvalidDocumentException::class)
        ->and(ContentRevision::query()->count())->toBe($before);
});

it('tells the editor the theme’s cards and the regions that take them', function (): void {
    $card = collect(CardManifest::forBrowser(app(CardRegistry::class))['themeCards'])->firstWhere('key', 'open:about-me');
    $region = regionCreate($this->editor, 'open', 'sidebar');
    $mode = app(RegionScreens::class)->editorMode((string) $region->id);

    expect($card)->toMatchArray(['theme' => 'open', 'themeName' => 'Fixture Open', 'label' => 'About me', 'icon' => 'user', 'keywords' => ['bio']])
        ->and(collect($card['fields'])->pluck('key')->all())->toBe(['heading', 'show_photo'])
        ->and($mode['cards'] ?? [])->toContain('open:about-me')
        ->and($mode['cardLabels'] ?? [])->toContain('About me')
        ->and(app(RegionScreens::class)->index()['regions'][2])->toMatchArray(['key' => 'own', 'cards' => ['open:about-me'], 'cardLabels' => ['About me']]);
});

it('keeps the card in its region across a switch and counts it under the other themes', function (): void {
    $region = regionCreate($this->editor, 'open', 'own');
    regionSave($region, $this->editor, [($this->about)()]);
    $before = (array) DB::table('contents')->where('id', $region->id)->first();

    app(ActivateTheme::class)->execute(new ActivateThemeInput('good', (string) $this->admin->id));
    app(ActiveTheme::class)->forget();

    $others = collect(app(RegionScreens::class)->index()['others'])->keyBy('slug');

    expect((array) DB::table('contents')->where('id', $region->id)->first())->toBe($before)
        ->and($others['open--own'])->toMatchArray(['themeName' => 'Fixture Open', 'themeCards' => 1]);
});

it('counts which of its cards a switch hides, and in how many regions', function (): void {
    regionSave(regionCreate($this->editor, 'open', 'own'), $this->editor, [($this->about)(), ($this->about)()]);
    regionSave(regionCreate($this->editor, 'open', 'sidebar'), $this->editor, [($this->about)()]);
    regionSave(regionCreate($this->editor, 'open', 'narrow'), $this->editor, [regionCardNode('button')]);

    expect(app(ThemeSwitchNotes::class)->hiddenByLeaving(app(ActiveTheme::class)->get()))
        ->toBe([['card' => 'open:about-me', 'label' => 'About me', 'regions' => 2]]);
});

it('warns on Themes before a switch, and says on the switch what it kept', function (): void {
    regionSave(regionCreate($this->editor, 'open', 'own'), $this->editor, [($this->about)()]);

    $this->actingAs($this->admin)->get('/admin/themes')
        ->assertInertia(fn (Assert $page) => $page->where('hiddenCards', [['card' => 'open:about-me', 'label' => 'About me', 'regions' => 1]]));

    $this->actingAs($this->admin)->post('/admin/themes/good/activate')
        ->assertInertiaFlash('toast.message', 'Fixture Good is live. 1 region stays empty until you fill it in Content › Regions. Kept: 1 theme card, under Regions › Other themes.');
});
