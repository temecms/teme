<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Fediverse;

use Teme\Contracts\Fediverse\ActivityHandler;

/**
 * Handles Likes, and asks for Follows too, which Teme keeps for itself.
 */
final class LikeHandler implements ActivityHandler
{
    /** @var list<string> */
    public static array $handled = [];

    public function types(): array
    {
        return ['Like', 'Follow'];
    }

    public function handle(array $activity, string $actorUri): void
    {
        self::$handled[] = ($activity['type'] ?? '?').' from '.$actorUri;
    }
}
