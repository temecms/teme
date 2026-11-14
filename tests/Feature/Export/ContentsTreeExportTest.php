<?php

declare(strict_types=1);

use App\Domain\Export\Kinds\ContentsKind;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Export\ExportAs;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake((string) config('media.disk'));
    Storage::fake((string) config('media.originals_disk'));
    $this->seed(RoleSeeder::class);
});

it('exports each item\'s parent_id and position', function (): void {
    $tree = PageTree::build();
    $lines = collect(ExportAs::kindLines(app(ContentsKind::class)))->keyBy('id');

    expect([$lines[$tree->team->id]['parent_id'], $lines[$tree->team->id]['position']])->toBe([$tree->about->id, 0])
        ->and([$lines[$tree->history->id]['parent_id'], $lines[$tree->history->id]['position']])->toBe([$tree->about->id, 1])
        ->and([$lines[$tree->about->id]['parent_id'], $lines[$tree->teamPost->id]['parent_id']])->toBe([null, null])
        ->and(array_slice(array_keys($lines[$tree->team->id]), 9, 3))->toBe(['visibility', 'parent_id', 'position']);
});

it('describes both in the reference', function (): void {
    $markdown = (string) file_get_contents(base_path('docs/reference/export.md'));

    expect($markdown)->toContain('| `parent_id` | The item it sits under, of the same type, or null. A child may come before its parent. |')
        ->and($markdown)->toContain('| `position` | Its place among its parent\'s children, lowest first, then by ID. |');
});
