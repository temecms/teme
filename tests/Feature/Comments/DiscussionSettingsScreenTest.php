<?php

declare(strict_types=1);

use App\Domain\Comments\Operations\ChangeDiscussionSettings;
use App\Domain\Comments\Operations\Input\ChangeDiscussionSettingsInput;
use App\Domain\Comments\Operations\Input\SwitchCommentsInput;
use App\Domain\Comments\Operations\SwitchComments;
use App\Domain\Comments\Queries\DiscussionScreen;
use App\Domain\Settings\Modules;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\Queries\SettingsNavigation;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    // Someone granted the settings alone, by hand: roles can't be edited yet (D5).
    $this->settingsOnly = User::factory()->create()->assignRole('editor')->givePermissionTo('settings.discussion.manage');
    $this->saved = fn (string $group): array => (array) json_decode((string) (DB::table('settings')->where('group_key', $group)->value('overrides') ?? '{}'), true);
    $this->switchTo = fn (User $person, string $value, int $version = 0) => $this->actingAs($person)->from(route('settings.discussion'))
        ->put(route('settings.discussion.switch'), ['values' => ['comments' => $value], 'expected_version' => $version]);
    $this->save = fn (array $values, int $version = 0) => $this->actingAs($this->admin)->from(route('settings.discussion'))
        ->put(route('settings.discussion.update'), ['values' => $values, 'expected_version' => $version]);
});

