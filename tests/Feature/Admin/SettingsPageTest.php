<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsRegistry;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('generates the General settings page from the registry', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/settings/general')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/group')
            ->where('group.key', 'general')
            ->where('fields.0.key', 'site_title')
            ->where('fields.0.type', 'text')
            ->where('fields.0.required', true)
            ->where('values.site_title', 'My Teme site')
            ->where('version', 0));
});

it('sends the time zone field its options', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/settings/general')
        ->assertInertia(fn (Assert $page) => $page
            ->where('fields.3.key', 'timezone')
            ->where('fields.3.options.Europe/London', 'Europe/London'));
});

it('saves through UpdateSettings and shows the new value', function (): void {
    $this->actingAs($this->admin)
        ->put('/admin/settings/general', [
            'values' => ['site_title' => 'Plant Notes'],
            'expected_version' => 0,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/settings/general');

    expect(app(SettingsReader::class)->string('general', 'site_title'))->toBe('Plant Notes');
});

it('explains a conflicting save instead of overwriting', function (): void {
    $this->actingAs($this->admin)->put('/admin/settings/general', ['values' => ['site_title' => 'First'], 'expected_version' => 0]);

    $this->actingAs($this->admin)
        ->put('/admin/settings/general', ['values' => ['site_title' => 'Second'], 'expected_version' => 0])
        ->assertSessionHasErrors('expected_version');

    expect(app(SettingsReader::class)->string('general', 'site_title'))->toBe('First');
});

it('forbids people who may not manage the group', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->get('/admin/settings/general')->assertForbidden();
    $this->actingAs($editor)->put('/admin/settings/general', ['values' => ['site_title' => 'x'], 'expected_version' => 0])->assertForbidden();
});

it('returns 404 for an unknown group', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/nope')->assertNotFound();
});

it('sends /admin/settings to the General group', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings')->assertRedirect('/admin/settings/general');
});

it('shares the site title and the groups the user may manage', function (): void {
    $request = Request::create('/admin');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn (): User => $this->admin);

    $shared = app(HandleInertiaRequests::class)->share($request);

    $entry = fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'href' => route('settings.show', ['group' => $key], false), 'heading' => 'Site'];

    // The Settings menu's order (SettingsNavigation::ORDER), pages that aren't groups among them.
    expect($shared['name'])->toBe('My Teme site')
        ->and($shared['settingsNav'])->toBe([
            $entry('general', 'General'),
            $entry('writing', 'Writing'),
            $entry('reading', 'Reading'),
            $entry('discussion', 'Discussion'),
            $entry('seo', 'Search and social'),
            $entry('addresses', 'Addresses'),
            $entry('media', 'Media'),
            $entry('people', 'People'),
            $entry('site-code', 'Site code'),
            $entry('ai', 'AI'),
            $entry('languages', 'Languages'),
            $entry('updates', 'Updates'),
            $entry('management_api', 'Management API'),
            $entry('fediverse', 'Fediverse'),
        ]);
});

it('shares no settings groups with someone who may manage none', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $request = Request::create('/admin');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn (): User => $editor);

    expect(app(HandleInertiaRequests::class)->share($request)['settingsNav'])->toBe([]);
});

it('keeps a typed secret out of the old input a refused save flashes to the session', function (): void {
    // A group of this test's own: main declares no secret setting.
    app(SettingsRegistry::class)->group(new SettingsGroup(
        key: 'test_secrets',
        label: 'Test secrets',
        description: 'A group for this test.',
        section: 'test_secrets',
        ability: 'settings.general.manage',
        settings: [
            Setting::text('label', 'Label', 'A label for this test.', '0.1.0'),
            Setting::secret('api_key', 'API key', 'A credential.', '0.1.0'),
            Setting::secret('old_key', 'Old key', 'A credential.', '0.1.0'),
        ],
        since: '0.1.0',
    ));

    $this->actingAs($this->admin)->put('/admin/settings/test_secrets', ['values' => ['label' => 'First'], 'expected_version' => 0])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->from('/admin/settings/test_secrets')
        ->put('/admin/settings/test_secrets', [
            'values' => ['label' => 'Second'],
            'secrets' => ['api_key' => 'sk-typed-in-plain-text'],
            'clear_secrets' => ['old_key'],
            'expected_version' => 0,
        ])
        ->assertSessionHasErrors('expected_version');

    $old = (array) session()->get('_old_input');

    expect($old)->not->toHaveKey('secrets')
        ->and($old)->not->toHaveKey('clear_secrets')
        ->and($old['values'])->toBe(['label' => 'Second']);
});
