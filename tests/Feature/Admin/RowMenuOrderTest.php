<?php

declare(strict_types=1);

it('orders a row\'s "…" menu: core actions, then plugins\' in their order, then the destructive ones last (I-3398)', function (array $actions, array $core, array $plugins, array $destructive): void {
    // "Label" is core, "plugin:Label" a plugin's, and a trailing "!" destructive.
    $items = array_map(fn (string $label): array => [
        'label' => rtrim(str_contains($label, ':') ? explode(':', $label, 2)[1] : $label, '!'),
        'owner' => str_contains($label, ':') ? explode(':', $label, 2)[0] : 'core',
        'destructive' => str_ends_with($label, '!'),
    ], $actions);

    expect(guardNode('row-menu.mjs', ['cases' => [$items]])[0])->toBe(['core' => $core, 'plugins' => $plugins, 'destructive' => $destructive]);
})->with([
    'a draft its viewer may publish' => [['Publish', 'Trash!'], ['Publish'], [], ['Trash']],
    'plugins between, in their order' => [['Trash!', 'seo:Check links', 'Publish', 'cache:Purge'], ['Publish'], ['Check links', 'Purge'], ['Trash']],
    'a destructive plugin action goes with the destructive ones' => [['Publish', 'cleaner:Shred!', 'Trash!'], ['Publish'], [], ['Shred', 'Trash']],
    'nothing' => [[], [], [], []],
]);
