<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\Exceptions\InvalidDocumentException;
use App\Domain\Import\Phases\SecondPass\SecondPassDocument;

/**
 * @return array<string, mixed>
 */
function spdP(string $text): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
}

/**
 * @return array{type: string, id: string, at: list<int>}
 */
function spdRef(string $id, array $at): array
{
    return ['type' => 'post', 'id' => $id, 'at' => $at];
}

/**
 * A finder over these blocks, as the step reads them from the map.
 *
 * @param  array<string, array{content: list<array<string, mixed>>, references?: list<array<string, mixed>>, pendingLinks?: list<string>}>  $blocks
 */
function spdFind(array $blocks): Closure
{
    return fn (string $id): ?array => isset($blocks[$id])
        ? ['content' => $blocks[$id]['content'], 'references' => $blocks[$id]['references'] ?? [], 'pendingLinks' => $blocks[$id]['pendingLinks'] ?? []]
        : null;
}

/**
 * The texts of a list of nodes, depth first.
 *
 * @param  list<array<string, mixed>>  $nodes
 *
 * @return list<string>
 */
function spdTexts(array $nodes): array
{
    $texts = [];

    foreach ($nodes as $node) {
        if (($node['type'] ?? null) === 'text') {
            $texts[] = $node['text'];
        }

        array_push($texts, ...spdTexts($node['content'] ?? []));
    }

    return $texts;
}

it('puts each block at its place, the last first, two at one place in the order they came (I-333)', function (): void {
    $done = SecondPassDocument::inline(
        [spdP('a'), spdP('b')],
        [spdRef('88', [1]), spdRef('90', [1]), spdRef('91', [2])],
        spdFind(['88' => ['content' => [spdP('x')]], '90' => ['content' => [spdP('y')]], '91' => ['content' => [spdP('z')]]]),
        'item',
    );

    expect(spdTexts($done['content']))->toBe(['a', 'x', 'y', 'b', 'z'])
        ->and($done['inlined'])->toEqualCanonicalizing(['88', '90', '91'])
        ->and($done['missing'])->toBe([]);
});

it('puts a block inside a parent before the parent’s own place moves', function (): void {
    $quote = ['type' => 'blockquote', 'content' => [spdP('q1'), spdP('q2')]];

    $done = SecondPassDocument::inline(
        [spdP('a'), $quote, spdP('b')],
        [spdRef('88', [1, 1]), spdRef('90', [1])],
        spdFind(['88' => ['content' => [spdP('x')]], '90' => ['content' => [spdP('y')]]]),
        'item',
    );

    expect(spdTexts($done['content']))->toBe(['a', 'y', 'q1', 'x', 'q2', 'b'])
        ->and($done['content'][2]['type'])->toBe('blockquote');
});

it('puts a block in place of the empty paragraph an empty container was given', function (): void {
    $find = spdFind(['88' => ['content' => [spdP('x')]]]);

    $inQuote = SecondPassDocument::inline([['type' => 'blockquote', 'content' => [['type' => 'paragraph']]]], [spdRef('88', [0, 0])], $find, 'item');
    $alone = SecondPassDocument::inline([['type' => 'paragraph']], [spdRef('88', [0])], $find, 'item');

    expect($inQuote['content'][0]['content'])->toBe([spdP('x')])
        ->and($alone['content'])->toBe([spdP('x')]);
});

it('gives a block’s second copy in one item new node IDs, the same ones every time', function (): void {
    $heading = ['type' => 'heading', 'attrs' => ['level' => 2, 'id' => '01K5Z0F1ED000000000000HXYZ'], 'content' => [['type' => 'text', 'text' => 'Hours']]];
    $inline = fn (): array => SecondPassDocument::inline([spdP('a')], [spdRef('88', [0]), spdRef('88', [1])], spdFind(['88' => ['content' => [$heading, spdP('x')]]]), 'item-1');

    $done = $inline();
    $ids = array_values(array_filter(array_map(fn (array $node): ?string => $node['attrs']['id'] ?? null, $done['content'])));

    expect(spdTexts($done['content']))->toBe(['Hours', 'x', 'a', 'Hours', 'x'])
        ->and($ids)->toHaveCount(2)
        ->and($ids)->toContain('01K5Z0F1ED000000000000HXYZ')
        ->and($ids[0])->not->toBe($ids[1])
        ->and(preg_match(DocumentSchema::NODE_ID, $ids[0]) === 1 && preg_match(DocumentSchema::NODE_ID, $ids[1]) === 1)->toBeTrue()
        ->and($inline())->toBe($done);
});

it('puts a block inside a block, and leaves out a block met inside itself (I-333)', function (): void {
    $done = SecondPassDocument::inline([spdP('a')], [spdRef('88', [1])], spdFind([
        '88' => ['content' => [spdP('outer')], 'references' => [spdRef('90', [1])]],
        '90' => ['content' => [spdP('inner')], 'references' => [spdRef('88', [1])]],
    ]), 'item');

    expect(spdTexts($done['content']))->toBe(['a', 'outer', 'inner'])
        ->and($done['loops'])->toBe(['88']);
});

