<?php

/**
 * What every change to the site's own fields shares (I-3320): who may make
 * it, the search a change of searchable fields rebuilds, and what follows
 * its commit. It declares nothing ManagesTypes has, so an Operation may use both.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Operations\Concerns;

use App\Domain\Contents\Jobs\ReindexSearchJob;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Stored\FieldDefinition;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\Operator;
use App\Domain\Types\TypeAccess;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;
use Throwable;

trait ManagesFields
{
    /**
     * The operator at the server; or a person who holds types.manage, while
     * the host hasn't locked the types: fields are part of their types.
     *
     * @throws AuthorizationException
     */
    private function authorizeFields(OperationInput $input): void
    {
        $actorId = $input->actorId();

        if ($actorId === null) {
            if (Operator::isPresent()) {
                return;
            }

            throw new AuthorizationException('Only a person who may manage types, or the operator at the server, changes them.');
        }

        $actor = User::query()->find($actorId);

        if ($actor === null || ! $actor->can(TypeAccess::MANAGE)) {
            throw new AuthorizationException('Not permitted to change the types.');
        }

        if (TypeAccess::locked()) {
            throw new AuthorizationException(TypeAccess::LOCKED);
        }
    }

    /**
     * Inside the change's transaction: marks the type's live published items
     * as waiting for their search to be built again, as a change of language
     * does, so rebuildSearch() after commit finds them.
     */
    private function reindexType(string $type): void
    {
        DB::table('contents')
            ->where('type', $type)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->update(['search_config' => null]);
    }

    /**
     * After commit: rebuilds the search of every item reindexType() marked,
     * in the background, as whoever made the change (I-392).
     */
    private function rebuildSearch(?string $actorId): void
    {
        ReindexSearchJob::dispatch(JobActor::of($actorId));
    }

    /**
     * After commit (I-3296): the cache store's copy of the fields, which
     * other processes read, then this process's registry. A cache store
     * that refuses is reported, never thrown: the change has committed.
     */
    private function fieldsChanged(): void
    {
        try {
            app(StoredFields::class)->remember();
        } catch (Throwable $refused) {
            report($refused);
        }

        app(FieldRegistry::class)->refreshStored();
    }

    /**
     * Under the types' lock: the type's stored field with this key, live or
     * removed as asked, its row locked. Any other key, one of another type,
     * or a row that doesn't read is no field a person can change here.
     *
     * @throws ValidationException
     */
    private function storedField(string $type, string $key, bool $removed): FieldDefinition
    {
        try {
            $field = app(StoredFields::class)->find($key, forUpdate: true);
        } catch (InvalidArgumentException) {
            $field = null;
        }

        if ($field === null || $field->type !== $type || ($field->removedAt !== null) !== $removed) {
            throw ValidationException::withMessages(['key' => __('There is no field :key.', ['key' => $key])]);
        }

        return $field;
    }

    /**
     * Whether a field's change alters what its items are found by: its
     * search turned on or off, a searchable field restored, or a searchable
     * choice's labels changed.
     */
    private static function searchChanges(FieldDefinition $current, FieldDefinition $next): bool
    {
        if ($current->search !== $next->search) {
            return true;
        }

        if (! $next->search) {
            return false;
        }

        return $current->removedAt !== null || $current->choices() !== $next->choices();
    }
}