it('shows administrators the switch, then every Discussion setting in the approved design\'s five sections (E1, E2, D4)', function (): void {
    $this->actingAs($this->admin)->get(route('settings.discussion'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/discussion')
            ->where('description', 'Whether the site has comments, who can comment, which comments wait for approval, and the emails they send.')
            ->where('comments.field.key', 'comments')
            ->where('comments.value', 'on')
            ->where('comments.canSwitch', true)
            ->where('fieldsets', fn ($fieldsets): bool => collect($fieldsets)->pluck('legend')->all() === ['Where comments are open', 'Threads and pages', 'Moderation', 'Emails', 'Privacy'])
            ->where('fieldsets.0.info', null)
            ->where('fieldsets.2.info.label', 'Who skips these checks')
            ->where('fieldsets.4.info', null)
            ->where('fieldsets.3.fields', fn ($fields): bool => collect($fields)->pluck('key')->all() === ['email_moderators', 'email_authors'])
            ->where('values.keep_ip_days', 60)
            ->where('version', 0));
});

it('puts every setting of the discussion group in exactly one fieldset, so none goes missing from the screen (D4)', function (): void {
    $keys = array_map(fn (Setting $setting): string => $setting->key, app(SettingsRegistry::class)->find('discussion')?->settings ?? []);
    $placed = array_merge(...array_values(DiscussionScreen::FIELDSETS));

    expect($keys)->not->toBeEmpty()
        ->and($placed)->toEqualCanonicalizing($keys)
        ->and(count($placed))->toBe(count(array_unique($placed)));
});

it('is a Settings tab for whoever may change it and never a Modules tab, and the generic address leads to it (D2, I-485)', function (): void {
    expect(array_column(app(SettingsNavigation::class)->forUser($this->admin->id), 'key'))->toContain('discussion')->not->toContain('modules')
        ->and(array_column(app(SettingsNavigation::class)->forUser($this->editor->id), 'key'))->not->toContain('discussion');

    $this->actingAs($this->admin)->get('/admin/settings/modules')->assertRedirect(route('settings.discussion'));
    $this->actingAs($this->editor)->get(route('settings.discussion'))->assertForbidden();
});

it('names, for every settings group with a screen of its own, a route that exists, the addresses group aside until issue #55 (D2)', function (): void {
    $screens = collect(app(SettingsRegistry::class)->all())
        ->filter(fn (SettingsGroup $group): bool => $group->screen !== null && $group->key !== 'addresses')
        ->mapWithKeys(fn (SettingsGroup $group): array => [$group->key => (string) $group->screen]);

    expect($screens->only(['discussion', 'modules'])->all())->toEqual(['discussion' => 'settings.discussion', 'modules' => 'settings.discussion'])
        ->and($screens->reject(fn (string $screen): bool => Route::has($screen))->all())->toBe([]);
});

it('shows the switch read-only to someone without settings.modules.manage, and SwitchComments writes nothing for them (E1, D5)', function (): void {
    $this->actingAs($this->settingsOnly)->get(route('settings.discussion'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('comments.canSwitch', false)->where('fieldsets', fn ($fieldsets): bool => count($fieldsets) === 5));

    ($this->switchTo)($this->settingsOnly, 'off')->assertForbidden();

    expect(fn () => app(SwitchComments::class)->execute(new SwitchCommentsInput('off', 0, (string) $this->editor->id)))->toThrow(AuthorizationException::class)
        ->and(Modules::on(Modules::COMMENTS))->toBeTrue()
        ->and(DB::table('settings')->where('group_key', 'modules')->exists())->toBeFalse();
});

it('switches comments off, keeping every other setting for when they come back, and shows the switch alone; then on (E1, I-484)', function (): void {
    cmSettings(['hold_all' => true]);

    ($this->switchTo)($this->admin, 'off')
        ->assertRedirect(route('settings.discussion'))
        ->assertInertiaFlash('toast.message', 'Comments switched off. Stored comments are kept for when you switch them back on.');

    expect(Modules::on(Modules::COMMENTS))->toBeFalse()
        ->and(($this->saved)('discussion'))->toBe(['hold_all' => true]);

    $this->actingAs($this->admin)->get(route('settings.discussion'))
        ->assertInertia(fn (Assert $page) => $page->where('comments.value', 'off')->where('fieldsets', [])->where('values', []));

    ($this->switchTo)($this->admin, 'on', 1)
        ->assertInertiaFlash('toast.message', 'Comments switched on. Readers can comment wherever an item is open.');

    expect(Modules::on(Modules::COMMENTS))->toBeTrue();
});

it('saves the Discussion settings through ChangeDiscussionSettings, against the version the form was opened on (D3)', function (): void {
    ($this->save)(['keep_ip_days' => '30', 'email_authors' => '0', 'thread_depth' => '3'])
        ->assertRedirect(route('settings.discussion'))
        ->assertInertiaFlash('toast.message', 'Settings saved.');

    expect(($this->saved)('discussion'))->toEqual(['keep_ip_days' => 30, 'email_authors' => false, 'thread_depth' => 3]);

    ($this->save)(['keep_ip_days' => '45'])
        ->assertSessionHasErrors(['expected_version' => 'These settings were changed by someone else while you had them open. Reload the page and try again.']);

    expect(($this->saved)('discussion')['keep_ip_days'])->toBe(30);
});

it('refuses a value out of range or a setting that doesn\'t exist, and writes nothing (E2)', function (array $values, string $key): void {
    ($this->save)($values)->assertSessionHasErrors($key);

    expect(DB::table('settings')->where('group_key', 'discussion')->exists())->toBeFalse();
})->with([
    'no days' => [['keep_ip_days' => '0'], 'values.keep_ip_days'],
    'over a year' => [['keep_ip_days' => '366'], 'values.keep_ip_days'],
    'a setting that isn\'t one' => [['keep_forever' => '1'], 'values.keep_forever'],
]);

it('refuses to change the settings while comments are off, which keeps them unchanged (D3, E1)', function (): void {
    cmSettings(['comments' => 'off'], 'modules');

    expect(fn () => app(ChangeDiscussionSettings::class)->execute(new ChangeDiscussionSettingsInput(['hold_all' => true], 0, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'Comments are switched off.')
        ->and(DB::table('settings')->where('group_key', 'discussion')->exists())->toBeFalse();
});

it('lets no one without settings.discussion.manage change the settings, and writes nothing for them (D5)', function (): void {
    expect(fn () => app(ChangeDiscussionSettings::class)->execute(new ChangeDiscussionSettingsInput(['hold_all' => true], 0, (string) $this->editor->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('settings')->where('group_key', 'discussion')->exists())->toBeFalse();
});

it('leaves both groups to their own screen: the generic save refuses them (D2)', function (string $group, array $values): void {
    expect(fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput($group, $values, [], [], 0, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'Change these settings on their own screen.');
})->with([
    'discussion' => ['discussion', ['hold_all' => true]],
    'modules' => ['modules', ['comments' => 'off']],
]);

it('offers the switch on the Comments screen while comments are off, to whoever may switch them (D15)', function (): void {
    cmSettings(['comments' => 'off'], 'modules');

    $this->actingAs($this->admin)->get(route('comments.index'))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('comments/off')->where('canSwitch', true));

    $this->actingAs($this->settingsOnly)->get(route('comments.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canSwitch', false));
});