it('stops ten blocks deep, saying so', function (): void {
    $blocks = [];

    foreach (range(1, 12) as $n) {
        $blocks[(string) $n] = ['content' => [spdP("level {$n}")], 'references' => $n < 12 ? [spdRef((string) ($n + 1), [1])] : []];
    }

    $done = SecondPassDocument::inline([], [spdRef('1', [0])], spdFind($blocks), 'item');

    expect(spdTexts($done['content']))->toBe(array_map(fn (int $n): string => "level {$n}", range(1, 10)))
        ->and($done['loops'])->toBe(['11']);
});

it('stops before the document passes the nodes it may hold, however blocks nest', function (): void {
    // Each level uses the next ten times, six deep: a million copies if nothing stopped it.
    $blocks = [];

    foreach (range(1, 6) as $level) {
        $blocks["b{$level}"] = ['content' => [spdP("level {$level}")], 'references' => $level < 6 ? array_fill(0, 10, spdRef('b'.($level + 1), [1])) : []];
    }

    expect(fn (): array => SecondPassDocument::inline([spdP('a')], [spdRef('b1', [1])], spdFind($blocks), 'item'))
        ->toThrow(InvalidDocumentException::class, 'The document has more than 20,000 nodes.');
});

it('leaves nothing for a block it can’t find, and names it', function (): void {
    $done = SecondPassDocument::inline([spdP('a')], [spdRef('88', [1]), spdRef('90', [1])], spdFind(['88' => ['content' => [spdP('x')]]]), 'item');

    expect(spdTexts($done['content']))->toBe(['a', 'x'])
        ->and($done['missing'])->toBe(['90']);
});

it('gathers the links its blocks left for the second pass', function (): void {
    $done = SecondPassDocument::inline([spdP('a')], [spdRef('88', [1])], spdFind([
        '88' => ['content' => [spdP('x')], 'references' => [spdRef('90', [1])], 'pendingLinks' => ['/later-story/']],
        '90' => ['content' => [spdP('y')], 'pendingLinks' => ['/tides/', '/later-story/']],
    ]), 'item');

    expect($done['pendingLinks'])->toEqualCanonicalizing(['/later-story/', '/tides/']);
});

it('finishes every link mark with a path it was given, and no other (I-1702)', function (): void {
    $linked = fn (string $text, string $href): array => ['type' => 'text', 'text' => $text, 'marks' => [['type' => 'bold'], ['type' => 'link', 'attrs' => ['href' => $href]]]];
    $content = [
        ['type' => 'paragraph', 'content' => [$linked('later', '/later-story/'), $linked('other', '/elsewhere/')]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [$linked('again', '/later-story/')]]]]]],
    ];

    $done = SecondPassDocument::relink($content, ['/later-story/' => 'teme:content/01k5z0f1ed0000000000000abc']);

    expect($done[0]['content'][0]['marks'][1]['attrs']['href'])->toBe('teme:content/01k5z0f1ed0000000000000abc')
        ->and($done[0]['content'][0]['marks'][0])->toBe(['type' => 'bold'])
        ->and($done[0]['content'][1]['marks'][1]['attrs']['href'])->toBe('/elsewhere/')
        ->and($done[1]['content'][0]['content'][0]['content'][0]['marks'][1]['attrs']['href'])->toBe('teme:content/01k5z0f1ed0000000000000abc');
});

it('finishes the links in an Imported content card’s markup (I-329)', function (): void {
    $card = ['type' => 'imported', 'attrs' => [
        'id' => '01K5Z0F1ED0000000000000CRD',
        'markup' => [['element' => 'p', 'children' => [['element' => 'a', 'attrs' => ['href' => '/later-story/'], 'children' => [['text' => 'later']]], ['text' => ' and on']]]],
        'sourceKind' => 'html-block',
        'sourceName' => null,
        'kept' => false,
    ]];

    $done = SecondPassDocument::relink([$card], ['/later-story/' => 'teme:content/01k5z0f1ed0000000000000abc']);

    expect($done[0]['attrs']['markup'][0]['children'][0]['attrs']['href'])->toBe('teme:content/01k5z0f1ed0000000000000abc')
        ->and($done[0]['attrs']['markup'][0]['children'][1])->toBe(['text' => ' and on']);
});

it('makes node IDs the schema accepts, the same for the same seed', function (): void {
    expect(preg_match(DocumentSchema::NODE_ID, SecondPassDocument::nodeId('item:A:2')))->toBe(1)
        ->and(SecondPassDocument::nodeId('item:A:2'))->toBe(SecondPassDocument::nodeId('item:A:2'))
        ->and(SecondPassDocument::nodeId('item:A:2'))->not->toBe(SecondPassDocument::nodeId('item:A:3'));
});

it('puts blocks in up to the nodes a document may hold, and stops at one more (I-2997)', function (): void {
    $content = array_map(fn (int $n): array => spdP("p{$n}"), range(1, 9999));
    $blocks = ['b' => ['content' => [spdP('b')]]];

    $done = SecondPassDocument::inline($content, [spdRef('b', [9999])], spdFind($blocks), 'item');

    expect($done['content'])->toHaveCount(10000)
        ->and($done['inlined'])->toBe(['b'])
        ->and(fn (): array => SecondPassDocument::inline($content, [spdRef('b', [9999]), spdRef('b', [9999])], spdFind($blocks), 'item'))
        ->toThrow(InvalidDocumentException::class, 'The document has more than 20,000 nodes.');
});
