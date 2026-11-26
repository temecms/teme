<?php

declare(strict_types=1);

use App\Domain\Comments\CommentEmails;
use App\Domain\Notices\NoticePreferences;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Notices\TwoChannelKind;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->waiting = NoticePreferences::PREFIX.CommentEmails::WAITING;
    $this->approved = NoticePreferences::PREFIX.CommentEmails::APPROVED;
});

it('draws Account › Notifications with a person’s comment emails, in their own words, and the way to their inbox', function (): void {
    $this->actingAs($this->author)->get(route('notifications.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/notifications')
            ->where('notices', [
                ['key' => $this->waiting, 'label' => 'Email me when a comment on my posts and pages waits for approval', 'channels' => [['key' => 'mail', 'label' => 'Email', 'on' => true]], 'disabled' => false, 'switchedOff' => null],
                ['key' => $this->approved, 'label' => 'Email me when a comment on my posts and pages is approved', 'channels' => [['key' => 'mail', 'label' => 'Email', 'on' => true]], 'disabled' => false, 'switchedOff' => null],
            ])
            ->where('inboxHref', '/admin/inbox'));
});

it('saves a person’s choice for one email, and says so', function (): void {
    $this->actingAs($this->author)->from(route('notifications.edit'))
        ->patch(route('notifications.update'), ['preferences' => [$this->waiting => ['']]])
        ->assertRedirect(route('notifications.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Notifications saved.']);

    expect(PreferencesFor::user($this->author->refresh()))->toMatchArray([$this->waiting => [], $this->approved => ['mail']]);
});

it('saves nothing but the emails the person is offered', function (): void {
    $this->actingAs($this->author)->from(route('notifications.edit'))
        ->patch(route('notifications.update'), ['preferences' => [$this->approved => [''], 'editor.spellcheck' => '0']])
        ->assertRedirect(route('notifications.edit'));

    expect(PreferencesFor::user($this->author->refresh()))->toMatchArray([$this->approved => [], 'editor.spellcheck' => true]);
});

it('refuses a save that names no offered email, and changes nothing', function (): void {
    $this->actingAs($this->author)->from(route('notifications.edit'))
        ->patch(route('notifications.update'), ['preferences' => ['editor.spellcheck' => '0']])
        ->assertStatus(422);

    expect(PreferencesFor::user($this->author->refresh())['editor.spellcheck'])->toBeTrue();
});

it('has no row for a required kind and ignores a save that names one', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($admin)->get(route('notifications.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('notices', fn ($rows): bool => collect($rows)->pluck('key')->doesntContain(NoticePreferences::PREFIX.'backups.alert')));

    $this->actingAs($admin)->from(route('notifications.edit'))
        ->patch(route('notifications.update'), ['preferences' => [NoticePreferences::PREFIX.'backups.alert' => ['']]])
        ->assertStatus(422);

    expect($admin->refresh()->preferences)->toBe([]);
});

it('gates Backup alerts on backups.manage, so an administrator sees it and an editor does not', function (): void {
    // Always sent is drawn in the page from shared capabilities; the wrong gate was the notice kind backups.alert (issue 755).
    expect(uiComponent('pages/account/notifications.tsx'))
        ->toContain("capabilities.includes('backups.manage')")
        ->not->toContain("capabilities.includes('backups.alert')");

    $admin = User::factory()->create()->assignRole('administrator');
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($admin)->get(route('notifications.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.capabilities', fn ($caps): bool => collect($caps)->contains('backups.manage')));

    $this->actingAs($editor)->get(route('notifications.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.capabilities', fn ($caps): bool => ! collect($caps)->contains('backups.manage')));
});

it('draws a switched-off row disabled with its line, and keeps its stored choice when the other row is saved', function (): void {
    cmSettings(['email_moderators' => false]);

    $this->actingAs($this->author)->get(route('notifications.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notices.0.disabled', true)
            ->where('notices.0.switchedOff', 'Settings › Discussion has turned this email off for everyone.')
            ->where('notices.1.disabled', false));

    $this->actingAs($this->author)->from(route('notifications.edit'))
        ->patch(route('notifications.update'), ['preferences' => [$this->waiting => [''], $this->approved => ['']]])
        ->assertRedirect(route('notifications.edit'));

    expect(PreferencesFor::user($this->author->refresh()))->toMatchArray([$this->waiting => ['mail'], $this->approved => []]);
});

it('offers no emails while comments are off, and says so on the panel', function (): void {
    cmSettings(['comments' => 'off'], 'modules');

    $this->actingAs($this->author)->get(route('notifications.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('notices', []));
});

it('draws a kind with two channels as one row with both', function (): void {
    TwoChannelKind::register();

    $this->actingAs($this->author)->get(route('notifications.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('notices', fn ($rows): bool => collect($rows)->firstWhere('key', 'notifications.'.TwoChannelKind::KEY)['channels'] === [
            ['key' => 'mail', 'label' => 'Email', 'on' => true],
            ['key' => 'inbox', 'label' => 'Inbox', 'on' => true],
        ]));
});

it('no longer draws the notice choices in Preferences', function (): void {
    $this->actingAs($this->author)->get(route('preferences.edit'))
        ->assertInertia(fn (Assert $page) => $page->missing('notifications'));

    expect(uiComponent('pages/account/preferences.tsx'))->not->toContain('Settings › Discussion decides whether');
});

it('lists Notifications between Preferences and Security in the account’s navigation', function (): void {
    $layout = uiComponent('layouts/account/account-nav.tsx');

    expect($layout)->toContain("{ title: __('Notifications'), href: editNotifications() },")
        ->and(strpos($layout, "title: __('Preferences')"))->toBeLessThan(strpos($layout, "title: __('Notifications')"))
        ->and(strpos($layout, "title: __('Notifications')"))->toBeLessThan(strpos($layout, "title: __('Security')"));
});
