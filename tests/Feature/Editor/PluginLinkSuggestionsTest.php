<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/*
 * Applying a plugin's link suggestion in the editor (apply-change.ts), run in
 * Node against Tiptap itself: a code block is skipped, only whole words match,
 * and the link lands on the original characters (issues #536, #537, #539).
 */

it('applies a plugin\'s link suggestion to whole words outside code, on the original characters', function (): void {
    if (Process::run(['node', '--version'])->failed()) {
        $this->markTestSkipped('Node is not installed.');
    }

    $run = Process::path(base_path())->timeout(60)->run([
        'node', '--import', './tests/Fixtures/editor-hooks.mjs',
        'resources/js/components/editor/plugin-panels/apply-change.test.mjs',
    ]);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('All apply-change tests passed.')
        ->not->toContain('not ok');
});
