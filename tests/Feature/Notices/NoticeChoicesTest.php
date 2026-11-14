<?php

declare(strict_types=1);

use App\Domain\Comments\CommentEmails;
use App\Domain\Comments\Queries\CommentRecipients;
use App\Domain\Notices\NoticePreferences;
use App\Domain\Notices\Queries\NoticeChoices;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\Fixtures\Notices\TwoChannelKind;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->choices = fn (): NoticeChoices => app(NoticeChoices::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('sends the moderators’ email to an editor and an author until they choose none', function (): void {
    foreach ([$this->editor, $this->author] as $person) {
        expect(($this->choices)()->channelsFor($person, 'comments.waiting'))->toBe(['mail']);
    }

    lscrPrefer($this->editor, [NoticePreferences::PREFIX.'comments.waiting' => []]);

    expect(($this->choices)()->channelsFor($this->editor->refresh(), 'comments.waiting'))->toBe([])
        ->and(($this->choices)()->wants($this->author, 'comments.waiting'))->toBeTrue();
});

it('words the choice for an author as their own posts and an editor’s as every comment', function (): void {
    $row = fn (User $person): string => collect(($this->choices)()->forScreen($person))->firstWhere('key', 'notifications.comments.waiting')['label'];

    expect($row($this->author))->toBe('Email me when a comment on my posts and pages waits for approval')
        ->and($row($this->editor))->toBe('Email me when a comment waits for approval');
});

it('does not offer or send the author’s email to someone without content.create (no role)', function (): void {
    $contributor = User::factory()->create();

    expect(collect(($this->choices)()->forScreen($contributor))->pluck('key')->all())->toBe([])
        ->and(($this->choices)()->channelsFor($contributor, 'comments.approved'))->toBe([])
        ->and(($this->choices)()->channelsFor($this->author, 'comments.approved'))->toBe(['mail']);
});

it('sends nothing while Settings › Discussion has the email off, and lists it disabled with the person’s choice untouched', function (): void {
    lscrPrefer($this->editor, [NoticePreferences::PREFIX.'comments.approved' => []]);
    cmSettings(['email_moderators' => false, 'email_authors' => false]);

    $rows = collect(($this->choices)()->forScreen($this->editor->refresh()))->keyBy('key');

    expect(($this->choices)()->channelsFor($this->editor, 'comments.waiting'))->toBe([])
        ->and($rows['notifications.comments.waiting']['disabled'])->toBeTrue()
        ->and($rows['notifications.comments.waiting']['switchedOff'])->toBe('Settings › Discussion has turned this email off for everyone.')
        ->and($rows['notifications.comments.waiting']['channels'][0]['on'])->toBeTrue()
        ->and($rows['notifications.comments.approved']['channels'][0]['on'])->toBeFalse()
        ->and(($this->choices)()->keysFor($this->editor))->toBe([]);
});

it('offers and sends nothing while comments are off', function (): void {
    cmSettings(['comments' => 'off'], 'modules');

    expect(($this->choices)()->forScreen($this->editor))->toBe([])
        ->and(($this->choices)()->channelsFor($this->editor, 'comments.waiting'))->toBe([]);
});

it('gives an unverified or suspended account nothing but the account emails', function (): void {
    $unverified = User::factory()->unverified()->create()->assignRole('administrator');
    $suspended = User::factory()->create(['suspended_at' => now()])->assignRole('administrator');

    foreach ([$unverified, $suspended] as $person) {
        foreach (['comments.waiting', 'comments.approved', 'backups.alert', 'updates.update'] as $kind) {
            expect(($this->choices)()->channelsFor($person, $kind))->toBe([]);
        }

        foreach (['users.invitation', 'users.password_changed', 'users.password_reset'] as $kind) {
            expect(($this->choices)()->channelsFor($person, $kind))->toBe(['mail']);
        }
    }
});

it('sends a required kind to its audience whatever they stored, and never lists it', function (): void {
    expect(($this->choices)()->channelsFor($this->admin, 'backups.alert'))->toBe(['mail'])
        ->and(($this->choices)()->channelsFor($this->author, 'backups.alert'))->toBe([])
        ->and(collect(($this->choices)()->forScreen($this->admin))->pluck('key')->all())->not->toContain('notifications.backups.alert');
});

it('sends an address mail for a required kind and nothing for a listed one', function (): void {
    $address = new AnonymousNotifiable;

    expect(($this->choices)()->channelsFor($address, 'users.password_reset'))->toBe(['mail'])
        ->and(($this->choices)()->channelsFor($address, 'comments.waiting'))->toBe([])
        ->and(($this->choices)()->channelsFor($address, 'no.such_kind'))->toBe([]);
});

it('keeps the channels a person chose, in the kind’s order, for a two-channel kind', function (): void {
    TwoChannelKind::register();

    expect(($this->choices)()->channelsFor($this->author, TwoChannelKind::KEY))->toBe(['mail', 'inbox']);

    lscrPrefer($this->author, [NoticePreferences::PREFIX.TwoChannelKind::KEY => ['inbox']]);

    expect(($this->choices)()->channelsFor($this->author, TwoChannelKind::KEY))->toBe(['inbox']);

    $row = collect(($this->choices)()->forScreen($this->author))->firstWhere('key', 'notifications.'.TwoChannelKind::KEY);

    expect($row['channels'])->toBe([['key' => 'mail', 'label' => 'Email', 'on' => false], ['key' => 'inbox', 'label' => 'Inbox', 'on' => true]]);
});

it('leaves a moderator who chose none out of the comment emails’ recipients', function (): void {
    $item = cmItem($this->author);
    lscrPrefer($this->editor, [NoticePreferences::PREFIX.CommentEmails::WAITING => []]);

    $recipients = collect((new CommentRecipients)->moderators($item, [], CommentEmails::WAITING))->pluck('id')->all();

    expect($recipients)->not->toContain($this->editor->id)
        ->and($recipients)->toContain($this->admin->id);
});

it('declares one list preference per listed kind and none for a required one', function (): void {
    $preferences = PreferencesFor::user($this->author);

    expect($preferences['notifications.comments.waiting'])->toBe(['mail'])
        ->and($preferences)->not->toHaveKey('notifications.backups.alert');
});
