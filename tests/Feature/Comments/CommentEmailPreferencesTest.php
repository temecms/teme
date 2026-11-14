<?php

declare(strict_types=1);

use App\Domain\Comments\CommentEmails;
use App\Domain\Notices\NoticePreferences;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->notifications = fn (User $person): array => $this->actingAs($person)->get(route('notifications.edit'))->viewData('page')['props']['notices'];
    $this->mail = fn (bool $on): array => [['key' => 'mail', 'label' => 'Email', 'on' => $on]];
    $this->approved = ['key' => NoticePreferences::PREFIX.CommentEmails::APPROVED, 'label' => 'Email me when a comment on my posts and pages is approved', 'channels' => ($this->mail)(true), 'disabled' => false, 'switchedOff' => null];
});

it('offers each person the comment emails they can get, in their own words, both on (E7, I-691, I-642)', function (string $role, ?string $waiting): void {
    $person = User::factory()->create()->assignRole($role);
    $expected = $waiting === null ? [$this->approved] : [
        ['key' => NoticePreferences::PREFIX.CommentEmails::WAITING, 'label' => $waiting, 'channels' => ($this->mail)(true), 'disabled' => false, 'switchedOff' => null],
        $this->approved,
    ];

    expect(($this->notifications)($person))->toBe($expected);
})->with([
    'an editor, who looks after every item' => ['editor', 'Email me when a comment waits for approval'],
    'an author, who looks after their own' => ['author', 'Email me when a comment on my posts and pages waits for approval'],
    'a contributor, who looks after none' => ['contributor', null],
]);

it('shows an email disabled while Settings › Discussion has turned it off for everyone, keeping the person\'s own choice (E7, D12)', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    lscrPrefer($editor, [NoticePreferences::PREFIX.CommentEmails::APPROVED => []]);
    cmSettings(['email_moderators' => false, 'email_authors' => false]);

    $off = 'Settings › Discussion has turned this email off for everyone.';

    expect(($this->notifications)($editor))->toBe([
        ['key' => NoticePreferences::PREFIX.CommentEmails::WAITING, 'label' => 'Email me when a comment waits for approval', 'channels' => ($this->mail)(true), 'disabled' => true, 'switchedOff' => $off],
        [...$this->approved, 'channels' => ($this->mail)(false), 'disabled' => true, 'switchedOff' => $off],
    ]);
});

it('offers none while comments are off (E7, I-484)', function (): void {
    cmSettings(['comments' => 'off'], 'modules');

    expect(($this->notifications)(User::factory()->create()->assignRole('editor')))->toBe([]);
});

it('turns an email off for one person from Account › Notifications, never among Preferences’ fields', function (): void {
    $author = User::factory()->create()->assignRole('author');

    $this->actingAs($author)->from(route('notifications.edit'))
        ->patch(route('notifications.update'), ['preferences' => [NoticePreferences::PREFIX.CommentEmails::WAITING => ['']]])
        ->assertRedirect(route('notifications.edit'));

    expect(PreferencesFor::user($author->refresh())[NoticePreferences::PREFIX.CommentEmails::WAITING])->toBe([])
        ->and(PreferencesFor::user($author)[NoticePreferences::PREFIX.CommentEmails::APPROVED])->toBe(['mail']);

    $this->actingAs($author)->get(route('preferences.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('fields', fn ($fields): bool => collect($fields)->pluck('key')->filter(fn (string $key): bool => str_starts_with($key, NoticePreferences::PREFIX))->isEmpty()));
});
