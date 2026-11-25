<?php

declare(strict_types=1);

use App\Domain\Design\Operations\ActivateTheme;
use App\Domain\Design\Operations\ChangeBrandColour;
use App\Domain\Design\Operations\Input\ActivateThemeInput;
use App\Domain\Design\Operations\Input\ChangeBrandColourInput;
use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Design\Operations\Input\StopPreviewingThemeInput;
use App\Domain\Design\Operations\SaveDesignDraft;
use App\Domain\Design\Operations\StopPreviewingTheme;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Themes\DesignSettingsGroup;
use App\Domain\Themes\ThemeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Backups\HealthyBackups;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    // Cream: a usable theme of the test's own, with one menu place, in a
    // folder deleted after each test. A case may darken its light background.
    $this->creamFolder = sys_get_temp_dir().'/teme-cream-'.getmypid().'-'.uniqid().'/cream';
    mkdir($this->creamFolder.'/views/templates', 0777, true);
    file_put_contents($this->creamFolder.'/views/templates/index.blade.php', '<x-teme::document><main id="main"></main></x-teme::document>');
    $this->writeCream = fn (string $base): int|false => file_put_contents($this->creamFolder.'/teme-theme.json', (string) json_encode(pageManifest(['code' => 'cream', 'name' => 'Cream', 'navigations' => ['primary' => 'Header'], 'tokens' => ['base' => $base]])));
    ($this->writeCream)('#ffffff');

    // Fixture Broken asks for another Teme, so it can't be used.
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/quiet'), base_path('tests/Fixtures/themes/broken'), $this->creamFolder]]);
    themeActivate('good');
    // As on a site that chose its theme on the screen: the writer's version,
    // not themeActivate()'s 0, which a cleared draft would take for a row
    // made only for the draft, and delete.
    DB::table('settings')->where('group_key', 'design')->update(['version' => 1]);

    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->adminId = (string) $this->admin->id;
    $this->draftOf = fn (string $group): mixed => json_decode((string) DB::table('settings')->where('group_key', $group)->value('draft_values'), true);
});

afterEach(function (): void {
    File::deleteDirectory(dirname($this->creamFolder));
});

it('starts previewing a theme through the design draft, leaving the live theme as it is', function (): void {
    $this->actingAs($this->admin)->post('/admin/themes/quiet/preview')
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/themes');

    expect(($this->draftOf)('design'))->toBe(['theme' => 'quiet'])
        ->and(app(SettingsReader::class)->string('design', 'theme'))->toBe('good');
});

it('refuses to preview a theme the brand colour fails, saying why, and drafts nothing', function (): void {
    ($this->writeCream)('#fcfbf8');
    themeActivate('good');
    DB::table('settings')->where('group_key', 'design')->update(['version' => 1]);
    // #767676 reaches 4.54:1 on Fixture Good's white, and 4.39:1 on Cream's #fcfbf8.
    app(ChangeBrandColour::class)->execute(new ChangeBrandColourInput('#767676', 1, $this->adminId));

    $this->actingAs($this->admin)->from('/admin/themes')->post('/admin/themes/cream/preview')
        ->assertSessionHasErrors('groups.design.theme');

    expect(session('errors')->first('groups.design.theme'))->toStartWith('The brand colour reaches only')
        ->and(DB::table('settings')->where('group_key', 'design')->value('draft_values'))->toBeNull();
});

it('stops previewing: the switch and the previewed theme\'s own changes go, the active theme\'s stay', function (): void {
    $menu = navCreate($this->admin);
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['feed_layout' => 'grid']], $this->adminId));
    // Another key waiting in the design group's own draft, which must outlive the switch.
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['accent' => '#1d4ed8']], $this->adminId));
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['theme' => 'cream'], 'theme.cream' => ['nav_primary' => $menu->id]], $this->adminId));

    $this->actingAs($this->admin)->delete('/admin/themes/cream/preview')
        ->assertRedirect('/admin/themes')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Stopped previewing Cream.']);

    expect(($this->draftOf)('design'))->toBe(['accent' => '#1d4ed8'])
        ->and(DB::table('settings')->where('group_key', 'theme.cream')->exists())->toBeFalse()
        ->and(($this->draftOf)('theme.good'))->toBe(['feed_layout' => 'grid'])
        ->and(app(SettingsReader::class)->string('design', 'theme'))->toBe('good');
});

it('leaves alone a preview of another theme, and writes nothing when the person may not change the design', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['theme' => 'quiet']], $this->adminId));
    $editor = User::factory()->create()->assignRole('editor');

    expect(app(StopPreviewingTheme::class)->execute(new StopPreviewingThemeInput('cream', $this->adminId)))->toBeNull()
        ->and(fn () => app(StopPreviewingTheme::class)->execute(new StopPreviewingThemeInput('quiet', (string) $editor->id)))->toThrow(AuthorizationException::class);

    $this->actingAs($editor)->delete('/admin/themes/quiet/preview')->assertForbidden();
    $this->actingAs($editor)->post('/admin/themes/cream/preview')->assertForbidden();

    expect(($this->draftOf)('design'))->toBe(['theme' => 'quiet']);
});

