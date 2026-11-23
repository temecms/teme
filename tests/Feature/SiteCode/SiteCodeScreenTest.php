<?php

declare(strict_types=1);

use App\Domain\Navigation\SettingsHub;
use App\Domain\Settings\Queries\SettingsNavigation;
use App\Domain\SiteCode\Models\SiteCodeEntry;
use App\Domain\SiteCode\Operations\DeleteSiteCode;
use App\Domain\SiteCode\Operations\Input\DeleteSiteCodeInput;
use App\Domain\SiteCode\Operations\Input\SwitchSiteCodeInput;
use App\Domain\SiteCode\Operations\SwitchSiteCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->analytics = '<script defer src="https://stats.example.org/script.js"></script>';
    $this->form = ['name' => 'Visitor analytics', 'placement' => 'head', 'code' => '<script defer src="https://stats.example.org/script.js"></script>', 'on' => '1'];
});

it('shows each placement\'s entries with their code as text, who changed each and when, and the newest changes with their before and after (I-866)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-02 10:00:00', 'UTC'));
    $analytics = scAdd($this->admin, 'Visitor analytics', 'head', $this->analytics);
    scAdd($this->admin, 'Organization', 'head', '<script type="application/ld+json">{"@type":"Organization"}</script>', on: false);
    scAdd($this->admin, 'Chat widget', 'body-end', '<script async src="https://chat.example.net/widget.js"></script>');

    $this->actingAs($this->admin)->get('/admin/settings/site-code')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/site-code')
            ->where('locked', false)
            ->where('siteTimezone', 'UTC')
            ->where('places.0.key', 'head')
            ->where('places.0.label', 'Head')
            ->where('places.0.where', 'Inside <head>, on every public page.')
            ->has('places.0.entries', 2)
            ->where('places.0.entries.0', [
                'id' => $analytics->id,
                'name' => 'Visitor analytics',
                'placement' => 'head',
                'code' => $this->analytics,
                'on' => true,
                'structuredData' => false,
                'changedBy' => 'Ada Admin',
                'changedAt' => '2026-06-02T10:00:00+00:00',
                'origins' => [],
                'found' => ['https://stats.example.org'],
            ])
            ->where('places.0.entries.1.on', false)
            ->where('places.0.entries.1.structuredData', true)
            ->where('places.1.key', 'body-start')
            ->has('places.1.entries', 0)
            ->where('places.2.where', 'At the end of <body>, on every public page.')
            ->where('places.2.entries.0.name', 'Chat widget')
            ->has('changes', 3)
            ->where('changes.0.what', 'Added')
            ->where('changes.0.name', 'Chat widget')
            ->where('changes.0.who', 'Ada Admin')
            ->where('changes.0.when', '2026-06-02T10:00:00+00:00')
            ->where('changes.0.before', null)
            ->where('changes.0.after', "End of the body, on\n<script async src=\"https://chat.example.net/widget.js\"></script>")
            ->where('changes.1.after', "Head, off\n<script type=\"application/ld+json\">{\"@type\":\"Organization\"}</script>"));
});

it('lists the eight newest changes, newest first, and calls a deleted person a removed account', function (): void {
    $gone = User::factory()->create(['name' => 'Bo Gone'])->assignRole('administrator');
    $entry = scAdd($this->admin, code: $this->analytics);
    $switch = fn (User $actor, bool $on): array => scElevated(fn (): array => app(SwitchSiteCode::class)->execute(new SwitchSiteCodeInput($entry->id, $on, (string) $actor->id)));

    foreach ([false, true, false, true, false, true, false] as $on) {
        $switch($this->admin, $on);
    }

    $switch($gone, true);
    $gone->delete();

    $this->actingAs($this->admin)->get('/admin/settings/site-code')
        ->assertInertia(fn (Assert $page) => $page
            ->has('changes', 8)
            ->where('changes.0.what', 'Switched on')
            ->where('changes.0.who', 'a removed account')
            ->where('changes.0.before', "Head, off\n".$this->analytics)
            ->where('changes.7.what', 'Switched off')
            ->where('changes.7.who', 'Ada Admin')
            ->where('places.0.entries.0.changedBy', 'a removed account'));
});

