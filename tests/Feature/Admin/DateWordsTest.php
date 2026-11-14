<?php

declare(strict_types=1);

it('says a month and a range of the site\'s days in plain words on the Date button (I-3129)', function (): void {
    $words = guardNode('date-words.mjs', [
        'months' => ['2026-09', '2025-12', 'never'],
        'values' => [
            (object) [],
            ['month' => '2026-10'],
            ['from' => '2026-09-01', 'to' => '2026-09-30'],
            ['from' => '2026-09-01', 'to' => '2026-10-15'],
            ['from' => '2025-12-20', 'to' => '2026-01-05'],
            ['from' => '2026-09-01'],
            ['to' => '2026-09-30'],
            ['from' => '2026-09-14', 'to' => '2026-09-14'],
            ['month' => '2026-10', 'from' => '2026-09-01'],
        ],
    ]);

    expect($words['months'])->toBe(['September 2026', 'December 2025', 'never'])
        ->and($words['values'])->toBe([
            'All dates',
            'October 2026',
            '1–30 Sep 2026',
            '1 Sep – 15 Oct 2026',
            '20 Dec 2025 – 5 Jan 2026',
            'Since 1 Sep 2026',
            'Until 30 Sep 2026',
            '14 Sep 2026',
            'October 2026',
        ]);
});
