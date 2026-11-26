<?php

declare(strict_types=1);

it('names every plugin table filter\'s empty choice All, not Any plus the label (#505)', function (): void {
    $source = file_get_contents(resource_path('js/components/plugins/area/area-table.tsx'));

    expect($source)->toContain("emptyLabel={__('All :label'")
        ->and($source)->not->toContain("__('Any :filter'");
});
