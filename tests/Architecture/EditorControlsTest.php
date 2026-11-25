<?php

declare(strict_types=1);

use App\Domain\Cards\BlockIds;
use App\Domain\Cards\DocumentSchema;
use Tests\TestCase;

uses(TestCase::class);

it('registers in the browser exactly the marks the server allows', function (): void {
    $schema = file_get_contents(resource_path('js/components/editor/schema.ts'));

    expect(preg_match('/export const MARKS = \[(.*?)\] as const;/s', $schema, $list))->toBe(1);
    preg_match_all("/'([A-Za-z]+)'/", $list[1], $marks);

    expect($marks[1])->toBe(DocumentSchema::MARKS);
});

it('gives an id in the editor to exactly the blocks the server lets carry one (I-3362)', function (): void {
    $ids = (string) @file_get_contents(resource_path('js/components/editor/block-ids.ts'));

    expect(preg_match('/export const BLOCK_ID_TYPES = \[(.*?)\] as const;/s', $ids, $list))->toBe(1);
    preg_match_all("/'([A-Za-z]+)'/", $list[1], $types);

    expect($types[1])->toEqualCanonicalizing(BlockIds::types());
});

it('offers every mark and block on the toolbar, not only in the slash menu', function (string $control): void {
    expect(file_get_contents(resource_path('js/components/editor/toolbar.tsx')))->toContain($control);
})->with(['bold', 'italic', 'underline', 'strike', 'code', 'highlight', 'link',
    'bulletList', 'orderedList', 'taskList', 'blockquote', 'codeBlock', 'horizontalRule', 'table',
    'subscript', 'superscript', 'textAlign', 'undo', 'redo']);

it('offers the horizontal rule in the slash menu as well as the Insert menu', function (): void {
    expect(file_get_contents(resource_path('js/components/editor/slash-menu.tsx')))->toContain('horizontalRule');
});

it('keeps the toolbar on one line, folding what does not fit into More', function (): void {
    $toolbar = file_get_contents(resource_path('js/components/editor/toolbar.tsx'));

    expect($toolbar)->not->toContain('flex-wrap')
        ->and($toolbar)->not->toContain('overflow-x-auto')
        ->and($toolbar)->toContain('ResizeObserver')
        ->and($toolbar)->toContain('More');
});

/**
 * Bold, italic, link and + Insert never fold, so everything else must, or a
 * phone-width row clips More itself and the folded controls with it (B-138).
 */
it('lets every control but bold, italic, link, Quote and Insert fold into More', function (): void {
    preg_match('/const FOLD_ORDER = \[(.*?)\]/s', file_get_contents(resource_path('js/components/editor/toolbar.tsx')), $match);

    expect($match[1] ?? '')->toContain("'undo'")
        ->toContain("'redo'")
        ->toContain("'text'")
        ->not->toContain("'blockquote'");
});

it('pins every tiptap package to one exact version', function (): void {
    $package = json_decode(file_get_contents(base_path('package.json')), true);
    $versions = [];

    foreach ($package['dependencies'] as $name => $version) {
        if (str_starts_with($name, '@tiptap/')) {
            expect($version)->not->toStartWith('^', "{$name} is not pinned");
            $versions[$version] = true;
        }
    }

    expect($versions)->toHaveCount(1)->and($versions)->not->toBeEmpty();
});

it('lets a link by ID through the browser only as the server reads one: an article, a term, a person or a file, a place at most', function (): void {
    $schema = file_get_contents(resource_path('js/components/editor/schema.ts'));

    expect($schema)->toContain('isAllowedUri')
        ->and($schema)->toContain('return linkTarget(href) !== null;');
});
