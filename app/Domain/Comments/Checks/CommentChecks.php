<?php

/**
 * Asks the plugin filling the comment-check slot about a comment before it
 * is saved (docs/guides/developers/plugins/comment-check.md), within a time
 * budget, failing open: no opinion when it is slow, fails, or is not granted
 * comments.check. Verdicts merge strictest first, so spam beats pending and
 * pending beats approved.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Checks;

use App\Domain\Plugins\Seo\SeoPlugins;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Plugins\Slots\SlotProviders;
use Teme\Contracts\Comments\CommentCandidate;
use Teme\Contracts\Comments\HasCommentCheck;
use Teme\Contracts\Comments\ModerationVerdict;
use Teme\Contracts\Plugins\Capability;
use Throwable;

final class CommentChecks
{
    /** The socket's name in a recorded fault. */
    public const string SOCKET = 'comment-check';

    /** The time a check has to answer, in milliseconds. */
    public const int CALL_BUDGET_MS = 200;

    public function __construct(
        private readonly SeoPlugins $plugins,
        private readonly SlotProviders $providers,
    ) {}

    /**
     * The chosen plugin's verdict on the comment, or null for no opinion.
     */
    public function verdict(CommentCandidate $comment): ?ModerationVerdict
    {
        $chosen = $this->providers->chosenFor(PluginSlots::COMMENT_CHECK);

        if ($chosen === null) {
            return null;
        }

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->name !== $chosen || ! $plugin->grants(Capability::CommentsCheck->value) || ! $plugin->running instanceof HasCommentCheck) {
                continue;
            }

            $running = $plugin->running;

            // A failure is recorded here, once; a late answer or a skipped plugin is SeoPlugins'.
            return $this->plugins->ask($plugin, self::SOCKET, HasCommentCheck::class, self::CALL_BUDGET_MS, function () use ($plugin, $running, $comment): ?ModerationVerdict {
                try {
                    $answer = $running->check($comment);
                } catch (Throwable $failure) {
                    $this->plugins->fault($plugin, self::SOCKET, HasCommentCheck::class, $failure);

                    return null;
                }

                return $answer instanceof ModerationVerdict ? $answer : null;
            });
        }

        return null;
    }

    /**
     * The stricter of two verdicts, where null is no opinion: spam beats
     * pending beats approved.
     */
    public static function strictest(?ModerationVerdict $first, ?ModerationVerdict $second): ?ModerationVerdict
    {
        if ($first === null) {
            return $second;
        }

        if ($second === null) {
            return $first;
        }

        return self::rank($second->status) > self::rank($first->status) ? $second : $first;
    }

    private static function rank(string $status): int
    {
        return match ($status) {
            ModerationVerdict::SPAM => 2,
            ModerationVerdict::PENDING => 1,
            default => 0,
        };
    }
}
