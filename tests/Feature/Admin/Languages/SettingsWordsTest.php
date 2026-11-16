<?php

declare(strict_types=1);

use App\Domain\Languages\Operations\Input\SaveSettingsWordsInput;
use App\Domain\Languages\Operations\SaveSettingsWords;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Languages\Languages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    siteSetLanguage($this->admin, 'en-GB');
    Languages::set(['ig', 'fr']);
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['tagline' => 'Notes on plants'], [], [], swVersion(), (string) $this->admin->id));
});

/**
 * The general group's version now.
 */
function swVersion(): int
{
    return (int) DB::table('settings')->where('group_key', 'general')->value('version');
}

/**
 * What a refused save must leave as it was: the group's row and the log.
 *
 * @return array{group: mixed, changes: int}
 */
function swWritten(): array
{
    return ['group' => DB::table('settings')->where('group_key', 'general')->first(), 'changes' => DB::table('settings_changes')->count()];
}

it('gives General its words in each other language, with the default language\'s as placeholders', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/general')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('words.languages', [['tag' => 'ig', 'name' => 'Igbo'], ['tag' => 'fr', 'name' => 'French']])
            ->where('words.fields', fn ($fields): bool => collect($fields)->pluck('key')->sort()->values()->all() === ['site_title', 'tagline'])
            ->where('words.defaults.tagline', 'Notes on plants')
            ->where('words.version', swVersion()));
});

it('saves each language\'s words, an empty one falling back to the default language\'s', function (): void {
    $this->actingAs($this->admin)
        ->put('/admin/settings/general/words', [
            'words' => ['ig' => ['tagline' => 'Ihe gbasara osisi', 'site_title' => ''], 'fr' => ['tagline' => '', 'site_title' => '']],
            'expected_version' => swVersion(),
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Saved the words in other languages.');

    $this->actingAs($this->admin)->get('/admin/settings/general')
        ->assertInertia(fn (Assert $page) => $page
            ->where('words.values.ig.tagline', 'Ihe gbasara osisi')
            ->where('words.values.fr', fn ($words): bool => ($words['tagline'] ?? '') === ''));
});

it('refuses words saved on an older version, and writes nothing', function (): void {
    $before = swWritten();

    $this->actingAs($this->admin)
        ->put('/admin/settings/general/words', ['words' => ['ig' => ['tagline' => 'Osisi']], 'expected_version' => swVersion() - 1])
        ->assertSessionHasErrors('expected_version');

    expect(swWritten())->toEqual($before);
});

it('refuses an editor General\'s words, and writes nothing', function (): void {
    $before = swWritten();

    $this->actingAs($this->editor)
        ->put('/admin/settings/general/words', ['words' => ['ig' => ['tagline' => 'Osisi']], 'expected_version' => swVersion()])
        ->assertForbidden();

    expect(swWritten())->toEqual($before);
});

it('gives no words on a site with one language', function (): void {
    Languages::set([]);

    $this->actingAs($this->admin)->get('/admin/settings/general')
        ->assertInertia(fn (Assert $page) => $page->where('words', null));
});

it('keeps no language\'s words when another\'s are refused', function (): void {
    $before = swWritten();

    $this->actingAs($this->admin)
        ->put('/admin/settings/general/words', [
            'words' => ['ig' => ['tagline' => 'Ihe gbasara osisi'], 'fr' => ['timezone' => 'Europe/Paris']],
            'expected_version' => swVersion(),
        ])
        ->assertSessionHasErrors('values.timezone');

    expect(swWritten())->toEqual($before);
});

it('refuses SaveSettingsWords to someone without the group\'s ability, and writes nothing', function (): void {
    $before = swWritten();

    expect(fn () => app(SaveSettingsWords::class)->execute(new SaveSettingsWordsInput('general', ['ig' => ['tagline' => 'Osisi']], swVersion(), (string) $this->editor->id)))
        ->toThrow(AuthorizationException::class);

    expect(swWritten())->toEqual($before);
});
