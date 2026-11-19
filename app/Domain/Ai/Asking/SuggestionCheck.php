<?php

/**
 * An answer checked twice (I-316): the provider held it to the task's
 * schema; here the task's own check runs, then Teme's for every task: each
 * value present, of a sane length, free of the fence's markup, and one line
 * of text for a target that takes one.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Asking;

use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\AiSubject;
use App\Domain\Ai\AiTask;

final readonly class SuggestionCheck
{
    /** The longest value any task may suggest. */
    public const int MAX_LENGTH = 10000;

    /** An image's alt text can be no longer (UpdateMediaDetails). */
    private const int ALT_LENGTH = 1000;

    /**
     * @return array<string, string>
     *
     * @throws AiFailure an invalid output, when either check fails
     */
    public function values(AiTask $task, AiSubject $subject, AiResult $result): array
    {
        $values = $task->check($result);

        if ($values === []) {
            throw AiFailure::invalidOutput();
        }

        foreach ($values as $value) {
            // Only an echo of the material, or an answer it redirected, holds the fence's markup.
            if (trim($value) === '' || mb_strlen($value) > self::MAX_LENGTH || preg_match('#</?input\b#i', $value) === 1) {
                throw AiFailure::invalidOutput();
            }
        }

        if ($subject->target !== null) {
            $text = $values['text'] ?? null;

            if (array_keys($values) !== ['text'] || $text === null || preg_match('/[\r\n]/', $text) === 1
                || ($subject->target === AiTargets::ALT && mb_strlen($text) > self::ALT_LENGTH)) {
                throw AiFailure::invalidOutput();
            }
        }

        return $values;
    }
}
