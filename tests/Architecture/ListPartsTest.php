<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

// The shared list parts (docs/architecture/design-system.md, section 4.26):
// the other screens import them, so each export stays; the row actions show
// without hovering (I-3398); the content lists page by number (I-3421).

it('keeps every export the other screens import', function (string $file, array $names): void {
    $source = uiComponent("components/list/{$file}");

    foreach ($names as $name) {
        expect($source)->toMatch('/export (?:function|type|const) '.$name.'\b/');
    }
})->with([
    ['bulk-bar.tsx', ['BulkBar']],
    ['bulk-edit-dialog.tsx', ['BulkEditReport', 'BulkEditDialog']],
    ['confirm-items.tsx', ['ConfirmItems']],
    ['data-table.tsx', ['SortDirection', 'Column', 'DataTable']],
    ['date-filter.tsx', ['DateMonths', 'DateFilter']],
    ['date-words.ts', ['monthLabel', 'rangeLabel', 'dateValue']],
    ['empty-state.tsx', ['EmptyState']],
    ['filter-button.tsx', ['ListPanel', 'FilterChoice', 'FilterButton']],
    ['page-header.tsx', ['PageHeader']],
    ['pager.tsx', ['Pager']],
    ['row-action-order.ts', ['splitRowActions']],
    ['row-actions.tsx', ['RowAction', 'RowActionsMenu']],
    ['row-links.tsx', ['RowLinks']],
    ['select-all-matching.tsx', ['MatchingAction', 'matchingScope', 'SelectAllMatching', 'ConfirmMatching']],
    ['tab-strip.tsx', ['Tab', 'TabStrip']],
]);

it('draws row actions with no hover rule', function (): void {
    $line = uiComponent('components/list/row-line.tsx');

    expect($line)->not->toContain('opacity-0')
        ->and($line)->not->toContain('group-hover');
});

it('pages these lists only through the numbered pager', function (string $page): void {
    $source = uiComponent($page);

    expect($source)->toContain("from '@/components/list/numbered-pager'")
        ->and($source)->not->toContain("from '@/components/list/pager'");
})->with(['pages/content/index.tsx', 'pages/terms/index.tsx', 'pages/comments/index.tsx']);
