<?php

declare(strict_types=1);

use App\Domain\Design\Operations\ChangeBrandColour;
use App\Domain\Design\Operations\Input\ChangeBrandColourInput;
use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Design\Operations\SaveDesignDraft;
use App\Domain\Settings\Queries\FindSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    // Both fixture themes have a white light background; Fixture Good's dark one is #141413.
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/quiet')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('saves the brand colour from General at once, through the design group, with no draft', function (): void {
    $version = (int) DB::table('settings')->where('group_key', 'design')->value('version');

    $this->actingAs($this->admin)->from('/admin/settings/general')
        ->put('/admin/settings/general/brand-colour', ['accent' => '#0B5394', 'expected_version' => $version])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/settings/general');

    expect(app(SettingsReader::class)->string('design', 'brand'))->toBe('#0b5394')
        ->and(DB::table('settings')->where('group_key', 'design')->value('draft_values'))->toBeNull()
        ->and(DB::table('settings_changes')->where('group_key', 'design')->exists())->toBeTrue();
});

it('refuses a colour that fails contrast on the light background, saying by how much, and writes nothing', function (): void {
    expect(fn () => app(ChangeBrandColour::class)->execute(new ChangeBrandColourInput('#ffeb3b', 0, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'The brand colour reaches only');

    expect(app(SettingsReader::class)->string('design', 'brand'))->toBe('');
});

it('refuses a colour the theme being previewed fails, though the live theme passes it', function (): void {
    $folder = sys_get_temp_dir().'/teme-brand-'.getmypid().'-'.uniqid().'/cream';
    mkdir($folder.'/views/templates', 0777, true);
    file_put_contents($folder.'/views/templates/index.blade.php', '<x-teme::document><main id="main"></main></x-teme::document>');
    file_put_contents($folder.'/teme-theme.json', (string) json_encode(pageManifest(['code' => 'cream', 'name' => 'Cream', 'tokens' => ['base' => '#fcfbf8']])));
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), $folder]]);
    themeActivate('good');
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['theme' => 'cream']], (string) $this->admin->id));

    // #767676 reaches 4.54:1 on white and 4.39:1 on #fcfbf8; white text on it, 4.54:1.
    expect(fn () => app(ChangeBrandColour::class)->execute(new ChangeBrandColourInput('#767676', 0, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'With Cream, being previewed in Themes');

    expect(app(SettingsReader::class)->string('design', 'brand'))->toBe('');
    File::deleteDirectory(dirname($folder));
});

it('drops a colour waiting in Design\'s draft, and keeps the rest of the draft', function (): void {
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['design' => ['theme' => 'quiet', 'brand' => '#1d4ed8']], (string) $this->admin->id));

    app(ChangeBrandColour::class)->execute(new ChangeBrandColourInput('#0b5394', 0, (string) $this->admin->id));

    expect(json_decode((string) DB::table('settings')->where('group_key', 'design')->value('draft_values'), true))->toBe(['theme' => 'quiet'])
        ->and(app(SettingsReader::class)->string('design', 'brand'))->toBe('#0b5394');
});

it('gives the theme\'s own colour back when emptied', function (): void {
    app(ChangeBrandColour::class)->execute(new ChangeBrandColourInput('#0b5394', 0, (string) $this->admin->id));
    $version = (int) DB::table('settings')->where('group_key', 'design')->value('version');

    app(ChangeBrandColour::class)->execute(new ChangeBrandColourInput('', $version, (string) $this->admin->id));

    expect(app(SettingsReader::class)->string('design', 'brand'))->toBe('');
});

it('refuses a form opened before the design last changed', function (): void {
    $this->actingAs($this->admin)->put('/admin/settings/general/brand-colour', ['accent' => '#0b5394', 'expected_version' => 0])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->from('/admin/settings/general')
        ->put('/admin/settings/general/brand-colour', ['accent' => '#1d4ed8', 'expected_version' => 0])
        ->assertSessionHasErrors('accent');

    expect(app(SettingsReader::class)->string('design', 'brand'))->toBe('#0b5394');
});

it('writes nothing when the person may not change the design', function (): void {
    $person = User::factory()->create();
    $person->givePermissionTo('settings.general.manage');

    expect(fn () => app(ChangeBrandColour::class)->execute(new ChangeBrandColourInput('#0b5394', 0, (string) $person->id)))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($person)->put('/admin/settings/general/brand-colour', ['accent' => '#0b5394', 'expected_version' => 0])->assertForbidden();

    expect(app(SettingsReader::class)->string('design', 'brand'))->toBe('');

    // On General, the section is theirs to read, not to change.
    $this->actingAs($person)->get('/admin/settings/general')
        ->assertInertia(fn (Assert $page) => $page->where('brandColour.canChange', false));
});

it('draws the brand colour on General with both schemes, as the server works them out (I-457)', function (): void {
    app(ChangeBrandColour::class)->execute(new ChangeBrandColourInput('#0b5394', 0, (string) $this->admin->id));

    $this->actingAs($this->admin)->get('/admin/settings/general')
        ->assertInertia(fn (Assert $page) => $page
            ->where('heading.description', 'The site’s name, tagline, contact address, time zone, language and brand color.')
            ->where('brandColour.value', '#0b5394')
            ->where('brandColour.themeAccent', '#b3261e')
            ->where('brandColour.bases', ['light' => '#ffffff', 'dark' => '#141413'])
            ->where('brandColour.schemes.light', ['accent' => '#0b5394', 'onAccent' => '#ffffff', 'ratio' => 7.84])
            ->where('brandColour.schemes.dark', ['accent' => '#4283c8', 'onAccent' => '#111111', 'ratio' => 4.66])
            ->where('brandColour.canChange', true));

    // Only General draws it, and Design no longer does.
    $this->actingAs($this->admin)->get('/admin/settings/media')->assertInertia(fn (Assert $page) => $page->where('brandColour', null));

    expect(uiComponent('components/fields/brand-colour-setting.tsx'))->toContain('darkVariant(')->toContain('schemes');
});

it('finds the brand colour on General', function (): void {
    expect(app(FindSettings::class)->run('brand colour', $this->admin)['settings'])
        ->toContain(['key' => 'design.brand', 'label' => 'Brand colour', 'screen' => 'General', 'href' => '/admin/settings/general#field-brand']);
});
