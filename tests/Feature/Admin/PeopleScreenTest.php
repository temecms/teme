<?php

declare(strict_types=1);

use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\Input\ResendInvitationInput;
use App\Domain\Users\Operations\InviteUser;
use App\Domain\Users\Operations\ResendInvitation;
use App\Domain\Users\Queries\UserList;
use App\Domain\Users\Roles;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00:00'));

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->author = User::factory()->create(['name' => 'Bea Author'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->suspended = User::factory()->suspended()->create(['name' => 'Sam Suspended'])->assignRole('author');

    // Ivy is invited as the screen invites, so the log names Ada and a link is held.
    $this->withElevation();
    $result = app(InviteUser::class)->execute(new InviteUserInput(name: 'Ivy Invited', email: 'ivy@example.com', role: 'contributor', actorId: (string) $this->admin->id, deliver: false));
    $this->invited = User::query()->findOrFail($result['userId']);
    $this->app->instance(Elevation::class, new DeniedElevation);

    $this->confirmed = ['auth.password_confirmed_at' => now()->getTimestamp()];
    $this->screen = fn (array $query = []) => $this->actingAs($this->admin)->get(route('users.index', $query));
});

it('shows every view, always, in order, each counted as its list is: invitations only under Invited, the suspended under their role too', function (): void {
    ($this->screen)()
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/index')
            ->where('view', 'all')
            ->where('tabs', [
                ['key' => 'all', 'label' => 'All', 'count' => 4],
                ['key' => 'administrator', 'label' => 'Administrators', 'count' => 1],
                ['key' => 'editor', 'label' => 'Editors', 'count' => 1],
                ['key' => 'author', 'label' => 'Authors', 'count' => 2],
                ['key' => 'contributor', 'label' => 'Contributors', 'count' => 0],
                ['key' => 'suspended', 'label' => 'Suspended', 'count' => 1],
                ['key' => 'invited', 'label' => 'Invited', 'count' => 1],
            ]));
});

it('gives every core role a view, most powerful first, between All and Suspended', function (): void {
    expect(UserList::VIEWS)->toBe(['all', ...array_keys(Roles::LABELS), 'suspended', 'invited']);
});

it('lists the people of the view asked for, by name, and All for a view that is not one', function (string $asked, string $view, array $names): void {
    ($this->screen)(['view' => $asked])->assertInertia(fn (Assert $page) => $page
        ->where('view', $view)
        ->where('users', fn ($users): bool => collect($users)->pluck('name')->all() === $names));
})->with([
    'all' => ['all', 'all', ['Ada Admin', 'Bea Author', 'Eve Editor', 'Sam Suspended']],
    'a role, its suspended member too' => ['author', 'author', ['Bea Author', 'Sam Suspended']],
    'a role only an invitation has' => ['contributor', 'contributor', []],
    'suspended' => ['suspended', 'suspended', ['Sam Suspended']],
    'invited' => ['invited', 'invited', ['Ivy Invited']],
    'a view that is not one' => ['owners', 'all', ['Ada Admin', 'Bea Author', 'Eve Editor', 'Sam Suspended']],
]);

it('tells each invitation when it was sent, by whom, and until when its link works', function (): void {
    // A second administrator sends Ivy's again a day later: the first sender stays hers.
    $other = User::factory()->create(['name' => 'Oli Other'])->assignRole('administrator');
    $this->travel(1)->days();
    app(ResendInvitation::class)->execute(new ResendInvitationInput((string) $this->invited->id, (string) $other->id));
    // Made with no log entry and no link, as the operator's or a pruned one can be.
    User::factory()->invited()->create(['name' => 'Kim Unlogged', 'email' => 'kim@example.com'])->assignRole('author');

    ($this->screen)(['view' => 'invited'])->assertInertia(fn (Assert $page) => $page
        ->where('users.0.name', 'Ivy Invited')
        ->where('users.0.invitedAt', CarbonImmutable::parse('2026-10-14 09:00:00')->toIso8601String())
        ->where('users.0.invitedBy', 'Ada Admin')
        ->where('users.0.linkWorksUntil', CarbonImmutable::parse('2026-10-22 09:00:00')->toIso8601String())
        ->where('users.1.name', 'Kim Unlogged')
        ->where('users.1.invitedBy', null)
        ->where('users.1.linkWorksUntil', null));

    // Past the link's seven days: it no longer works.
    $this->travel(8)->days();
    // The first request after the clock jumps is answered with a redirect to its own address.
    ($this->screen)(['view' => 'invited'])->assertRedirect();

    ($this->screen)(['view' => 'invited'])->assertInertia(fn (Assert $page) => $page
        ->where('users.0.name', 'Ivy Invited')
        ->where('users.0.linkWorksUntil', null));
});

