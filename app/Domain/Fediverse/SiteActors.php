<?php

declare(strict_types=1);

namespace App\Domain\Fediverse;

use App\Domain\Fediverse\Models\FediverseActor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether the site has any fediverse account of its own, made or imported:
 * until it has, the WordPress plugin's addresses on the home page stay the
 * home page's. Kept in the cache, forgotten after the commit that adds or
 * removes an account (rule 6).
 */
final class SiteActors
{
    private const string KEY = 'fediverse:site-actors';

    /**
     * Whether the site has a fediverse account; false while its table doesn't exist yet.
     */
    public static function exist(): bool
    {
        if (Cache::get(self::KEY) === true) {
            return true;
        }

        try {
            $exist = FediverseActor::query()->exists();
        } catch (Throwable) {
            return false;
        }

        if ($exist) {
            Cache::forever(self::KEY, true);
        }

        return $exist;
    }

    /**
     * Forgets the answer once the change that touched an account commits.
     */
    public static function changed(): void
    {
        DB::afterCommit(fn () => Cache::forget(self::KEY));
    }
}
