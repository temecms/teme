<?php

declare(strict_types=1);

beforeEach(function (): void {
    // A file's source with each run of whitespace made one space, so a
    // sentence Prettier wraps reads as one line.
    $this->flat = fn (string $path): string => (string) preg_replace('/\s+/', ' ', uiComponent($path));

    // Where a piece of source starts; the test fails when it isn't there.
    $this->at = function (string $source, string $needle, int $from = 0): int {
        $at = strpos($source, $needle, $from);
        expect($at)->not->toBeFalse("Not in the source: {$needle}");

        return (int) $at;
    };
});

it('says to add a place off this server under the places’ heading, before the list', function (): void {
    $source = ($this->flat)('components/backups/places-list.tsx');

    expect(($this->at)($source, 'Add a place off this server'))
        ->toBeGreaterThan(($this->at)($source, "Where backups are kept')} </h2>"))
        ->toBeLessThan(($this->at)($source, '<ul'));
});

it('says how backups are checked under the checks’ heading, before the list', function (): void {
    $source = ($this->flat)('components/backups/checks-list.tsx');

    expect(($this->at)($source, 'Every backup is checked where it lands. A seventh'))
        ->toBeGreaterThan(($this->at)($source, "Latest checks')} </h2>"))
        ->toBeLessThan(($this->at)($source, 'checks.length === 0'));
});

it('warns that Teme keeps no copy of a recovery key under the keys’ heading, before the list', function (): void {
    $source = ($this->flat)('components/backups/keys-list.tsx');

    expect(($this->at)($source, 'Teme keeps no copy of a recovery key.'))
        ->toBeGreaterThan(($this->at)($source, "Recovery keys')} </h2>"))
        ->toBeLessThan(($this->at)($source, '<ul'));
});

it('says what Jobs is for, and that the inbox tells you, in the page’s description', function (): void {
    $source = ($this->flat)('pages/tools/jobs.tsx');

    expect(($this->at)($source, 'description="Imports, bulk changes and other long tasks. When one you started finishes, you’ll get a message in your inbox, so you can leave this page."'))
        ->toBeLessThan(($this->at)($source, '<DataTable'));
});

it('says nothing under the pager on Jobs', function (): void {
    $source = ($this->flat)('pages/tools/jobs.tsx');

    expect(strpos($source, '<p', ($this->at)($source, '<Pager')))->toBeFalse()
        ->and($source)->not->toContain('whoever started it is told');
});

it('offers Open inbox among the header’s actions on Jobs', function (): void {
    $source = ($this->flat)('pages/tools/jobs.tsx');
    $action = ($this->at)($source, 'actions={');

    expect(($this->at)($source, 'Open inbox', $action))->toBeLessThan(($this->at)($source, '<DataTable'))
        ->and(($this->at)($source, 'asChild', $action))->toBeLessThan(($this->at)($source, 'Open inbox', $action))
        ->and($source)->not->toContain('Open your inbox');
});

it('offers Show the latest import as a button beside an older report’s line', function (): void {
    $source = ($this->flat)('components/import/run-report.tsx');
    $line = ($this->at)($source, 'Report of the import started');

    expect(($this->at)($source, 'asChild', $line))->toBeLessThan(($this->at)($source, 'Show the latest import', $line))
        ->and($source)->not->toContain('This is the report of the import started');
});

it('puts the signed-in note inside the notice under Now on Page cache', function (): void {
    $source = ($this->flat)('pages/tools/page-cache.tsx');

    expect(($this->at)($source, 'While you’re signed in you always see pages drawn'))->toBeGreaterThan(($this->at)($source, 'data-test="page-cache-notice"'))
        ->and($source)->not->toContain('text-[13px]"> While you’re signed in');
});

it('says how redirects behave in the notice at the top, not above the pager', function (): void {
    $source = ($this->flat)('pages/tools/redirects.tsx');

    expect(($this->at)($source, 'A redirect answers only where no page does'))
        ->toBeGreaterThan(($this->at)($source, 'data-test="redirects-notice"'))
        ->toBeLessThan(($this->at)($source, '<DataTable'));
});

it('says what the 404 log keeps under its description, before the list', function (): void {
    $source = ($this->flat)('pages/tools/not-found.tsx');

    expect(($this->at)($source, 'Keeps up to'))
        ->toBeGreaterThan(($this->at)($source, 'One row per address.'))
        ->toBeLessThan(($this->at)($source, '<DataTable'));
});

it('offers Change log settings as the 404 log’s header button', function (): void {
    $source = ($this->flat)('pages/tools/not-found.tsx');

    expect(($this->at)($source, 'Change log settings'))
        ->toBeGreaterThan(($this->at)($source, 'actions={'))
        ->toBeLessThan(($this->at)($source, 'One row per address.'))
        ->and($source)->not->toContain('Change the log’s size');
});

it('offers Set a budget as a button beside None set', function (): void {
    $source = ($this->flat)('pages/tools/ai-activity.tsx');
    $none = ($this->at)($source, 'None set');

    expect(($this->at)($source, 'asChild', $none))->toBeLessThan(($this->at)($source, 'Set a budget', $none))
        ->and($source)->not->toContain('Set one in');
});

it('offers Change the budget as a button beside the budget-reached note', function (): void {
    $source = ($this->flat)('pages/tools/ai-activity.tsx');
    $note = ($this->at)($source, 'until the new month starts.');

    expect(($this->at)($source, 'asChild', $note))->toBeLessThan(($this->at)($source, 'Change the budget', $note))
        ->and($source)->not->toContain('Change it in');
});

it('explains the cost in the month summary, under Spent, not above the pager', function (): void {
    $source = ($this->flat)('pages/tools/ai-activity.tsx');

    expect(($this->at)($source, 'Cost is Teme’s own sum'))
        ->toBeGreaterThan(($this->at)($source, "Spent')}</dt>"))
        ->toBeLessThan(($this->at)($source, 'export default function AiActivity'));
});
