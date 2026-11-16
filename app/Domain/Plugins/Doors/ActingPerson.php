<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Users\Actors;
use Illuminate\Support\Facades\Auth;
use Teme\Contracts\Content\WriteRefused;

/**
 * Who a plugin may write for: only the person on CurrentJobActor when a job
 * is running, otherwise the signed-in person of the current request.
 */
final class ActingPerson
{
    /**
     * Refuses when the ID is not all digits, is not the current actor, or
     * names nobody.
     *
     * @throws WriteRefused
     */
    public static function require(string $actingPersonId): void
    {
        if (! ctype_digit($actingPersonId)) {
            throw new WriteRefused(__('A plugin writes on behalf of a person, and none was named.'));
        }

        $current = self::currentId();

        if ($current === null || $actingPersonId !== $current) {
            throw new WriteRefused(__('A plugin writes only for the person who started this work.'));
        }

        if (app(Actors::class)->find($actingPersonId) === null) {
            throw new WriteRefused(__('A plugin writes on behalf of a person, and none was named.'));
        }
    }

    /**
     * The job's person when CurrentJobActor holds one; otherwise the
     * signed-in request person.
     */
    private static function currentId(): ?string
    {
        $job = app(CurrentJobActor::class)->get();

        if ($job !== null) {
            return $job->isOperator() ? null : $job->userId;
        }

        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
