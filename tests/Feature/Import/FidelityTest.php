<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentRenderer;
use App\Domain\Import\Phases\Finish\Fidelity;
use App\Domain\Import\Phases\Finish\FidelityText;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A paragraph of $count words, word1 to word{count}, from $from.
 */
function fidWords(int $count, int $from = 1): string
{
    // range() counts down when its end is below its start.
    return $count < 1 ? '' : implode(' ', array_map(fn (int $n): string => "word{$n}", range($from, $from + $count - 1)));
}

it('reads each block’s text, leaving out what no reader reads (I-337)', function (): void {
    $html = '<div><p>Boats came<br>in at <em>dawn</em>.</p><script>var x = "hidden";</script><style>p { color: red }</style>'
        .'<form><button>Share this</button></form><ul><li>One</li><li>Two</li></ul><!-- a note --></div>';

    expect(FidelityText::blocks($html))->toBe(['Boats came', 'in at dawn.', 'One', 'Two']);
});

it('makes words lower case, quotes straight and their edges free of punctuation', function (): void {
    expect(FidelityText::words('“Hello,” she said — it’s 2019! (Really?)'))->toBe(['hello', 'she', 'said', "it's", '2019', 'really']);
});

it('counts no loss when the same words come in other markup', function (): void {
    $fidelity = Fidelity::compare('<p>Boats came in at <strong>dawn</strong>.</p><p>The tide&nbsp;turned.</p>', '<p>Boats came in at dawn.</p><p>The tide turned.</p>');

    expect($fidelity->total)->toBe(8)
        ->and($fidelity->lost)->toBe(0)
        ->and($fidelity->flagged())->toBeFalse()
        ->and($fidelity->passages)->toBe([]);
});

it('flags only more than 2% of WordPress’s words lost', function (int $lost, float $share, bool $flagged): void {
    $fidelity = Fidelity::compare('<p>'.fidWords(100).'</p>', '<p>'.fidWords(100 - $lost).'</p>');

    expect($fidelity->lost)->toBe($lost)
        ->and($fidelity->share)->toBe($share)
        ->and($fidelity->flagged())->toBe($flagged);
})->with([
    'none' => [0, 0.0, false],
    'exactly 2%' => [2, 2.0, false],
    '3%' => [3, 3.0, true],
    'all' => [100, 100.0, true],
]);

it('counts a word lost each time Teme has it fewer times than WordPress', function (): void {
    $fidelity = Fidelity::compare('<p>the cat and the hat</p>', '<p>the cat and hat</p>');

    expect($fidelity->total)->toBe(5)
        ->and($fidelity->lost)->toBe(1);
});

it('names where at most three missing passages begin, eight words each, as WordPress has them', function (): void {
    $sentences = [
        'The harbour opened in the spring of that year.',
        'Fishing boats came in at dawn, every single morning.',
        'The market sold the catch before the sun was high.',
        'Visitors came from the hills to buy fresh fish.',
        'By evening the quay was quiet again and empty.',
    ];

    $fidelity = Fidelity::compare('<p>'.implode(' ', $sentences).'</p>', '<p>'.$sentences[0].'</p>');

    expect($fidelity->passages)->toBe([
        'Fishing boats came in at dawn, every single…',
        'The market sold the catch before the sun…',
        'Visitors came from the hills to buy fresh…',
    ])->and($fidelity->flagged())->toBeTrue();
});

it('keeps each block’s sentences to itself', function (): void {
    $fidelity = Fidelity::compare('<p>Alpha beta gamma delta</p><p>Epsilon zeta eta theta</p>', '<p>Alpha beta gamma delta</p>');

    expect($fidelity->passages)->toBe(['Epsilon zeta eta theta']);
});

it('never flags an item with no words on WordPress', function (): void {
    $fidelity = Fidelity::compare('<p> </p><script>var words = 1;</script>', '<p>Boats came in at dawn.</p>');

    expect($fidelity->total)->toBe(0)
        ->and($fidelity->flagged())->toBeFalse();
});

it('counts an Imported content card’s words and a code block’s as readers see them (I-329)', function (): void {
    $teme = app(DocumentRenderer::class)->html(['type' => 'doc', 'content' => [
        ['type' => 'codeBlock', 'attrs' => ['language' => null], 'content' => [['type' => 'text', 'text' => 'echo tide();']]],
        ['type' => 'imported', 'attrs' => [
            'id' => '01K5Z0F1ED0000000000000CRD',
            'markup' => [['element' => 'p', 'children' => [['text' => 'From the old table']]]],
            'sourceKind' => 'html-block',
            'sourceName' => null,
            'kept' => false,
        ]],
    ]]);

    $fidelity = Fidelity::compare('<pre><code>echo tide();</code></pre><p>From the old table</p>', $teme);

    expect($fidelity->lost)->toBe(0);
});

it('leaves out what Teme drops on purpose: a table of contents, a listing of other posts, sharing and related boxes', function (string $dropped): void {
    $article = '<h2>Where the boats go</h2><p>'.fidWords(40).'</p><h2>When the tide turns</h2><p>'.fidWords(40, 41).'</p>';

    // WordPress's page holds the dropped part too; Teme's theme draws its own, or none.
    $fidelity = Fidelity::compare($dropped.$article, $article);

    expect($fidelity->total)->toBe(88)
        ->and($fidelity->lost)->toBe(0)
        ->and($fidelity->flagged())->toBeFalse();
})->with([
    'a contents block' => ['<div class="wp-block-yoast-seo-table-of-contents yoast-table-of-contents"><h2>Table of contents</h2><ul><li><a href="#a">Where the boats go</a></li><li><a href="#b">When the tide turns</a></li></ul></div>'],
    'a contents plugin box' => ['<div id="ez-toc-container" class="ez-toc-v2_0 ez-toc-container"><p class="ez-toc-title">Contents</p><nav><ul><li>Where the boats go</li><li>When the tide turns</li></ul></nav></div>'],
    'latest posts' => ['<ul class="wp-block-latest-posts__list wp-block-latest-posts"><li><a href="/x">Another story about harbors</a></li></ul>'],
    'related posts' => ['<div id="jp-relatedposts" class="jp-relatedposts"><h3>Related</h3><p>Another story about harbors</p></div>'],
]);

it('still counts the words of an article that only mentions contents', function (): void {
    $fidelity = Fidelity::compare('<div class="contents-note"><p>Table of contents follows here soon</p></div>', '<p>Nothing</p>');

    expect($fidelity->lost)->toBe(6);
});
