<?php

declare(strict_types=1);

it('pads the editor plugin panel and keeps the job state from shrinking (#507)', function (): void {
    $source = file_get_contents(resource_path('js/components/editor/plugin-panels/plugin-panel.tsx'));

    expect($source)->toContain('data-test="plugin-panel"')
        ->and($source)->toMatch('/className="[^"]*\bp-4\b[^"]*"[^>]*data-test="plugin-panel"/')
        ->and($source)->toContain("finished: __('Done')")
        ->and($source)->toContain('text-ink-2 shrink-0');
});
