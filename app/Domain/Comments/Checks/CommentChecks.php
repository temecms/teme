<?php

/**
 * Asks the plugin filling the comment-check slot about a comment before it
 * is saved (docs/guides/developers/plugins/comment-check.md), within a time
 * budget, failing open: no opinion when it is slow, fails, or is not granted
 * comments.check. Verdicts merge strictest first, so spam beats pending and
 * pending beats approved.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Checks;

use App\Domain\Plugins\Seo\SeoPlugin;
use App\Domain\Plugins\Seo\SeoPlugins;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Plugins\Slots\SlotProviders;
use Teme\Contracts\Comments\CommentCandidate;
use Teme\Contracts\Comments\HasCommentCheck;
use Teme\Contracts\Comments\ModerationVerdict;
use Teme\Contracts\Plugins\Capability;

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
     * Whether a plugin fills the comment-check slot, so a check is worth asking; reads one row and nothing else.
     */
    public function wanted(): bool
    {
        return $this->providers->chosenFor(PluginSlots::COMMENT_CHECK) !== null;
    }

    /**
     * The field the chosen plugin declares for the comment form, or null when
     * none does; the form draws it and the check is given its value.
     *
     * @return array{name: string, make: string, value: string}|null
     */
    public function field(): ?array
    {
        $plugin = $this->chosen();

        return $plugin === null ? null : CommentField::of($plugin->manifest);
    }

    /**
     * The chosen plugin's verdict on the comment, or null for no opinion. The
     * verdict names the plugin in its signals, as checkedBy.
     */
    public function verdict(CommentCandidate $comment): ?ModerationVerdict
    {
        $plugin = $this->chosen();

        if ($plugin === null) {
            return null;
        }

        $running = $plugin->running;

        // A failure, a late answer or a skipped plugin is recorded by SeoPlugins::ask; no opinion then.
        $answered = $this->plugins->ask($plugin, self::SOCKET, HasCommentCheck::class, self::CALL_BUDGET_MS, function () use ($running, $comment): ?ModerationVerdict {
            $answer = $running->check($comment);

            return $answer instanceof ModerationVerdict ? self::known($answer) : null;
        });

        return $answered === null ? null : new ModerationVerdict($answered->status, $answered->reason, [...$answered->signals, 'checkedBy' => $plugin->name]);
    }

    /**
     * The enabled plugin filling the comment-check slot, granted comments.check and running its check, or null.
     */
    private function chosen(): ?SeoPlugin
    {
        $chosen = $this->providers->chosenFor(PluginSlots::COMMENT_CHECK);

        if ($chosen === null) {
            return null;
        }

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->name === $chosen && $plugin->grants(Capability::CommentsCheck->value) && $plugin->running instanceof HasCommentCheck) {
                return $plugin;
            }
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

    /**
     * A plugin's verdict with a status core knows; any other status is held as pending, as SubmitComment holds one core's moderator can't name.
     */
    private static function known(ModerationVerdict $verdict): ModerationVerdict
    {
        if (in_array($verdict->status, [ModerationVerdict::APPROVED, ModerationVerdict::PENDING, ModerationVerdict::SPAM], true)) {
            return $verdict;
        }

        return new ModerationVerdict(ModerationVerdict::PENDING, $verdict->reason, [...$verdict->signals, 'answered' => $verdict->status]);
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
