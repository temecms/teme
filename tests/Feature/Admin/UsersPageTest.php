<?php

declare(strict_types=1);

use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Users\Notifications\InvitationLink;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    Notification::fake();
});

/**
 * @return array<string, int>
 */
function usersConfirmed(): array
{
    return ['auth.password_confirmed_at' => now()->getTimestamp()];
}

it('lists everyone with an account, with their role and status, and nothing more of the model', function (): void {
    User::factory()->invited()->create(['name' => 'Ivy Invited'])->assignRole('author');
    User::factory()->suspended()->create(['name' => 'Sam Suspended'])->assignRole('editor');

    $this->actingAs($this->admin)
        ->get('/admin/users')
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/index')
            ->has('users', 2)
            ->where('users.0.name', 'Ada Admin')
            ->where('users.0.role', 'administrator')
            ->where('users.0.status', 'active')
            ->where('users.1.name', 'Sam Suspended')
            ->where('users.1.status', 'suspended')
            ->missing('users.0.password')
            ->missing('users.0.remember_token')
            ->where('roles.0', ['value' => 'administrator', 'label' => 'Administrator'])
            ->where('currentUserId', $this->admin->id));
});

it('flags an invitation whose email failed to send', function (): void {
    $invited = User::factory()->invited()->create(['name' => 'Ivy Invited'])->assignRole('author');
    app(SecurityLog::class)->record(SecurityEventType::InvitationMailFailed, $invited->id);

    $this->actingAs($this->admin)
        ->get('/admin/users?view=invited')
        ->assertInertia(fn (Assert $page) => $page->where('users.0.invitationFailed', true));
});

it('forbids the Users screens to anyone without users.manage', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->get('/admin/users')->assertForbidden();
    $this->actingAs($editor)->post('/admin/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'author'])->assertForbidden();
});

it('invites after confirmation', function (): void {
    $this->actingAs($this->admin)
        ->withSession(usersConfirmed())
        ->post('/admin/users', ['name' => 'New Person', 'email' => 'new@example.com', 'role' => 'author'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index', ['view' => 'invited']));

    Notification::assertSentTo(User::query()->where('email', 'new@example.com')->sole(), InvitationLink::class);
});

it('asks for confirmation before inviting', function (): void {
    $this->actingAs($this->admin)
        ->from('/admin/users')
        ->post('/admin/users', ['name' => 'New Person', 'email' => 'new@example.com', 'role' => 'author'])
        ->assertRedirect(route('password.confirm'));
});

it('changes a role, suspends, reactivates and deletes through the Operations', function (): void {
    $author = User::factory()->create()->assignRole('author');

    $this->actingAs($this->admin)->withSession(usersConfirmed())
        ->put("/admin/users/{$author->id}/role", ['role' => 'editor'])->assertSessionHasNoErrors();
    expect($author->refresh()->hasRole('editor'))->toBeTrue();

    $this->actingAs($this->admin)->withSession(usersConfirmed())
        ->post("/admin/users/{$author->id}/suspension")->assertSessionHasNoErrors();
    expect($author->refresh()->suspended_at)->not->toBeNull();

    $this->actingAs($this->admin)->withSession(usersConfirmed())
        ->delete("/admin/users/{$author->id}/suspension")->assertSessionHasNoErrors();
    expect($author->refresh()->suspended_at)->toBeNull();

    $this->actingAs($this->admin)->withSession(usersConfirmed())
        ->delete("/admin/users/{$author->id}")->assertSessionHasNoErrors();
    expect(User::query()->find($author->id))->toBeNull();
});

it('shows the reason when an action is refused', function (): void {
    $this->actingAs($this->admin)->withSession(usersConfirmed())
        ->delete("/admin/users/{$this->admin->id}")
        ->assertSessionHasErrors('user');
});

it('re-sends a pending invitation', function (): void {
    $invited = User::factory()->invited()->create()->assignRole('author');

    $this->actingAs($this->admin)
        ->post("/admin/users/{$invited->id}/invitation")
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($invited, InvitationLink::class);
});
