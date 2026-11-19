<?php

/**
 * Withdraws a pending invitation: its link stops working, and the account
 * that waited for it goes, with its author address. Only someone who may
 * manage users, or the operator, can do it; only while the invitation waits,
 * and never once anything is attributed to the person. It grants nothing,
 * so, like sending again, it asks for no fresh password. Someone a byline
 * names stays as a person with no account, with their page (issue 396).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Media\Models\Media;
use App\Domain\People\Operations\Concerns\KeepsDepartedWriters;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Operations\Concerns\ManagesUsers;
use App\Domain\Users\Operations\Input\RevokeInvitationInput;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class RevokeInvitation extends BaseOperation
{
    use KeepsDepartedWriters;
    use ManagesUsers;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeUserManagement($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RevokeInvitationInput $input */
        $user = User::query()->find($input->userId);

        if ($user === null || $user->invited_at === null) {
            throw ValidationException::withMessages(['user' => __('Only a pending invitation can be revoked.')]);
        }
    }

    /**
     * @return array{email: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var RevokeInvitationInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->userId);

        // Checked again under the lock, which AcceptInvitation and ResetPassword
        // take too: an invitation accepted meanwhile is an account, never
        // removed as one.
        if ($user->invited_at === null) {
            throw ValidationException::withMessages(['user' => __('Only a pending invitation can be revoked.')]);
        }

        // The database refuses to orphan their work (I-102); an author change
        // made while this lock is held waits for it, then fails on the
        // database's foreign key.
        if ($this->hasWork((string) $user->id)) {
            throw ValidationException::withMessages(['user' => __('Something on the site is attributed to them, so the invitation can’t be revoked. Give it to someone else first.')]);
        }

        $shown = Footprints::person($user);
        $kept = $this->isCredited((int) $user->id) ? $this->creditByName((int) $user->id) : null;

        if ($kept !== null) {
            $this->keepAsPerson($user, $kept);
        }

        Password::broker('invitations')->deleteToken($user);

        app(SecurityLog::class)->record(SecurityEventType::UserDeleted, $user->id, context: [
            'invitation_revoked' => true,
            'by' => $input->actorId() === null ? 'operator' : (int) $input->actorId(),
        ]);

        $user->delete();

        if ($kept === null) {
            app(PathProjection::class)->forget('user', (string) $user->id);
        }

        // A "not found" page may be stored at the author address they held (I-263).
        $this->purgeAfterCommit($kept === null
            ? new CacheKeysFor(CacheKeysFor::PERSON, (string) $user->id, $shown)
            : new CacheKeysFor(CacheKeysFor::PERSON, $kept, [...$shown, ...Footprints::guest($kept)]));

        return ['email' => $user->email];
    }

    private function hasWork(string $userId): bool
    {
        return Content::query()->where('author_id', $userId)->exists()
            || ContentRevision::query()->where('author_id', $userId)->exists()
            || Media::withTrashed()->where('uploaded_by', $userId)->exists();
    }
}
