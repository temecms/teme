<?php

declare(strict_types=1);

namespace App\Http\Admin\Users;

use App\Domain\Users\Operations\ChangeUserRole;
use App\Domain\Users\Operations\DeleteUser;
use App\Domain\Users\Operations\Input\ChangeUserRoleInput;
use App\Domain\Users\Operations\Input\DeleteUserInput;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\Input\RemovePictureInput;
use App\Domain\Users\Operations\Input\ResendInvitationInput;
use App\Domain\Users\Operations\Input\RevokeInvitationInput;
use App\Domain\Users\Operations\Input\SuspendUserInput;
use App\Domain\Users\Operations\InviteUser;
use App\Domain\Users\Operations\RemovePicture;
use App\Domain\Users\Operations\ResendInvitation;
use App\Domain\Users\Operations\RevokeInvitation;
use App\Domain\Users\Operations\SuspendUser;
use App\Domain\Users\Queries\RoleOptions;
use App\Domain\Users\Queries\UserList;
use App\Domain\Users\Roles;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The People screen. It lists everyone by view and hands each action to its
 * Operation; it changes nothing itself, and answers on the view the page was
 * on.
 */
final class UserController extends Controller
{
    public function index(Request $request, UserList $users, RoleOptions $roles): Response
    {
        $screen = $users->screen($request->string('view')->toString());

        return Inertia::render('users/index', [
            'view' => $screen['view'],
            'tabs' => $screen['tabs'],
            'users' => $screen['users'],
            'reassignOptions' => $screen['reassignOptions'],
            'roles' => $roles->all(),
            // The invitation link's lifetime, from config/auth.php, in whole days.
            'invitationDays' => intdiv((int) config('auth.passwords.invitations.expire'), 60 * 24),
            'currentUserId' => (int) $request->user()?->getAuthIdentifier(),
        ]);
    }

    public function store(Request $request, InviteUser $inviteUser): RedirectResponse
    {
        $inviteUser->execute(new InviteUserInput(
            name: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
            role: $request->string('role')->toString(),
            actorId: $this->actorId($request),
        ));

        // As InviteUser stores it.
        $email = Str::lower(trim($request->string('email')->toString()));

        return $this->done(__('Invitation sent to :email.', ['email' => $email]), view: 'invited');
    }

    public function updateRole(Request $request, int $user, ChangeUserRole $changeUserRole): RedirectResponse
    {
        /** @var array{name: string, role: string, changed: bool, invited: bool} $changed */
        $changed = $changeUserRole->execute(new ChangeUserRoleInput((string) $user, $request->string('role')->toString(), $this->actorId($request)));
        $words = ['name' => $changed['name'], 'role' => $this->roleWords($changed['role'])];

        return match (true) {
            ! $changed['changed'] => $this->done(__(':name is already :role.', $words), 'info'),
            $changed['invited'] => $this->done(__('Role changed. :name will be :role once they accept.', $words)),
            default => $this->done(__('Role changed. :name is now :role and was signed out everywhere.', $words)),
        };
    }

    public function suspend(Request $request, int $user, SuspendUser $suspendUser): RedirectResponse
    {
        $suspendUser->execute(new SuspendUserInput((string) $user, true, $this->actorId($request)));

        return $this->done(__('Suspended. Their sessions have ended.'));
    }

    public function reactivate(Request $request, int $user, SuspendUser $suspendUser): RedirectResponse
    {
        $suspendUser->execute(new SuspendUserInput((string) $user, false, $this->actorId($request)));

        return $this->done(__('Reactivated.'));
    }

    public function resendInvitation(Request $request, int $user, ResendInvitation $resendInvitation): RedirectResponse
    {
        /** @var array{email: string} $sent */
        $sent = $resendInvitation->execute(new ResendInvitationInput((string) $user, $this->actorId($request)));

        return $this->done(__('Sent the invitation to :email again. The old link no longer works.', ['email' => $sent['email']]));
    }

    public function revokeInvitation(Request $request, int $user, RevokeInvitation $revokeInvitation): RedirectResponse
    {
        /** @var array{email: string} $revoked */
        $revoked = $revokeInvitation->execute(new RevokeInvitationInput((string) $user, $this->actorId($request)));

        return $this->done(__('Revoked the invitation to :email.', ['email' => $revoked['email']]));
    }

    public function destroy(Request $request, int $user, DeleteUser $deleteUser): RedirectResponse
    {
        $reassignTo = $request->input('reassign_to');

        $deleteUser->execute(new DeleteUserInput(
            (string) $user,
            $reassignTo === null || $reassignTo === '' ? null : (string) $reassignTo,
            $this->actorId($request),
        ));

        return $this->done(__('Deleted.'));
    }

    public function removePicture(Request $request, int $user, RemovePicture $removePicture): RedirectResponse
    {
        /** @var array{name: string, removed: bool} $removed */
        $removed = $removePicture->execute(new RemovePictureInput((string) $user, $this->actorId($request)));

        return $removed['removed']
            ? $this->done(__('Removed the picture of :name.', ['name' => $removed['name']]))
            : $this->done(__(':name has no picture.', ['name' => $removed['name']]), 'info');
    }

    private function actorId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    /**
     * A role's label inside a sentence: lower case, with its article, as in
     * "an editor".
     */
    private function roleWords(string $role): string
    {
        $label = mb_strtolower(__(Roles::LABELS[$role] ?? $role));

        return (preg_match('/^[aeiou]/', $label) === 1 ? 'an ' : 'a ').$label;
    }

    /**
     * The view the page was on: the previous address's `view`, only when that
     * address was this screen and the view one of its own; nothing else the
     * previous address carries is kept, as on the Comments screen.
     */
    private function previousView(): ?string
    {
        $previous = url()->previous();

        if (parse_url($previous, PHP_URL_PATH) !== parse_url(route('users.index'), PHP_URL_PATH)) {
            return null;
        }

        $query = [];
        parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);
        $view = $query['view'] ?? null;

        return is_string($view) && $view !== 'all' && in_array($view, UserList::VIEWS, true) ? $view : null;
    }

    private function done(string $message, string $type = 'success', ?string $view = null): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        $view ??= $this->previousView();

        return to_route('users.index', $view === null ? [] : ['view' => $view]);
    }
}