it('activates a previewed theme with every change made for it, one change each in the history, and says it is live', function (): void {
    $menu = navCreate($this->admin);
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['theme' => 'cream'], 'theme.cream' => ['nav_primary' => $menu->id]], $this->adminId));

    $this->actingAs($this->admin)->post('/admin/themes/cream/activate')
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/themes')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Cream is live.']);

    expect(app(SettingsReader::class)->string('design', 'theme'))->toBe('cream')
        ->and(app(SettingsReader::class)->string('theme.cream', 'nav_primary'))->toBe($menu->id)
        ->and(DB::table('settings')->whereNotNull('draft_values')->exists())->toBeFalse()
        ->and(DB::table('settings_changes')->where('group_key', 'design')->count())->toBe(1)
        ->and(DB::table('settings_changes')->where('group_key', 'theme.cream')->count())->toBe(1);
});

it('says how many of the new theme\'s regions wait to be filled', function (): void {
    themeActivate('quiet');
    DB::table('settings')->where('group_key', 'design')->update(['version' => 1]);

    $this->actingAs($this->admin)->post('/admin/themes/good/activate')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Fixture Good is live. 1 region stays empty until you fill it in Content › Regions.']);
});

it('activates the theme it is given, whatever the draft was previewing', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['theme' => 'cream']], $this->adminId));

    app(ActivateTheme::class)->execute(new ActivateThemeInput('quiet', $this->adminId));

    expect(app(SettingsReader::class)->string('design', 'theme'))->toBe('quiet')
        ->and(DB::table('settings')->where('group_key', 'design')->value('draft_values'))->toBeNull();
});

it('refuses to activate a theme the brand colour fails, writing nothing', function (): void {
    // #767676 passes both whites, so Cream can be previewed with it.
    app(ChangeBrandColour::class)->execute(new ChangeBrandColourInput('#767676', 1, $this->adminId));
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['theme' => 'cream']], $this->adminId));

    // Then Cream's next version darkens its light background, which the colour fails (4.39:1).
    ($this->writeCream)('#fcfbf8');
    app(ThemeRegistry::class)->flush();
    DesignSettingsGroup::register(app(SettingsRegistry::class), app(ThemeRegistry::class));

    expect(fn () => app(ActivateTheme::class)->execute(new ActivateThemeInput('cream', $this->adminId)))
        ->toThrow(ValidationException::class, 'The brand colour reaches only');

    expect(app(SettingsReader::class)->string('design', 'theme'))->toBe('good')
        ->and(($this->draftOf)('design'))->toBe(['theme' => 'cream']);
});

it('refuses a theme that isn\'t installed, can\'t be used, or is already the active one', function (): void {
    $activate = fn (string $code): mixed => app(ActivateTheme::class)->execute(new ActivateThemeInput($code, $this->adminId));

    expect(fn () => $activate('nowhere'))->toThrow(ValidationException::class, 'No theme called “nowhere” is installed.')
        ->and(fn () => $activate('broken'))->toThrow(ValidationException::class, 'Fixture Broken can’t be used: The theme requires Teme ^9.0, but this is Teme 0.1.0.')
        ->and(fn () => $activate('good'))->toThrow(ValidationException::class, 'Fixture Good is already the active theme.');

    expect(app(SettingsReader::class)->string('design', 'theme'))->toBe('good');
});

it('publishes every waiting change with the theme, the old theme\'s own among them', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.good' => ['feed_layout' => 'grid']], $this->adminId));

    app(ActivateTheme::class)->execute(new ActivateThemeInput('quiet', $this->adminId));

    expect(app(SettingsReader::class)->string('design', 'theme'))->toBe('quiet')
        ->and(app(SettingsReader::class)->string('theme.good', 'feed_layout'))->toBe('grid')
        ->and(DB::table('settings')->whereNotNull('draft_values')->exists())->toBeFalse();
});

it('mends a site whose chosen theme can\'t be used', function (): void {
    HealthyBackups::seed();

    themeActivate('broken');
    DB::table('settings')->where('group_key', 'design')->update(['version' => 1]);

    $this->actingAs($this->admin)->get('/admin')->assertInertia(fn (Assert $page) => $page->where('siteStatus.0.level', 'error'));

    app(ActivateTheme::class)->execute(new ActivateThemeInput('quiet', $this->adminId));

    // Fixture Quiet draws no menu, so no other check speaks either.
    $this->actingAs($this->admin)->get('/admin')->assertInertia(fn (Assert $page) => $page->where('siteStatus', []));
});

it('writes nothing when the person may not change the design', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => app(ActivateTheme::class)->execute(new ActivateThemeInput('quiet', (string) $editor->id)))->toThrow(AuthorizationException::class);

    $this->actingAs($editor)->post('/admin/themes/quiet/activate')->assertForbidden();

    expect(app(SettingsReader::class)->string('design', 'theme'))->toBe('good')
        ->and(DB::table('settings')->where('group_key', 'design')->value('draft_values'))->toBeNull();
});

it('publishes a drafted switch only through ActivateTheme, refusing a theme that can no longer be used and writing nothing', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['theme' => 'cream']], $this->adminId));

    // Cream's next version lost the view every theme needs; a tab opened before the preview still shows Publish.
    unlink($this->creamFolder.'/views/templates/index.blade.php');
    app(ThemeRegistry::class)->flush();

    $this->actingAs($this->admin)->from('/admin/themes')->post('/admin/themes/publish')
        ->assertSessionHasErrors(['theme' => 'Cream can’t be used: The theme has no views/templates/index.blade.php, which every theme needs.']);

    expect(app(SettingsReader::class)->string('design', 'theme'))->toBe('good')
        ->and(($this->draftOf)('design'))->toBe(['theme' => 'cream'])
        ->and(DB::table('settings_changes')->where('group_key', 'design')->exists())->toBeFalse();
});
