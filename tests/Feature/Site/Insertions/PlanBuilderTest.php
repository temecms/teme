<?php

declare(strict_types=1);

use App\Domain\Site\Insertions\PlanBuilder;
use Teme\Contracts\Insertions\ArticleFacts;
use Teme\Contracts\Insertions\Insertion;
use Teme\Contracts\Insertions\Position;

/**
 * Fixed document: five paragraphs, an h2, a list, an image card, a table.
 * Indexes: 0 p1, 1 p2, 2 h2, 3 p3, 4 list, 5 p4, 6 image, 7 p5, 8 table.
 */
function insertionDocument(): array
{
    return [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'One']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Two']]],
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Mid']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Three']]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Item']]]]]]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Four']]],
            ['type' => 'image', 'attrs' => ['mediaId' => 'x']],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Five']]],
            ['type' => 'table', 'content' => []],
        ],
    ];
}

function insertionFacts(int $paragraphs = 5): ArticleFacts
{
    return new ArticleFacts('c1', 'post', [], 'en', [], 50, $paragraphs);
}

it('maps each Position to the expected top-level index', function (Position $at, ?int $index): void {
    $plan = PlanBuilder::for(insertionDocument(), insertionFacts(), [
        new Insertion('one', $at, '<aside>x</aside>'),
    ]);

    if ($index === null) {
        expect($plan->at())->toBe([]);

        return;
    }

    expect($plan->at())->toBe([$index => ['<aside>x</aside>']]);
})->with([
    'beforeContent' => [Position::beforeContent(), 0],
    'afterContent' => [Position::afterContent(), 9],
    'afterParagraph 2' => [Position::afterParagraph(2), 2],
    'afterParagraph 5' => [Position::afterParagraph(5), 8],
    'afterParagraph 9 missing' => [Position::afterParagraph(9), null],
    'beforeHeading h2 first' => [Position::beforeHeading(2, 1), 2],
    'afterHeading h2 first' => [Position::afterHeading(2, 1), 3],
    'beforeHeading missing' => [Position::beforeHeading(3, 1), null],
    'nearEnd' => [Position::nearEnd(), 7],
]);

it('never lands a point inside the list, card or table', function (): void {
    $plan = PlanBuilder::for(insertionDocument(), insertionFacts(), [
        new Insertion('a', Position::afterParagraph(3), '<i>a</i>'),
        new Insertion('b', Position::afterParagraph(4), '<i>b</i>'),
        new Insertion('c', Position::afterParagraph(5), '<i>c</i>'),
    ]);

    // after p3 → index 4 (before the list); after p4 → 6 (before image); after p5 → 8 (before table)
    expect($plan->at())->toBe([
        4 => ['<i>a</i>'],
        6 => ['<i>b</i>'],
        8 => ['<i>c</i>'],
    ])
        ->and(array_keys($plan->at()))->not->toContain(5, 7);
});

it('stops everyParagraphs at its ceiling', function (): void {
    $plan = PlanBuilder::for(insertionDocument(), insertionFacts(), [
        new Insertion('every', Position::everyParagraphs(1, 3), '<i>e</i>'),
    ]);

    expect($plan->at())->toBe([
        1 => ['<i>e</i>'],
        2 => ['<i>e</i>'],
        4 => ['<i>e</i>'],
    ]);
});

it('draws nothing for NearEnd when there are fewer than three paragraphs', function (): void {
    $short = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'One']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Two']]],
        ],
    ];

    $plan = PlanBuilder::for($short, insertionFacts(2), [
        new Insertion('end', Position::nearEnd(), '<i>e</i>'),
    ]);

    expect($plan->at())->toBe([]);
});

it('draws everyParagraphs at most ten times, by default and when a larger ceiling is asked for', function (?int $max): void {
    $long = ['type' => 'doc', 'content' => array_map(
        fn (int $n): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "P{$n}"]]],
        range(1, 25),
    )];
    $at = $max === null ? Position::everyParagraphs(1) : Position::everyParagraphs(1, $max);

    $plan = PlanBuilder::for($long, insertionFacts(25), [new Insertion('every', $at, '<i>e</i>')]);

    expect(array_keys($plan->at()))->toBe(range(1, 10))
        ->and($at->args['max'] ?? null)->toBe(10);
})->with([
    'the default' => [null],
    'a ceiling of 50' => [50],
]);
