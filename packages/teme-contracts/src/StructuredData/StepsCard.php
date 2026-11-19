<?php

/**
 * One Steps card in an article, for how-to content: its summary, how long it
 * takes, what it needs, and its numbered steps.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\StructuredData;

/**
 * One Steps card in an article, for how-to content: its summary, how long
 * it takes, what it needs, and its numbered steps. Core adds no HowTo
 * markup of its own; a plugin may build one from it.
 *
 * @since 1.1.0
 */
final readonly class StepsCard
{
    /**
     * @param  string  $id  the card's own ID, kept through every export
     * @param  string  $nodeId  an absolute @id for a node about the card: the page's address and NodeIds::card('steps', $id)
     * @param  string  $summary  plain text; empty when it has none
     * @param  string|null  $totalTime  an ISO 8601 duration, such as PT1H30M, or null
     * @param  list<string>  $materials  what it uses up, each plain text
     * @param  list<string>  $tools  what it uses and keeps, each plain text
     * @param  list<Step>  $steps  in order, the first numbered 1
     *
     * @since 1.1.0
     */
    public function __construct(
        public string $id,
        public string $nodeId,
        public string $summary,
        public ?string $totalTime,
        public array $materials,
        public array $tools,
        public array $steps,
    ) {}
}
