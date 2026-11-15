<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Support\Security\JobActor;
use App\Domain\Users\Actors;
use Illuminate\Support\Facades\Auth;
use Teme\Contracts\Content\WriteRefused;

/**
 * Who a plugin may write for: only the signed-in person of the current
 * request, or the person recorded on the queued job (JobActor). Anything
 * else is refused before a lookup.
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
     * The signed-in person, or the job's person when a JobActor is bound.
     */
    private static function currentId(): ?string
    {
        if (app()->bound(JobActor::class)) {
            $actor = app(JobActor::class);

            return $actor->isOperator() ? null : $actor->userId;
        }

        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
