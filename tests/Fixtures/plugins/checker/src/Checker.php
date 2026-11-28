<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Checker;

use Closure;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Comments\CommentCandidate;
use Teme\Contracts\Comments\HasCommentCheck;
use Teme\Contracts\Comments\ModerationVerdict;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin that fills the comment-check slot. The tests set its
 * verdict and read which comments it was asked about.
 */
final class Checker implements HasCommentCheck, Plugin
{
    /** @var (Closure(CommentCandidate): ?ModerationVerdict)|null */
    public static ?Closure $verdict = null;

    /** @var list<string> */
    public static array $asked = [];

    /** The database transaction level each check ran at. @var list<int> */
    public static array $levels = [];

    public static function reset(): void
    {
        self::$verdict = null;
        self::$asked = [];
        self::$levels = [];
    }

    public function register(Hooks $hooks): void {}

    public function check(CommentCandidate $comment): ?ModerationVerdict
    {
        self::$asked[] = $comment->text;
        self::$levels[] = DB::transactionLevel();

        return self::$verdict === null ? null : (self::$verdict)($comment);
    }
}
