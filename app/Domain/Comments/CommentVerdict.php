<?php

/**
 * A comment's judgement in words, for the Comments screen's drawer (1.8c's
 * D16): who judged it, its stored reason, and, for core's moderator, the
 * checks it ran in its order, each before the deciding one passed. Core's
 * moderator stops at the first rule that applies and records that rule
 * alone (BuiltInModerator), so the checks after it are never shown.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

final class CommentVerdict
{
    /** The name core's moderator signs its verdicts with (BuiltInModerator::name()). */
    private const string CORE = 'Teme';

    /** Core's moderator's rules, in the order it runs them; the last three are one step. */
    private const array ORDER = ['block-list', 'hold-list', 'links', 'hold-all', 'returning', 'first-comment', 'passed'];

    /**
     * The judgement's byline, verdict, checks and hint.
     *
     * @param  array<string, mixed>|null  $moderation  the comment's stored `moderation`
     * @param  array<mixed>  $body  its document, whose links the Links check counts
     *
     * @return array{byline: string|null, verdict: string, checks: list<array{label: string, state: string, detail: string}>, hint: string|null}
     */
    public static function judgement(?array $moderation, array $body, ?int $legacyId, string $writer, string $itemWord): array
    {
        if ($moderation === null) {
            $verdict = $legacyId !== null ? 'Imported from WordPress with its status, so Teme made no judgement.' : 'No judgement was recorded.';

            return ['byline' => null, 'verdict' => $verdict, 'checks' => [], 'hint' => null];
        }

        $by = is_string($moderation['by'] ?? null) ? $moderation['by'] : '';
        $verdict = is_string($moderation['reason'] ?? null) ? $moderation['reason'] : '';
        $signals = is_array($moderation['signals'] ?? null) ? $moderation['signals'] : [];
        $rule = is_string($signals['rule'] ?? null) ? $signals['rule'] : '';

        if ($by !== self::CORE) {
            return ['byline' => "Judged as it arrived, by {$by}.", 'verdict' => $verdict, 'checks' => [], 'hint' => null];
        }

        $byline = 'Judged as it arrived, by Teme’s built-in moderator. A spam plugin can take its place.';

        if ($rule === 'looks-after') {
            return ['byline' => $byline, 'verdict' => $verdict, 'checks' => [self::check('Signed in', 'ok', "{$writer}, who looks after this {$itemWord}’s comments")], 'hint' => null];
        }

        $decided = array_search($rule, self::ORDER, true);

        if ($decided === false) {
            return ['byline' => $byline, 'verdict' => $verdict, 'checks' => [], 'hint' => null];
        }

        $match = is_string($signals['match'] ?? null) ? $signals['match'] : '';
        $field = is_string($signals['field'] ?? null) ? (BuiltInModerator::FIELDS[$signals['field']] ?? 'the comment') : 'the comment';
        $found = "“{$match}”, in {$field}";
        $links = CommentText::links($body);
        $held = is_int($signals['links'] ?? null) ? $signals['links'] : $links;

        $checks = [
            $decided === 0 ? self::check('Block list', 'bad', $found) : self::check('Block list', 'ok', 'No match'),
            $decided === 1 ? self::check('Hold list', 'warn', $found) : self::check('Hold list', 'ok', 'No match'),
            $decided === 2 ? self::check('Links', 'warn', "{$held} links, enough to hold it") : self::check('Links', 'ok', match ($links) {
                0 => 'None', 1 => '1 link', default => "{$links} links"
            }),
            $decided === 3 ? self::check('Hold every comment', 'warn', 'On') : self::check('Hold every comment', 'ok', 'Off'),
        ];

        // The checks that ran: each before the deciding one, then it.
        $ran = array_slice($checks, 0, min($decided, 3) + 1);

        if ($rule === 'returning') {
            $ran[] = self::check('Returning commenter', 'ok', 'Yes: an approved comment from this email address and this browser');
        } elseif ($rule === 'first-comment') {
            $ran[] = self::check('Returning commenter', 'warn', 'No: a first comment, or the first from this browser');
        }

        return ['byline' => $byline, 'verdict' => $verdict, 'checks' => $ran, 'hint' => 'Checked in this order. The first that applies decides.'];
    }

    /**
     * @return array{label: string, state: string, detail: string}
     */
    private static function check(string $label, string $state, string $detail): array
    {
        return ['label' => $label, 'state' => $state, 'detail' => $detail];
    }
}
