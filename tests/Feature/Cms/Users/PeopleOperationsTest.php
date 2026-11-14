<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Users\Operations\AcceptInvitation;
use App\Domain\Users\Operations\ChangeUserRole;
use App\Domain\Users\Operations\Input\AcceptInvitationInput;
use App\Domain\Users\Operations\Input\ChangeUserRoleInput;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\Input\ResendInvitationInput;
use App\Domain\Users\Operations\Input\RevokeInvitationInput;
use App\Domain\Users\Operations\InviteUser;
use App\Domain\Users\Operations\ResendInvitation;
use App\Domain\Users\Operations\RevokeInvitation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // An invitation as the screen makes one: through InviteUser, with its link
    // and its log entry. Its token is kept for the test that accepts it.
    $this->invite = function (string $name = 'Ivy Invited', string $email = 'ivy@example.com', string $role = 'contributor'): User {
        $this->withElevation();
        $result = app(InviteUser::class)->execute(new InviteUserInput(name: $name, email: $email, role: $role, actorId: (string) $this->admin->id, deliver: false));
        $this->token = $result['token'];
        // Back to the default, which refuses every fresh-password check.
        $this->app->instance(Elevation::class, new DeniedElevation);

        return User::query()->findOrFail($result['userId']);
    };

    $this->revoke = fn (User $invited, ?User $actor = null): mixed => app(RevokeInvitation::class)
        ->execute(new RevokeInvitationInput((string) $invited->id, (string) ($actor ?? $this->admin)->id));

    // What revoking removes: the account, its link and its author address.
    $this->leftOf = fn (User $person): array => [
        'account' => User::query()->whereKey($person->id)->exists(),
        'link' => DB::table('password_reset_tokens')->where('email', $person->email)->exists(),
        'address' => DB::table('paths')->where('target_type', 'user')->where('target_id', (string) $person->id)->exists(),
    ];
});

it('revokes a pending invitation without asking for the password: the account, its link and its address go, and the log says who did it', function (): void {
    $invited = ($this->invite)();

    expect(($this->leftOf)($invited))->toBe(['account' => true, 'link' => true, 'address' => true]);

    // The default elevation refuses every check, so this passes only because
    // revoking asks for none.
    expect(($this->revoke)($invited))->toBe(['email' => 'ivy@example.com'])
        ->and(($this->leftOf)($invited))->toBe(['account' => false, 'link' => false, 'address' => false]);

    // The log's user_id is cleared with the account, so the event is found by its subject.
    $logged = json_decode((string) DB::table('security_events')
        ->where('type', 'user.deleted')
        ->where('subject', 'user:'.$invited->id)
        ->value('context'), true);

    expect($logged['invitation_revoked'] ?? null)->toBeTrue()
        ->and($logged['by'] ?? null)->toBe($this->admin->id);
});

it('refuses an invitation that has been accepted, and removes nothing', function (): void {
    $invited = ($this->invite)();
    app(AcceptInvitation::class)->execute(new AcceptInvitationInput('ivy@example.com', $this->token, 'a-brand-new-long-passphrase', 'a-brand-new-long-passphrase'));

    expect(fn () => ($this->revoke)($invited))
        ->toThrow(ValidationException::class, 'Only a pending invitation can be revoked.');

    expect(User::query()->whereKey($invited->id)->exists())->toBeTrue()
        ->and(DB::table('security_events')->where('type', 'user.deleted')->exists())->toBeFalse();
});

it('refuses while content is attributed to them, and removes nothing', function (): void {
    $invited = ($this->invite)(role: 'author');
    lifecycleDraft($invited, 'Harbour news');

    expect(fn () => ($this->revoke)($invited))
        ->toThrow(ValidationException::class, 'Something on the site is attributed to them, so the invitation can’t be revoked. Give it to someone else first.');

    expect(($this->leftOf)($invited))->toBe(['account' => true, 'link' => true, 'address' => true])
        ->and(Content::query()->where('author_id', $invited->id)->count())->toBe(1);
});

it('writes nothing for someone who may not manage users', function (): void {
    $invited = ($this->invite)();
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => ($this->revoke)($invited, $editor))->toThrow(AuthorizationException::class);

    expect(($this->leftOf)($invited))->toBe(['account' => true, 'link' => true, 'address' => true]);
});

it('refuses a null actor outside the operator\'s shell, and writes nothing', function (): void {
    $invited = ($this->invite)();

    expect(fn () => app(RevokeInvitation::class)->execute(new RevokeInvitationInput((string) $invited->id, null)))
        ->toThrow(AuthorizationException::class);

    expect(($this->leftOf)($invited))->toBe(['account' => true, 'link' => true, 'address' => true]);
});

it('says what a role change did: whom, to which role, whether anything changed, and whether they are still invited', function (): void {
    $this->withElevation();
    $author = User::factory()->create(['name' => 'Bea Author'])->assignRole('author');
    $invited = User::factory()->invited()->create(['name' => 'Ivy Invited'])->assignRole('contributor');
    $change = fn (User $person, string $role): mixed => app(ChangeUserRole::class)
        ->execute(new ChangeUserRoleInput((string) $person->id, $role, (string) $this->admin->id));

    expect($change($author, 'editor'))->toEqual(['name' => 'Bea Author', 'role' => 'editor', 'changed' => true, 'invited' => false])
        ->and($change($author, 'editor'))->toEqual(['name' => 'Bea Author', 'role' => 'editor', 'changed' => false, 'invited' => false])
        ->and($change($invited, 'author'))->toEqual(['name' => 'Ivy Invited', 'role' => 'author', 'changed' => true, 'invited' => true]);
});

it('says which address an invitation was sent to again', function (): void {
    $invited = ($this->invite)();

    expect(app(ResendInvitation::class)->execute(new ResendInvitationInput((string) $invited->id, (string) $this->admin->id)))
        ->toBe(['email' => 'ivy@example.com']);
});
