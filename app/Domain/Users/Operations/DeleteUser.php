<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Cache\PageTags;
use App\Domain\Contents\AuthorSort;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Media\Models\Media;
use App\Domain\People\Operations\Concerns\KeepsDepartedWriters;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\AccountSessions;
use App\Domain\Users\Operations\Concerns\ManagesUsers;
use App\Domain\Users\Operations\Input\DeleteUserInput;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Content and its revisions are reassigned first, as WordPress does; the
 * database refuses a delete that would orphan them (I-102). The person's
 * uploads are reassigned first too. A revision's document and fields stay
 * immutable — only its attribution moves, and the security log records to
 * whom. What they wrote keeps their name: a credited writer stays as a
 * person with no account, with their author page (issue 396).
 */
final class DeleteUser extends BaseOperation implements RequiresElevation
{
    use KeepsDepartedWriters;
    use ManagesUsers;

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeUserManagement($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var DeleteUserInput $input */
        $this->refuseSelf($input->actorId(), $input->userId, __('You cannot delete yourself.'));

        Validator::make(
            ['user' => $input->userId, 'reassign_to' => $input->reassignTo],
            [
                'user' => ['required', Rule::exists('users', 'id')],
                'reassign_to' => ['nullable', Rule::exists('users', 'id'), 'different:user'],
            ],
        )->validate();

        $hasWritten = Content::query()->where('author_id', $input->userId)->exists()
            || ContentRevision::query()->where('author_id', $input->userId)->exists()
            || Media::withTrashed()->where('uploaded_by', $input->userId)->exists();

        if ($hasWritten && $input->reassignTo === null) {
            throw ValidationException::withMessages([
                'reassign_to' => __('Choose who should own their content.'),
            ]);
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var DeleteUserInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->userId);

        // Their items by ID, after the user and before any byline, so a byline set meanwhile waits (I-3366).
        DB::select('SELECT id FROM contents WHERE author_id = ? ORDER BY id FOR UPDATE', [$user->id]);

        $this->ensureAnotherAdministratorRemains($user);

        // Their byline, their page and their list; whoever receives their
        // work has more in theirs (I-263).
        $shown = Footprints::person($user);
        $kept = $this->isCredited((int) $user->id) ? $this->creditByName((int) $user->id) : null;

        if ($input->reassignTo !== null) {
            // The query builder, not Eloquent's update(), which would stamp updated_at: reassigning work isn't editing it (I-3131).
            Content::query()->where('author_id', $user->id)->toBase()->update([
                'author_id' => (int) $input->reassignTo,
                'author_sort' => AuthorSort::of(User::query()->whereKey($input->reassignTo)->value('name')),
            ]);
            ContentRevision::query()->where('author_id', $user->id)->update(['author_id' => (int) $input->reassignTo]);
            ContentCounts::moveAuthor((int) $user->id, (int) $input->reassignTo);
            Media::withTrashed()->where('uploaded_by', $user->id)->update(['uploaded_by' => (int) $input->reassignTo]);
        }

        if ($kept !== null) {
            $this->keepAsPerson($user, $kept);
        }

        app(SecurityLog::class)->record(SecurityEventType::UserDeleted, $user->id, context: [
            'reassigned_to' => $input->reassignTo === null ? null : (int) $input->reassignTo,
            'by' => $input->actorId() === null ? 'operator' : (int) $input->actorId(),
        ]);

        AccountSessions::endAllFor($user);
        $user->delete();

        if ($kept === null) {
            app(PathProjection::class)->forget('user', (string) $user->id);
        }

        $receiver = $input->reassignTo === null ? [] : [PageTags::author($input->reassignTo)];
        $this->purgeAfterCommit($kept === null
            ? new CacheKeysFor(CacheKeysFor::PERSON, (string) $user->id, [...$shown, ...$receiver])
            : new CacheKeysFor(CacheKeysFor::PERSON, $kept, [...$shown, ...Footprints::guest($kept), ...$receiver]));

        // No address moves: their items still credit them, so no {author} value changes (I-3366).
        return null;
    }
}