it('offers every account, and never an invitation, as whom to give content to, whatever the view', function (): void {
    ($this->screen)(['view' => 'contributor'])->assertInertia(fn (Assert $page) => $page
        ->where('reassignOptions', [
            ['value' => $this->admin->id, 'label' => 'Ada Admin'],
            ['value' => $this->author->id, 'label' => 'Bea Author'],
            ['value' => $this->editor->id, 'label' => 'Eve Editor'],
            ['value' => $this->suspended->id, 'label' => 'Sam Suspended'],
        ])
        ->where('invitationDays', 7));
});

it('sends a new invitation to the Invited view, naming the address', function (): void {
    $this->actingAs($this->admin)->withSession($this->confirmed)->from(route('users.index'))
        ->post(route('users.store'), ['name' => 'New Person', 'email' => ' New@Example.com ', 'role' => 'author'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index', ['view' => 'invited']))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation sent to new@example.com.']);
});

it('answers 423 and adds nobody when an invitation asks in place and the password was not confirmed lately', function (): void {
    $this->actingAs($this->admin)->from(route('users.index'))
        ->post(route('users.store'), ['name' => 'Noor New', 'email' => 'noor@example.com', 'role' => 'author'], ['X-Teme-Ask-In-Place' => '1'])
        ->assertStatus(423);

    expect(User::query()->where('email', 'noor@example.com')->exists())->toBeFalse();
});

it('still sends the person to the password page when the form does not ask in place', function (): void {
    $this->actingAs($this->admin)->from(route('users.index'))
        ->post(route('users.store'), ['name' => 'Noor New', 'email' => 'noor@example.com', 'role' => 'author'])
        ->assertRedirect(route('password.confirm'));
});

it('says what a role change did, on the view the page was on', function (string $who, string $role, string $type, string $message): void {
    $person = $who === 'Bea' ? $this->author : $this->invited;

    $this->actingAs($this->admin)->withSession($this->confirmed)->from(route('users.index', ['view' => 'author']))
        ->put(route('users.role.update', ['user' => $person->id]), ['role' => $role])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index', ['view' => 'author']))
        ->assertInertiaFlash('toast', ['type' => $type, 'message' => $message]);
})->with([
    'a person' => ['Bea', 'editor', 'success', 'Role changed. Bea Author is now an editor and was signed out everywhere.'],
    'a person, to a role without a vowel first' => ['Bea', 'contributor', 'success', 'Role changed. Bea Author is now a contributor and was signed out everywhere.'],
    'an invitation' => ['Ivy', 'administrator', 'success', 'Role changed. Ivy Invited will be an administrator once they accept.'],
    'nothing changed' => ['Bea', 'author', 'info', 'Bea Author is already an author.'],
]);

it('answers on All when the page was not on one of its views', function (string $from): void {
    $this->actingAs($this->admin)->withSession($this->confirmed)->from($from)
        ->post(route('users.suspension.store', ['user' => $this->author->id]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));
})->with([
    'no view' => ['/admin/users'],
    'a view that is not one' => ['/admin/users?view=https://elsewhere.example'],
    'another screen' => ['/admin/posts?view=author'],
]);

it('sends an invitation again, naming the address and saying the old link no longer works', function (): void {
    $this->actingAs($this->admin)->from(route('users.index', ['view' => 'invited']))
        ->post(route('users.invitation.store', ['user' => $this->invited->id]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index', ['view' => 'invited']))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Sent the invitation to ivy@example.com again. The old link no longer works.']);
});

it('revokes an invitation from the Invited view without asking for the password, naming the address', function (): void {
    $this->actingAs($this->admin)->from(route('users.index', ['view' => 'invited']))
        ->delete(route('users.invitation.destroy', ['user' => $this->invited->id]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index', ['view' => 'invited']))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Revoked the invitation to ivy@example.com.']);

    expect(User::query()->whereKey($this->invited->id)->exists())->toBeFalse();
});

it('refuses to revoke an account, with the reason, and keeps it', function (): void {
    $this->actingAs($this->admin)->from(route('users.index'))
        ->delete(route('users.invitation.destroy', ['user' => $this->author->id]))
        ->assertSessionHasErrors(['user' => 'Only a pending invitation can be revoked.']);

    expect(User::query()->whereKey($this->author->id)->exists())->toBeTrue();
});

it('forbids revoking to anyone without users.manage, and keeps the invitation', function (): void {
    $this->actingAs($this->editor)
        ->delete(route('users.invitation.destroy', ['user' => $this->invited->id]))
        ->assertForbidden();

    expect(User::query()->whereKey($this->invited->id)->exists())->toBeTrue();
});
