<?php

declare(strict_types=1);

namespace Teme\Contracts\StructuredData;

/**
 * One question of an FAQ card and its answer: the answer's words as plain
 * text, and as the HTML the page draws, its links made from the site's
 * address now.
 *
 * @since 1.1.0
 */
final readonly class FaqPair
{
    /**
     * @param  string  $question  plain text
     * @param  string  $answer  the answer's words as plain text
     * @param  string  $answerHtml  the answer as the page draws it: paragraphs, lists, links and emphasis only
     *
     * @since 1.1.0
     */
    public function __construct(
        public string $id,
        public string $question,
        public string $answer,
        public string $answerHtml,
    ) {}
}
