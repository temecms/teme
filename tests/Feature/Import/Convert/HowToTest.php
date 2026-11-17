<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Yoast's and Rank Math's how-to blocks as the Steps card.
 */

beforeEach(function (): void {
    $this->convert = function (array $blocks): array {
        $conversion = wpConvert($blocks);
        wpAccepted($conversion->document);

        return wpWithoutIds($conversion->document)['content'];
    };
    $this->body = fn (string $text): array => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
});

it('converts Yoast\'s how-to block to the Steps card', function (): void {
    $content = ($this->convert)([wpBlock('yoast/how-to-block', '<div class="schema-how-to wp-block-yoast-how-to-block"><p class="schema-how-to-total-time"><span class="schema-how-to-duration-time-text">Time needed:&nbsp;</span>1 hour 30 minutes</p><p class="schema-how-to-description">Brew a pot of tea.</p> <ol class="schema-how-to-steps"><li class="schema-how-to-step" id="how-to-step-1"><strong class="schema-how-to-step-name">Boil the water</strong> <p class="schema-how-to-step-text">Fresh water.</p> </li><li class="schema-how-to-step" id="how-to-step-2"><strong class="schema-how-to-step-name">Steep</strong> <p class="schema-how-to-step-text">Five minutes.</p> </li></ol></div>', ['hasDuration' => true, 'days' => '', 'hours' => '1', 'minutes' => '30'])]);

    expect($content)->toBe([['type' => 'steps', 'attrs' => [
        'summary' => 'Brew a pot of tea.',
        'totalTime' => 'PT1H30M',
        'materials' => [],
        'tools' => [],
        'steps' => [
            ['title' => 'Boil the water', 'body' => ($this->body)('Fresh water.'), 'mediaId' => null],
            ['title' => 'Steep', 'body' => ($this->body)('Five minutes.'), 'mediaId' => null],
        ],
    ]]]);
});

it('converts Rank Math\'s how-to block to the Steps card, its supplies and tools as lists', function (): void {
    $content = ($this->convert)([wpBlock('rank-math/howto-block', '<div id="rank-math-howto" class="rank-math-block"><div class="rank-math-howto-description"><p>Mend a puncture.</p></div><div class="rank-math-steps "><ul><li id="howto-step-1" class="rank-math-step"><h3 class="rank-math-step-title ">Find the hole</h3><div class="rank-math-step-content "><p>Listen for the hiss.</p></div></li></ul></div></div>', ['supply' => "Patch\nGlue", 'tools' => 'Tyre levers, Pump', 'hasDuration' => true, 'minutes' => '20'])]);

    expect($content)->toBe([['type' => 'steps', 'attrs' => [
        'summary' => 'Mend a puncture.',
        'totalTime' => 'PT20M',
        'materials' => ['Patch', 'Glue'],
        'tools' => ['Tyre levers', 'Pump'],
        'steps' => [['title' => 'Find the hole', 'body' => ($this->body)('Listen for the hiss.'), 'mediaId' => null]],
    ]]]);
});

it('keeps a how-to block as headings and paragraphs when a step holds more than text', function (): void {
    $content = ($this->convert)([wpBlock('rank-math/howto-block', '<div class="rank-math-block"><div class="rank-math-steps"><ul><li class="rank-math-step"><h3 class="rank-math-step-title">Measure</h3><div class="rank-math-step-content"><table><tbody><tr><td>10 cm</td></tr></tbody></table></div></li></ul></div></div>')]);

    expect(array_column($content, 'type'))->toBe(['heading', 'table']);
});

it('reads a how-to block with no steps as any HTML', function (): void {
    $content = ($this->convert)([wpBlock('yoast/how-to-block', '<div class="schema-how-to"><p>Just text</p></div>')]);

    expect($content)->toBe([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Just text']]]]);
});
