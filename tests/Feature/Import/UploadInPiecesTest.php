<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/*
 * Uploading an export file in pieces from the browser (upload-in-pieces.ts),
 * run in Node: pieces shrink after a gateway failure, a gateway's HTML answer
 * is retried rather than read as JSON, and a retried piece goes from its offset.
 */

it('uploads an export file in pieces that shrink, retry and resume from the server\'s offset', function (): void {
    if (Process::run(['node', '--version'])->failed()) {
        $this->markTestSkipped('Node is not installed.');
    }

    $run = Process::path(base_path())->timeout(60)->run([
        'node', '--import', './tests/Fixtures/editor-hooks.mjs',
        'resources/js/lib/upload-in-pieces.test.mjs',
    ]);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('All upload-in-pieces tests passed.')
        ->not->toContain('not ok');
});