it('forbids the screen and every change to anyone without site-code.manage, and writes nothing (I-850)', function (): void {
    $entry = scAdd($this->admin);
    $editor = User::factory()->create()->assignRole('editor');
    $as = fn () => $this->actingAs($editor)->withSession(scConfirmed());

    $as()->get('/admin/settings/site-code')->assertForbidden();
    $as()->post('/admin/settings/site-code', $this->form)->assertForbidden();
    $as()->put("/admin/settings/site-code/{$entry->id}", [...$this->form, 'name' => 'Renamed'])->assertForbidden();
    $as()->put("/admin/settings/site-code/{$entry->id}/switch", ['on' => '0'])->assertForbidden();
    $as()->delete("/admin/settings/site-code/{$entry->id}")->assertForbidden();

    expect(SiteCodeEntry::query()->sole()->only(['name', 'enabled']))->toBe(['name' => 'Visitor analytics', 'enabled' => true])
        ->and(count(scLog()))->toBe(1);
});

it('adds, changes, switches and deletes through the Operations, each toast saying what pages now draw', function (): void {
    $as = fn () => $this->actingAs($this->admin)->withSession(scConfirmed())->from('/admin/settings/site-code');

    $as()->post('/admin/settings/site-code', $this->form)
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/settings/site-code')
        ->assertInertiaFlash('toast.message', 'Code added. Every public page includes “Visitor analytics” in the head.');

    $entry = SiteCodeEntry::query()->sole();
    $changed = [...$this->form, 'placement' => 'body-end', 'on' => '0'];

    $as()->put("/admin/settings/site-code/{$entry->id}", $changed)
        ->assertInertiaFlash('toast.message', 'Code saved. “Visitor analytics” is off, so no page includes it yet.');
    $as()->put("/admin/settings/site-code/{$entry->id}", $changed)
        ->assertInertiaFlash('toast.message', 'Nothing changed.');
    $as()->put("/admin/settings/site-code/{$entry->id}/switch", ['on' => '1'])
        ->assertInertiaFlash('toast.message', 'Switched on. Every public page includes “Visitor analytics” again.');
    $as()->put("/admin/settings/site-code/{$entry->id}/switch", ['on' => '0'])
        ->assertInertiaFlash('toast.message', 'Switched off. No page includes “Visitor analytics” until it’s on again.');
    $as()->delete("/admin/settings/site-code/{$entry->id}")
        ->assertRedirect('/admin/settings/site-code')
        ->assertInertiaFlash('toast.message', 'Code deleted. No page includes “Visitor analytics” any more.');

    expect(array_column(scLog(), 'type'))->toBe(['site_code.added', 'site_code.changed', 'site_code.switched_on', 'site_code.switched_off', 'site_code.deleted'])
        ->and(SiteCodeEntry::query()->count())->toBe(0);
});

it('asks for the password before any change, sends the person back to Site code afterwards, and writes nothing meanwhile (I-864)', function (): void {
    $this->actingAs($this->admin)
        ->from('/admin/settings/site-code')
        ->post('/admin/settings/site-code', $this->form)
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHas('url.intended', url('/admin/settings/site-code'));

    expect(SiteCodeEntry::query()->count())->toBe(0)
        ->and(scLog())->toBe([]);
});

it('answers 423, writing nothing, when a save asks in place and the password lapsed, wildcard hosts included', function (): void {
    $this->actingAs($this->admin)
        ->from('/admin/settings/site-code')
        ->post('/admin/settings/site-code', [...$this->form, 'origins' => '*.kraftysprouts.com'], ['X-Teme-Ask-In-Place' => '1'])
        ->assertStatus(423);

    expect(SiteCodeEntry::query()->count())->toBe(0)
        ->and(scLog())->toBe([]);
});

