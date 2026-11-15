<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

uses(TestCase::class);

// The editor's seams with the parts beside it (docs/plans/editor.md): one way
// to the media picker, the hook the media picker reads, the pin rule for the
// Tools launcher, and where a plugin's tab sits in the Search and social box.

/**
 * The editor's own files, relative to resources/js: everything under
 * components/editor and the two pages this part owns.
 *
 * @return list<string>
 */
function seamsEditorFiles(): array
{
    $files = ['pages/content/edit.tsx', 'pages/content/compare.tsx'];

    foreach (Finder::create()->files()->in(resource_path('js/components/editor'))->name(['*.ts', '*.tsx']) as $file) {
        $files[] = 'components/editor/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
    }

    sort($files);

    return $files;
}

it('opens the media picker from the editor only through pick-media', function (): void {
    $callers = array_values(array_filter(
        seamsEditorFiles(),
        fn (string $path): bool => $path !== 'components/editor/pick-media.tsx'
            && preg_match('/MediaModal|openMediaPicker\(/', (string) file_get_contents(resource_path("js/{$path}"))) === 1,
    ));

    expect($callers)->toBe([]);
});

it('keeps useUploadTarget where the media picker reads it', function (): void {
    expect(file_get_contents(resource_path('js/components/editor/cards/card-environment.ts')))
        ->toContain('export function useUploadTarget(');
});

it('pins tools up to three, refusing a fourth and anything not pinnable', function (): void {
    $pinnable = ['assistant', 'share', 'links', 'words'];

    expect(guardCall('components/editor/tools/pins.ts', 'togglePin', [
        [[], 'share', ['assistant', 'share']],
        [['assistant', 'share', 'links'], 'words', $pinnable],
        [['assistant', 'share', 'links'], 'share', $pinnable],
        [['assistant'], 'history', $pinnable],
    ]))->toBe([
        ['pins' => ['share'], 'refused' => false],
        ['pins' => ['assistant', 'share', 'links'], 'refused' => true],
        ['pins' => ['assistant', 'links'], 'refused' => false],
        ['pins' => ['assistant'], 'refused' => false],
    ]);
});

it('offers the available tools, and refuses one that opens as a workspace', function (): void {
    $tool = fn (string $id, string $size, bool $available = true): array => ['id' => $id, 'size' => $size, 'available' => $available];

    expect(guardCall('components/editor/tools/pins.ts', 'offeredTools', [
        [[$tool('overview', 'pane'), $tool('assistant', 'pane', false), $tool('words', 'dialog'), $tool('studio', 'workspace')]],
    ]))->toBe([
        [$tool('overview', 'pane'), $tool('words', 'dialog')],
    ]);
});

it('places a plugin\'s tab where its author declared it, Search always first', function (): void {
    expect(guardCall('components/editor/search-social/tab-order.ts', 'orderTabs', [
        [[]],
        [[['id' => 'analysis', 'owner' => 'seo', 'position' => 'after-search']]],
        [[['id' => 'analysis', 'owner' => 'seo', 'position' => 'after-social']]],
        [[['id' => 'analysis', 'owner' => 'seo', 'position' => 'last']]],
        [[['id' => 'beta', 'owner' => 'seo', 'position' => 'after-search', 'order' => 2], ['id' => 'gamma', 'owner' => 'seo', 'position' => 'after-search', 'order' => 1], ['id' => 'alpha', 'owner' => 'seo', 'position' => 'after-search', 'order' => 2]]],
        [[['id' => 'search', 'owner' => 'seo', 'position' => 'last'], ['id' => 'extra', 'owner' => 'core', 'position' => 'after-search']]],
    ]))->toBe([
        ['search', 'social', 'schema', 'advanced'],
        ['search', 'analysis', 'social', 'schema', 'advanced'],
        ['search', 'social', 'analysis', 'schema', 'advanced'],
        ['search', 'social', 'schema', 'advanced', 'analysis'],
        ['search', 'gamma', 'alpha', 'beta', 'social', 'schema', 'advanced'],
        ['search', 'social', 'schema', 'advanced'],
    ]);
});
