<?php

declare(strict_types=1);

/**
 * The facts a region's checks read for $nodes, in a region that takes the
 * callout and the image card and at most $max blocks.
 *
 * @param  list<array<string, mixed>>  $nodes
 *
 * @return array<string, mixed>
 */
function rcFacts(array $nodes, int $max = 3): array
{
    return guardNode('region-checks.mjs', [
        'document' => ['type' => 'doc', 'content' => $nodes],
        'media' => [
            'a' => ['variants' => [], 'width' => 1200, 'height' => 800, 'alt' => '', 'title' => 'A'],
        ],
        'max' => $max,
        'allowed' => ['callout', 'image'],
        'cardLabels' => ['callout' => 'Callout', 'image' => 'Image', 'gallery' => 'Gallery', 'button' => 'Button'],
    ]);
}

it('reads a region\'s blocks against its limit, the cards it takes and its images\' alt text, and nothing else (#529)', function (): void {
    $facts = rcFacts([
        docText('Fresh bread at the harbour bakery.'),
        ['type' => 'callout', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3A'], 'content' => [docText('Open on Sundays.')]],
    ]);

    expect($facts)->toBe(['blocks' => 2, 'max' => 3, 'refused' => [], 'takes' => ['Callout', 'Image'], 'noAlt' => 0]);
});

it('finds a region over its limit, a card it does not take, and an image with no alt text (#529)', function (): void {
    $facts = rcFacts([
        docText('One.'),
        ['type' => 'button', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3B', 'text' => 'Go']],
        ['type' => 'image', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3C', 'mediaId' => 'a', 'alt' => '', 'decorative' => false]],
        docText('Four.'),
        docText('Five.'),
    ]);

    expect($facts)->toBe(['blocks' => 5, 'max' => 3, 'refused' => ['Button'], 'takes' => ['Callout', 'Image'], 'noAlt' => 1]);
});

it('counts a region that holds only an empty line as empty (#529)', function (): void {
    expect(rcFacts([['type' => 'paragraph']])['blocks'])->toBe(0);
});
