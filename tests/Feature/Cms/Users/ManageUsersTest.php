<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Users\Operations\ChangeUserRole;
use App\Domain\Users\Operations\DeleteUser;
use App\Domain\Users\Operations\Input\ChangeUserRoleInput;
use App\Domain\Users\Operations\Input\DeleteUserInput;
use App\Domain\Users\Operations\Input\SuspendUserInput;
use App\Domain\Users\Operations\SuspendUser;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create()->assignRole('author');
    $this->withElevation();
});

function usersChangeRole(User $actor, User $user, string $role): void
{
    app(ChangeUserRole::class)->execute(new ChangeUserRoleInput((string) $user->id, $role, (string) $actor->id));
}

function usersSuspend(User $actor, User $user, bool $suspended = true): void
{
    app(SuspendUser::class)->execute(new SuspendUserInput((string) $user->id, $suspended, (string) $actor->id));
}

function usersDelete(User $actor, User $user, ?User $reassignTo = null): void
{
    app(DeleteUser::class)->execute(new DeleteUserInput((string) $user->id, $reassignTo === null ? null : (string) $reassignTo->id, (string) $actor->id));
}

function usersAttempt(string $action, User $actor, User $user): void
{
    match ($action) {
        'role' => usersChangeRole($actor, $user, 'administrator'),
        'suspend' => usersSuspend($actor, $user),
        'delete' => usersDelete($actor, $user),
    };
}

function usersWriteSomething(User $author): Content
{
    return app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'post',
        title: 'By '.$author->name,
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        actorId: (string) $author->id,
    ));
}

it('changes a role, replacing the old one, and rotates the remember token', function (): void {
    $token = $this->author->remember_token;

    usersChangeRole($this->admin, $this->author, 'editor');

    $this->author->refresh();
    expect($this->author->getRoleNames()->all())->toBe(['editor'])
        ->and($this->author->remember_token)->not->toBe($token);
});

it('never changes your own role', function (): void {
    expect(fn () => usersChangeRole($this->admin, $this->admin, 'editor'))->toThrow(ValidationException::class);
});

it('never demotes the last active administrator', function (): void {
    // The operator acts with a null actor, so the self-rule does not apply and
    // only the last-administrator guard can refuse.
    expect(fn () => app(ChangeUserRole::class)->execute(new ChangeUserRoleInput((string) $this->admin->id, 'editor', null)))
        ->toThrow(ValidationException::class);

    User::factory()->create()->assignRole('administrator');

    app(ChangeUserRole::class)->execute(new ChangeUserRoleInput((string) $this->admin->id, 'editor', null));

    expect($this->admin->refresh()->hasRole('administrator'))->toBeFalse();
});

it('does not count a suspended administrator as the one who remains', function (): void {
    User::factory()->suspended()->create()->assignRole('administrator');

    expect(fn () => app(SuspendUser::class)->execute(new SuspendUserInput((string) $this->admin->id, true, null)))
        ->toThrow(ValidationException::class);
});

it('suspends and reactivates, but never yourself or the last active administrator', function (): void {
    usersSuspend($this->admin, $this->author);
    expect($this->author->refresh()->suspended_at)->not->toBeNull();

    usersSuspend($this->admin, $this->author, false);
    expect($this->author->refresh()->suspended_at)->toBeNull();

    expect(fn () => usersSuspend($this->admin, $this->admin))->toThrow(ValidationException::class);
});

it('reassigns content and revisions before deleting', function (): void {
    $content = usersWriteSomething($this->author);

    usersDelete($this->admin, $this->author, $this->admin);

    expect(User::query()->find($this->author->id))->toBeNull()
        ->and(Content::query()->findOrFail($content->id)->author_id)->toBe($this->admin->id)
        ->and(ContentRevision::query()->where('author_id', $this->author->id)->exists())->toBeFalse();
});

it('refuses to delete someone with content unless it is reassigned to someone else', function (): void {
    usersWriteSomething($this->author);

    expect(fn () => usersDelete($this->admin, $this->author))->toThrow(ValidationException::class)
        ->and(fn () => usersDelete($this->admin, $this->author, $this->author))->toThrow(ValidationException::class);

    expect(User::query()->find($this->author->id))->not->toBeNull();
});

it('deletes someone with no content without asking for reassignment', function (): void {
    usersDelete($this->admin, $this->author);

    expect(User::query()->find($this->author->id))->toBeNull();
});

it('never deletes yourself', function (): void {
    expect(fn () => usersDelete($this->admin, $this->admin))->toThrow(ValidationException::class);
});

it('writes nothing for someone who may not manage users', function (string $action): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => usersAttempt($action, $editor, $this->author))->toThrow(AuthorizationException::class);

    $this->author->refresh();
    expect($this->author->getRoleNames()->all())->toBe(['author'])
        ->and($this->author->suspended_at)->toBeNull();
})->with(['role', 'suspend', 'delete']);

it('writes nothing without a fresh confirmation', function (string $action): void {
    // Undo beforeEach's withElevation(): back to the default, which denies.
    $this->app->instance(Elevation::class, new DeniedElevation);

    expect(fn () => usersAttempt($action, $this->admin, $this->author))->toThrow(ElevationRequiredException::class);

    $this->author->refresh();
    expect($this->author->getRoleNames()->all())->toBe(['author'])
        ->and($this->author->suspended_at)->toBeNull();
})->with(['role', 'suspend', 'delete']);

it('reassigns a deleted user\'s uploads, and insists on someone to take them', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    $heir = User::factory()->create()->assignRole('editor');
    $media = mediaUpload($this->author, mediaPdf(), 'handbook.pdf');

    expect(fn () => usersDelete($this->admin, $this->author))->toThrow(ValidationException::class)
        ->and(User::query()->find($this->author->id))->not->toBeNull();

    usersDelete($this->admin, $this->author, $heir);

    expect($media->refresh()->uploaded_by)->toBe($heir->id);
});
