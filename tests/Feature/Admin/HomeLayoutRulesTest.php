<?php

declare(strict_types=1);

/**
 * The Home arrangement rules, run through Node so the same module the page
 * imports is what the cases exercise.
 */
it('moves panels within their section and writes only sizes that differ', function (array $input, array $expected): void {
    expect(guardNode('home-layout.mjs', $input))->toBe($expected);
})->with([
    'moving a core panel later swaps it with the next shown core panel, skipping a hidden one between them' => [
        [
            'call' => 'moveBy',
            'layout' => [
                'order' => ['my_drafts', 'scheduled', 'my_recent_edits'],
                'hidden' => ['scheduled'],
                'sizes' => ['my_drafts' => 'medium', 'scheduled' => 'small', 'my_recent_edits' => 'small'],
            ],
            'owners' => ['my_drafts' => 'core', 'scheduled' => 'core', 'my_recent_edits' => 'core'],
            'key' => 'my_drafts',
            'step' => 1,
        ],
        ['my_recent_edits', 'scheduled', 'my_drafts'],
    ],
    'moving a plugin\'s panel never crosses into core\'s panels, though they are interleaved in order' => [
        [
            'call' => 'moveBy',
            'layout' => [
                'order' => ['my_drafts', 'plugin_a', 'my_recent_edits', 'plugin_b'],
                'hidden' => [],
                'sizes' => [],
            ],
            'owners' => [
                'my_drafts' => 'core',
                'plugin_a' => 'tests/hello',
                'my_recent_edits' => 'core',
                'plugin_b' => 'tests/hello',
            ],
            'key' => 'plugin_a',
            'step' => 1,
        ],
        ['my_drafts', 'plugin_b', 'my_recent_edits', 'plugin_a'],
    ],
    'moving the first panel earlier returns the same order' => [
        [
            'call' => 'moveBy',
            'layout' => [
                'order' => ['my_drafts', 'my_recent_edits'],
                'hidden' => [],
                'sizes' => [],
            ],
            'owners' => ['my_drafts' => 'core', 'my_recent_edits' => 'core'],
            'key' => 'my_drafts',
            'step' => -1,
        ],
        ['my_drafts', 'my_recent_edits'],
    ],
    'moving the last panel later returns the same order' => [
        [
            'call' => 'moveBy',
            'layout' => [
                'order' => ['my_drafts', 'my_recent_edits'],
                'hidden' => [],
                'sizes' => [],
            ],
            'owners' => ['my_drafts' => 'core', 'my_recent_edits' => 'core'],
            'key' => 'my_recent_edits',
            'step' => 1,
        ],
        ['my_drafts', 'my_recent_edits'],
    ],
    'moveTo with an index past the end puts it last' => [
        [
            'call' => 'moveTo',
            'layout' => [
                'order' => ['my_drafts', 'scheduled', 'my_recent_edits'],
                'hidden' => [],
                'sizes' => [],
            ],
            'owners' => ['my_drafts' => 'core', 'scheduled' => 'core', 'my_recent_edits' => 'core'],
            'key' => 'my_drafts',
            'index' => 99,
        ],
        ['scheduled', 'my_recent_edits', 'my_drafts'],
    ],
    'a hidden panel keeps its slot, so showing it puts it back where it was' => [
        [
            'call' => 'moveBy',
            'layout' => [
                'order' => ['my_drafts', 'scheduled', 'my_recent_edits'],
                'hidden' => ['scheduled'],
                'sizes' => [],
            ],
            'owners' => ['my_drafts' => 'core', 'scheduled' => 'core', 'my_recent_edits' => 'core'],
            'key' => 'my_drafts',
            'step' => 1,
        ],
        ['my_recent_edits', 'scheduled', 'my_drafts'],
    ],
    'toPreferences writes only sizes that differ from the declared ones' => [
        [
            'call' => 'toPreferences',
            'layout' => [
                'order' => ['my_drafts', 'scheduled'],
                'hidden' => ['scheduled'],
                'sizes' => ['my_drafts' => 'full', 'scheduled' => 'small'],
            ],
            'declared' => ['my_drafts' => 'medium', 'scheduled' => 'small'],
        ],
        [
            'dashboard.order' => ['my_drafts', 'scheduled'],
            'dashboard.hidden' => ['scheduled'],
            'dashboard.sizes' => ['my_drafts:full'],
        ],
    ],
]);
