<?php

declare(strict_types=1);

/**
 * The region checks for $nodes, in a region that takes the callout and the
 * image card and at most $max blocks, as ['id' => [state, message]].
 *
 * @param  list<array<string, mixed>>  $nodes
 *
 * @return array<string, array{0: string, 1: string}>
 */
function rcChecks(array $nodes, int $max = 3): array
{
    $checks = guardNode('region-checks.mjs', [
        'document' => ['type' => 'doc', 'content' => $nodes],
        'media' => [
            'a' => ['variants' => [], 'width' => 1200, 'height' => 800, 'alt' => '', 'title' => 'A'],
        ],
        'max' => $max,
        'allowed' => ['callout', 'image'],
        'cardLabels' => ['callout' => 'Callout', 'image' => 'Image', 'gallery' => 'Gallery', 'button' => 'Button'],
    ]);

    $byId = [];

    foreach ($checks as $check) {
        $byId[$check['id']] = [$check['state'], $check['message']];
    }

    return $byId;
}

it('checks a region\'s blocks against its limit, the cards it takes and its images\' alt text, and nothing else (#529)', function (): void {
    $checks = rcChecks([
        docText('Fresh bread at the harbour bakery.'),
        ['type' => 'callout', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3A'], 'content' => [docText('Open on Sundays.')]],
    ]);

    expect(array_keys($checks))->toBe(['blocks', 'cards', 'alt'])
        ->and($checks['blocks'])->toBe(['ok', '2 of at most 3.'])
        ->and($checks['cards'])->toBe(['ok', 'Only text, Callout and Image cards are used.'])
        ->and($checks['alt'])->toBe(['ok', 'No images without alt text.']);
});

it('warns of a region over its limit, a card it does not take, and an image with no alt text (#529)', function (): void {
    $checks = rcChecks([
        docText('One.'),
        ['type' => 'button', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3B', 'text' => 'Go']],
        ['type' => 'image', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3C', 'mediaId' => 'a', 'alt' => '', 'decorative' => false]],
        docText('Four.'),
        docText('Five.'),
    ]);

    expect($checks['blocks'])->toBe(['warn', 'This region takes at most 3, and it has 5. Remove 2 blocks to publish.'])
        ->and($checks['cards'])->toBe(['warn', 'Button isn’t taken by this region.'])
        ->and($checks['alt'])->toBe(['warn', '1 image has no alt text.']);
});

it('calls a region that holds only an empty line empty (#529)', function (): void {
    expect(rcChecks([['type' => 'paragraph']])['blocks'])
        ->toBe(['warn', 'Empty. The theme draws nothing here until the region has something in it.']);
});
