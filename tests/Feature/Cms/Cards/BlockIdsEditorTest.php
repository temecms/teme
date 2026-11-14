<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentSchema;

/*
 * The editor's block IDs (I-3362), run in Node through the editor's own
 * module: what it gives a stored document before the editor opens, and what
 * a paste keeps or makes new.
 */

/**
 * Each node of a document or a list of nodes that has an `id` attribute, as
 * [type, id], depth first.
 *
 * @param  array<mixed>  $nodes
 *
 * @return list<array{0: string, 1: mixed}>
 */
function bieIds(array $nodes): array
{
    $found = [];

    foreach (array_is_list($nodes) ? $nodes : [$nodes] as $node) {
        if (is_array($node['attrs'] ?? null) && array_key_exists('id', $node['attrs'])) {
            $found[] = [(string) $node['type'], $node['attrs']['id']];
        }

        array_push($found, ...bieIds((array) ($node['content'] ?? [])));
    }

    return $found;
}

/**
 * The ids among these that are not of a node ID's shape.
 *
 * @param  list<mixed>  $ids
 *
 * @return list<mixed>
 */
function bieMalformed(array $ids): array
{
    return array_values(array_filter($ids, fn (mixed $id): bool => ! is_string($id) || preg_match(DocumentSchema::NODE_ID, $id) !== 1));
}

it('gives every block that carries an id one before the editor opens, and no other node one', function (): void {
    $text = ['type' => 'text', 'text' => 'Words'];
    $paragraph = ['type' => 'paragraph', 'content' => [$text]];
    $document = ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2, 'id' => null], 'content' => [$text]],
        $paragraph,
        ['type' => 'blockquote', 'content' => [$paragraph]],
        ['type' => 'codeBlock', 'attrs' => ['language' => null], 'content' => [$text]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$paragraph]]]],
        ['type' => 'taskList', 'content' => [['type' => 'taskItem', 'attrs' => ['checked' => false], 'content' => [$paragraph]]]],
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [$paragraph]]]]]],
        ['type' => 'horizontalRule'],
    ]];

    [$opened] = guardCall('components/editor/block-ids.ts', 'withBlockIds', [[$document, []]]);
    $found = bieIds($opened['content']);
    $ids = array_column($found, 1);

    expect(array_column($found, 0))->toBe(['heading', 'paragraph', 'blockquote', 'paragraph', 'codeBlock', 'listItem', 'paragraph', 'taskItem', 'paragraph', 'table', 'paragraph'])
        ->and(bieMalformed($ids))->toBe([])
        ->and(array_unique($ids))->toHaveCount(11)
        ->and($opened['content'][6]['content'][0])->not->toHaveKey('attrs')
        ->and($opened['content'][7])->toBe(['type' => 'horizontalRule']);
});

it('keeps every id a stored block or card has, and makes a repeated one or one of another shape new, the first keeping it', function (): void {
    $id = '01K5Z9P2QH7N3X4C8V6B0M1T3D';
    $card = '01K5Z9P2QH7N3X4C8V6B0M1T3E';
    $text = [['type' => 'text', 'text' => 'Words']];
    $document = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'attrs' => ['id' => $id, 'textAlign' => null], 'content' => $text],
        ['type' => 'paragraph', 'attrs' => ['id' => $id, 'textAlign' => null], 'content' => $text],
        ['type' => 'callout', 'attrs' => ['id' => $card, 'tone' => 'note', 'text' => 'Hi']],
        ['type' => 'callout', 'attrs' => ['id' => $card, 'tone' => 'note', 'text' => 'Hi']],
        ['type' => 'paragraph', 'attrs' => ['id' => '9b2c6d1e-4f3a-4c1b-8e2d-7a6b5c4d3e2f', 'textAlign' => null], 'content' => $text],
    ]];

    [$opened] = guardCall('components/editor/block-ids.ts', 'withBlockIds', [[$document, ['callout']]]);
    $ids = array_map(fn (array $node): mixed => $node['attrs']['id'], $opened['content']);

    expect($ids[0])->toBe($id)
        ->and($ids[2])->toBe($card)
        ->and(bieMalformed($ids))->toBe([])
        ->and(array_unique($ids))->toHaveCount(5)
        ->and($opened['content'][3]['attrs'])->toMatchArray(['tone' => 'note', 'text' => 'Hi']);
});

