<?php

declare(strict_types=1);

it('shows up to four actions on a row\'s line, and past four the first three and the destructive one, the rest under More (I-3130)', function (array $actions, array $line, array $more): void {
    $items = array_map(fn (string $label): array => ['label' => rtrim($label, '!'), 'destructive' => str_ends_with($label, '!')], $actions);

    expect(guardNode('row-actions.mjs', ['cases' => [$items]])[0])->toBe(['line' => $line, 'more' => $more]);
})->with([
    'two' => [['Edit', 'Delete!'], ['Edit', 'Delete'], []],
    'four' => [['Edit', 'Quick edit', 'View', 'Trash!'], ['Edit', 'Quick edit', 'View', 'Trash'], []],
    'a draft its viewer may publish' => [['Edit', 'Quick edit', 'Preview', 'Publish', 'Trash!'], ['Edit', 'Quick edit', 'Preview', 'Trash'], ['Publish']],
    'a live item with a review to decide' => [['Edit', 'Quick edit', 'View', 'Approve', 'Revert to draft', 'Trash!'], ['Edit', 'Quick edit', 'View', 'Trash'], ['Approve', 'Revert to draft']],
    'a waiting comment' => [['Approve', 'Reply', 'Quick edit', 'Details', 'Mark as spam', 'Move to trash!'], ['Approve', 'Reply', 'Quick edit', 'Move to trash'], ['Details', 'Mark as spam']],
    'five with nothing destructive' => [['A', 'B', 'C', 'D', 'E'], ['A', 'B', 'C', 'D'], ['E']],
    'two destructive past four' => [['A', 'B', 'C', 'D!', 'E!'], ['A', 'B', 'C', 'D'], ['E']],
]);
