<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Fielded;

use Teme\Contracts\Comments\CommentCandidate;
use Teme\Contracts\Comments\HasCommentCheck;
use Teme\Contracts\Comments\ModerationVerdict;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin that fills the comment-check slot and declares one hidden
 * field. The tests read the signals each check was given.
 */
final class Fielded implements HasCommentCheck, Plugin
{
    /** @var list<array<string, string>> */
    public static array $signals = [];

    public static function reset(): void
    {
        self::$signals = [];
    }

    public function register(Hooks $hooks): void {}

    public function check(CommentCandidate $comment): ?ModerationVerdict
    {
        self::$signals[] = $comment->signals;

        return null;
    }
}
