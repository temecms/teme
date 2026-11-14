<?php

declare(strict_types=1);

use Tests\Fixtures\Guides\OperatorGuide;

/*
 * The update chapter against the order teme:deploy takes, and the runbook
 * against the shape that lets a run fill it in.
 */

/**
 * The text under one second-level heading of a page, up to the next.
 */
function guideSection(string $page, string $heading): string
{
    $found = preg_match('/^## '.preg_quote($heading, '/').'\s*\n(.*?)(?=^## |\z)/ms', OperatorGuide::page($page)->body(), $match);

    expect($found)->toBe(1);

    return $match[1];
}

it('lists what teme:deploy does in the order it does it', function (): void {
    // Each step is the first line of its own numbered item, and says its keyword there.
    $steps = ['preflight' => '/\bpreflight\b/i', 'lock' => '/\block\b/i', 'backup' => '/\bbackup\b/i', 'migrate' => '/\bmigrat/i', 'rebuild' => '/\brebuild/i', 'queue:restart' => '/queue:restart/', 'teme:cache:clear' => '/teme:cache:clear/'];

    preg_match_all('/^\d+\.\s+(.+)$/m', guideSection('updates.md', 'What `teme:deploy` does'), $items);

    $at = [];
    $from = 0;

    foreach ($steps as $name => $pattern) {
        $at[$name] = null;

        foreach (array_slice($items[1], $from, null, true) as $index => $item) {
            if (preg_match($pattern, $item) === 1) {
                $at[$name] = $index;
                $from = $index + 1;

                break;
            }
        }
    }

    expect(array_keys(array_filter($at, fn (?int $index): bool => $index === null)))->toBe([]);
});

it('tells the reader to check for pending migrations with the option the command has', function (): void {
    $uses = array_filter(OperatorGuide::commands(OperatorGuide::page('updates.md')), fn (array $command): bool => $command['name'] === 'teme:deploy' && in_array('check', $command['options'], true));

    expect($uses)->not->toBe([]);
});

it('is a checklist of seven stages, each a table with a took column and a surprise column', function (): void {
    $page = OperatorGuide::page('migration-runbook.md');
    $stages = array_values(array_filter($page->headingsAt(2), fn (string $heading): bool => str_starts_with($heading, 'Stage ')));

    expect($stages)->toHaveCount(7);

    foreach (range(1, 7) as $number) {
        expect($stages[$number - 1])->toStartWith("Stage {$number}: ");
    }

    preg_match_all('/^\| Step \| What to do \| Took \| Surprise \|\s*$/m', $page->body(), $headers);
    preg_match_all('/^\| \d+\.\d+ \|/m', $page->body(), $rows);

    expect($headers[0])->toHaveCount(7)
        ->and(count($rows[0]))->toBeGreaterThanOrEqual(21);
});