it('makes a pasted block new when the article holds its id or the paste repeats it, and keeps an id the article doesn\'t hold', function (): void {
    $held = '01K5Z9P2QH7N3X4C8V6B0M1T3D';
    $other = '01K5Z9P2QH7N3X4C8V6B0M1T3E';
    $paragraph = fn (?string $id): array => ['type' => 'paragraph', 'attrs' => ['id' => $id, 'textAlign' => null], 'content' => [['type' => 'text', 'text' => 'Words']]];

    [$pasted] = guardCall('components/editor/block-ids.ts', 'freshPastedIds', [[
        [$paragraph($held), $paragraph($other), $paragraph($other), $paragraph(null)],
        [$held],
        [],
    ]]);
    $ids = array_map(fn (array $node): mixed => $node['attrs']['id'], $pasted);

    expect($ids[0])->not->toBe($held)
        ->and($ids[1])->toBe($other)
        ->and($ids[2])->not->toBe($other)
        ->and(bieMalformed($ids))->toBe([])
        ->and(array_unique($ids))->toHaveCount(4)
        ->and(array_column($pasted, 'content'))->toBe(array_fill(0, 4, [['type' => 'text', 'text' => 'Words']]));
});

it('makes a pasted card new when the article holds its id, and a list item and its paragraph inside a pasted list', function (): void {
    $card = '01K5Z9P2QH7N3X4C8V6B0M1T3D';
    $item = '01K5Z9P2QH7N3X4C8V6B0M1T3E';
    $inner = '01K5Z9P2QH7N3X4C8V6B0M1T3F';
    $list = ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'attrs' => ['id' => $item], 'content' => [['type' => 'paragraph', 'attrs' => ['id' => $inner, 'textAlign' => null], 'content' => [['type' => 'text', 'text' => 'One']]]]]]];

    [$pasted] = guardCall('components/editor/block-ids.ts', 'freshPastedIds', [[
        [['type' => 'callout', 'attrs' => ['id' => $card, 'tone' => 'note', 'text' => 'Gates open at six.']], $list],
        [$card, $item, $inner],
        ['callout'],
    ]]);

    expect($pasted[0]['attrs']['id'])->not->toBe($card)->toMatch(DocumentSchema::NODE_ID)
        ->and($pasted[0]['attrs'])->toMatchArray(['tone' => 'note', 'text' => 'Gates open at six.'])
        ->and($pasted[1])->not->toHaveKey('attrs')
        ->and($pasted[1]['content'][0]['attrs']['id'])->not->toBe($item)->toMatch(DocumentSchema::NODE_ID)
        ->and($pasted[1]['content'][0]['content'][0]['attrs']['id'])->not->toBe($inner)->toMatch(DocumentSchema::NODE_ID);
});

it('lists every id a document\'s blocks and cards carry, in order, and none of another shape', function (): void {
    $document = ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2, 'id' => '01K5Z9P2QH7N3X4C8V6B0M1T3D'], 'content' => [['type' => 'text', 'text' => 'H']]],
        ['type' => 'blockquote', 'attrs' => ['id' => null], 'content' => [['type' => 'paragraph', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3E'], 'content' => [['type' => 'text', 'text' => 'Q']]]]],
        ['type' => 'callout', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3F', 'tone' => 'note', 'text' => 'Hi']],
        ['type' => 'paragraph', 'attrs' => ['id' => 'not-a-ulid'], 'content' => [['type' => 'text', 'text' => 'P']]],
    ]];

    expect(guardCall('components/editor/block-ids.ts', 'blockIdsIn', [[$document, ['callout']]]))
        ->toBe([['01K5Z9P2QH7N3X4C8V6B0M1T3D', '01K5Z9P2QH7N3X4C8V6B0M1T3E', '01K5Z9P2QH7N3X4C8V6B0M1T3F']]);
});

it('uses these rules in the editor: the paste rule in the schema, the opening pass on the stored document', function (): void {
    expect(uiComponent('components/editor/schema.ts'))->toContain('blockIds(cards.map((card) => card.name))')
        ->not->toContain('UniqueID.configure(')
        // Read with its spaces aside: the formatter puts each argument on its own line.
        ->and(preg_replace('/\s+/', '', uiComponent('components/editor/use-teme-editor.ts')))->toContain('withBlockIds(withBlock(document),cards.map((card)=>card.name)')
        ->and(uiComponent('components/editor/block-ids.ts'))->toContain('view.dragging?.move === true');
});
