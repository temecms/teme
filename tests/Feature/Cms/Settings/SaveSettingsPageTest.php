<?php

declare(strict_types=1);

use App\Domain\Design\Operations\Input\ChangeBrandColourInput;
use App\Domain\Settings\Operations\Input\SaveSettingsPageInput;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\SaveSettingsPage;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

/** A settings group's version as stored; 0 before its first save. */
function sspVersion(string $group): int
{
    return (int) (DB::table('settings')->where('group_key', $group)->value('version') ?? 0);
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/quiet')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('saves General and its brand color in one request, with one toast', function (): void {
    $this->actingAs($this->admin)->from('/admin/settings/general')
        ->put('/admin/settings/general', [
            'values' => ['site_title' => 'The Garden City Ledger'],
            'expected_version' => sspVersion('general'),
            'accent' => '#0B5394',
            'accent_version' => sspVersion('design'),
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Settings saved.');

    expect(app(SettingsReader::class)->string('general', 'site_title'))->toBe('The Garden City Ledger')
        ->and(app(SettingsReader::class)->string('design', 'accent'))->toBe('#0b5394');
});

it('saves nothing of the page when the brand color fails contrast, naming it under its field', function (): void {
    $this->actingAs($this->admin)->from('/admin/settings/general')
        ->put('/admin/settings/general', [
            'values' => ['site_title' => 'The Garden City Ledger'],
            'expected_version' => sspVersion('general'),
            'accent' => '#ffeb3b',
            'accent_version' => sspVersion('design'),
        ])
        ->assertSessionHasErrors('accent');

    expect(app(SettingsReader::class)->string('general', 'site_title'))->not->toBe('The Garden City Ledger')
        ->and(sspVersion('general'))->toBe(0);
});

it('names a brand color opened on an older version under its field, and saves nothing', function (): void {
    $this->actingAs($this->admin)->from('/admin/settings/general')
        ->put('/admin/settings/general', [
            'values' => ['site_title' => 'The Garden City Ledger'],
            'expected_version' => sspVersion('general'),
            'accent' => '#0b5394',
            'accent_version' => sspVersion('design') + 5,
        ])
        ->assertSessionHasErrors('accent');

    expect(sspVersion('general'))->toBe(0);
});

it('leaves the design group alone when the page does not send the brand color', function (): void {
    $design = sspVersion('design');

    $this->actingAs($this->admin)->from('/admin/settings/general')
        ->put('/admin/settings/general', ['values' => ['site_title' => 'The Garden City Ledger'], 'expected_version' => sspVersion('general')])
        ->assertSessionHasNoErrors();

    expect(sspVersion('design'))->toBe($design);
});

it('takes a brand color only from General', function (): void {
    $design = sspVersion('design');

    $this->actingAs($this->admin)->from('/admin/settings/writing')
        ->put('/admin/settings/content', ['values' => ['trash_days' => 20], 'expected_version' => sspVersion('content'), 'accent' => '#0b5394', 'accent_version' => $design])
        ->assertSessionHasNoErrors();

    expect(sspVersion('design'))->toBe($design)
        ->and(app(SettingsReader::class)->string('design', 'accent'))->toBe('');
});

it('saves Reading and its home page in one request, saying when addresses move', function (): void {
    $welcome = addrPublished($this->admin, 'Welcome', 'page');

    $this->actingAs($this->admin)->from('/admin/settings/reading')
        ->put('/admin/settings/reading', [
            'values' => ['posts_per_page' => 12],
            'expected_version' => sspVersion('reading'),
            'front_shows' => 'page',
            'front_page_id' => $welcome->id,
            'posts_page_id' => '',
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Settings saved. Moving every address in the background.');

    expect(app(SettingsReader::class)->int('reading', 'posts_per_page'))->toBe(12)
        ->and(app(SettingsReader::class)->string('addresses', 'front_page_id'))->toBe((string) $welcome->id);
});

it('writes nothing when the person may change General but not the site design', function (): void {
    $person = User::factory()->create();
    $person->givePermissionTo('settings.general.manage');
    $design = sspVersion('design');

    $this->actingAs($person)->from('/admin/settings/general')
        ->put('/admin/settings/general', [
            'values' => ['site_title' => 'The Garden City Ledger'],
            'expected_version' => sspVersion('general'),
            'accent' => '#0b5394',
            'accent_version' => $design,
        ])
        ->assertForbidden();

    expect(sspVersion('general'))->toBe(0)->and(sspVersion('design'))->toBe($design);
});

it('writes nothing when the person may change Reading but not the addresses its home page lives in', function (): void {
    $person = User::factory()->create();
    $person->givePermissionTo('settings.general.manage');
    $addresses = sspVersion('addresses');

    $this->actingAs($person)->from('/admin/settings/reading')
        ->put('/admin/settings/reading', [
            'values' => ['posts_per_page' => 12],
            'expected_version' => sspVersion('reading'),
            'front_shows' => 'posts',
            'front_page_id' => '',
            'posts_page_id' => '',
        ])
        ->assertForbidden();

    expect(sspVersion('reading'))->toBe(0)->and(sspVersion('addresses'))->toBe($addresses);
});

it('refuses the Operation itself for someone with no right to the group, writing nothing', function (): void {
    $person = User::factory()->create();

    expect(fn () => app(SaveSettingsPage::class)->execute(new SaveSettingsPageInput(
        group: new UpdateSettingsInput('general', ['site_title' => 'X'], [], [], 0, (string) $person->id),
        brandColour: new ChangeBrandColourInput('#0b5394', 0, (string) $person->id),
    )))->toThrow(AuthorizationException::class);

    expect(sspVersion('general'))->toBe(0)->and(sspVersion('design'))->toBe(0);
});