it('saves a wildcard host and reports a refused one under origins once the password is fresh', function (): void {
    $as = fn () => $this->actingAs($this->admin)->withSession(scConfirmed())->from('/admin/settings/site-code');

    $as()->post('/admin/settings/site-code', [...$this->form, 'origins' => "*.kraftysprouts.com\nplausible.io"])
        ->assertRedirect('/admin/settings/site-code')
        ->assertSessionHasNoErrors();
    expect(SiteCodeEntry::query()->sole()->origins)->toBe(['*.kraftysprouts.com', 'plausible.io']);

    $as()->post('/admin/settings/site-code', [...$this->form, 'origins' => 'https://x.io'])
        ->assertSessionHasErrors('origins');
    expect(SiteCodeEntry::query()->count())->toBe(1);
});

it('puts each refusal under its own field', function (): void {
    $entry = scAdd($this->admin);
    $as = fn () => $this->actingAs($this->admin)->withSession(scConfirmed())->from('/admin/settings/site-code');

    $as()->post('/admin/settings/site-code', [...$this->form, 'name' => '', 'code' => ''])
        ->assertSessionHasErrors([
            'name' => 'Give the code a name, so everyone can tell what it is.',
            'code' => 'Paste the code to add, or cancel.',
        ]);

    scElevated(fn (): array => app(DeleteSiteCode::class)->execute(new DeleteSiteCodeInput($entry->id, (string) $this->admin->id)));

    $as()->put("/admin/settings/site-code/{$entry->id}", $this->form)
        ->assertSessionHasErrors(['entry' => 'This code was deleted while you had it open.']);
});

it('shows every entry read-only while the host disallows site code, and refuses a change without asking for a password (I-866)', function (): void {
    $entry = scAdd($this->admin);
    config(['teme.disallow_site_code' => true]);

    $this->actingAs($this->admin)->get('/admin/settings/site-code')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locked', true)
            ->where('places.0.entries.0.name', 'Visitor analytics')
            ->where('places.0.entries.0.on', true));

    $this->actingAs($this->admin)->from('/admin/settings/site-code')
        ->put("/admin/settings/site-code/{$entry->id}/switch", ['on' => '0'])
        ->assertForbidden();

    expect($entry->refresh()->enabled)->toBeTrue();
});

it('gives Site code a tab for whoever holds site-code.manage, even alone, opening on its own page (I-863)', function (): void {
    $keeper = User::factory()->create();
    $keeper->givePermissionTo('site-code.manage');
    $editor = User::factory()->create()->assignRole('editor');
    $tabs = fn (User $user): array => app(SettingsNavigation::class)->forUser($user->id);
    $adminTabs = $tabs($this->admin);

    $siteCode = ['key' => 'site-code', 'label' => 'Site code', 'href' => '/admin/settings/site-code', 'heading' => 'Site', 'plugin' => false];

    expect($adminTabs)->toContain($siteCode)
        ->and($tabs($keeper))->toBe([$siteCode])
        ->and(array_column($tabs($editor), 'key'))->not->toContain('site-code')
        ->and(app(SettingsHub::class)->firstHrefFor($keeper))->toBe('/admin/settings');

    $this->actingAs($keeper)->get('/admin/settings/site-code')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/site-code'));
});

it('offers a fourth place, for code a card puts in a theme region, with its words (I-3127)', function (): void {
    scAdd($this->admin, 'Sidebar ad', 'placed', '<div>Ad</div>');

    $this->actingAs($this->admin)->get('/admin/settings/site-code')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('places', 4)
            ->where('places.3.key', 'placed')
            ->where('places.3.label', 'Placed with a card')
            ->where('places.3.where', 'Nowhere by itself. Editors place it in a theme region with the Custom HTML card.')
            ->where('places.3.entries.0.name', 'Sidebar ad'));
});
