<?php

declare(strict_types=1);

use App\Domain\Languages\SiteLanguages;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Languages\Languages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    siteSetLanguage(User::factory()->create()->assignRole('administrator'), 'en-GB');
    $this->languages = app(SiteLanguages::class);
});

it('has one language, the default, on a site that added none', function (): void {
    expect($this->languages->all())->toBe(['en-GB'])
        ->and($this->languages->isMultilingual())->toBeFalse()
        ->and($this->languages->prefix('en-GB'))->toBe('');
});

it('lists the default first, then the others in the order they were added', function (): void {
    Languages::set(['fr', 'de', 'ar'], ['fr' => 'fr', 'de' => 'deutsch']);

    expect($this->languages->all())->toBe(['en-GB', 'fr', 'de', 'ar'])
        ->and($this->languages->others())->toBe(['fr', 'de', 'ar'])
        ->and($this->languages->isMultilingual())->toBeTrue()
        ->and($this->languages->prefix('de'))->toBe('deutsch')
        ->and($this->languages->prefix('ar'))->toBe('ar');
});

it('leaves a hidden language out of what readers are shown, and always shows the default', function (): void {
    Languages::set(['fr', 'es'], hidden: ['es']);

    expect($this->languages->shown())->toBe(['en-GB', 'fr'])
        ->and($this->languages->isShown(null))->toBeTrue()
        ->and($this->languages->isShown('fr'))->toBeTrue()
        ->and($this->languages->isShown('es'))->toBeFalse();
});

it('stores the default as null and any other language as its tag, and reads null back as the default', function (): void {
    Languages::set(['fr']);

    expect($this->languages->stored('en-GB'))->toBeNull()
        ->and($this->languages->stored('fr'))->toBe('fr')
        ->and($this->languages->of(null))->toBe('en-GB')
        ->and($this->languages->of('fr'))->toBe('fr')
        ->and(fn () => $this->languages->stored('it'))->toThrow(InvalidArgumentException::class);
});

it('finds a language by its prefix, shown or hidden, and never the default by an empty one', function (): void {
    Languages::set(['fr', 'es'], hidden: ['es']);

    expect($this->languages->byPrefix('fr'))->toBe('fr')
        ->and($this->languages->byPrefix('es'))->toBe('es')
        ->and($this->languages->byPrefix(''))->toBeNull()
        ->and($this->languages->byPrefix('admin'))->toBeNull();
});

it('gives each language its own name, its direction and its Open Graph locale', function (): void {
    expect($this->languages->native('fr'))->toBe('Français')
        ->and($this->languages->direction('ar'))->toBe('rtl')
        ->and($this->languages->direction('yi'))->toBe('rtl')
        ->and($this->languages->direction('de'))->toBe('ltr')
        ->and($this->languages->ogLocale('pt-BR'))->toBe('pt_BR')
        ->and($this->languages->ogLocale('es'))->toBe('es_ES')
        ->and($this->languages->locale('pt-BR'))->toBe('pt_BR')
        ->and($this->languages->label('fr'))->toBe('French');
});

it('registers the languages group with its own screen, under General\'s ability', function (): void {
    $group = app(SettingsRegistry::class)->find('languages');

    expect($group?->screen)->toBe('settings.languages')
        ->and($group?->ability)->toBe('settings.general.manage')
        ->and($group?->setting('others')?->default)->toBe([])
        ->and($group?->setting('prefixes')?->default)->toBe([])
        ->and($group?->setting('hidden')?->default)->toBe([])
        ->and($group?->setting('untranslated_types')?->default)->toBe([])
        ->and($group?->setting('untranslated_taxonomies')?->default)->toBe([]);
});

it('refuses the languages group through the generic settings form', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    expect(fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('languages', ['others' => ['fr']], [], [], 0, (string) $admin->id)))
        ->toThrow(ValidationException::class, 'Change these settings on their own screen.')
        ->and(DB::table('settings')->where('group_key', 'languages')->exists())->toBeFalse();
});

it('shows the Languages screen to whoever manages General settings, and to nobody else', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($admin)->get('/admin/settings/languages')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/languages'));

    $this->actingAs($editor)->get('/admin/settings/languages')->assertForbidden();
});

it('offers the forms to add, change, reorder and remove a language, each through the screen\'s own routes', function (): void {
    // The placeholder this proved bare is replaced (docs/proposals/multilingual-screens.md, 2).
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($admin)->get('/admin/settings/languages')
        ->assertInertia(fn (Assert $page) => $page->component('settings/languages')
            ->has('languages')->has('choices')->has('version')->has('types'));

    expect((string) file_get_contents(resource_path('js/pages/settings/languages.tsx')))
        ->toContain('@/actions/App/Http/Admin/Settings/LanguagesController')
        ->not->toContain('fetch(');
});
