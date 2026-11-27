<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentRenderer;

it('wraps each table in a sideways-scroll region, leaving the table HTML unchanged', function (): void {
    $cell = fn (string $type, string $text): array => ['type' => $type, 'attrs' => [], 'content' => [docText($text)]];
    $document = docWith(['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
        $cell('tableHeader', 'Stall'),
        $cell('tableCell', 'Oysters'),
    ]]]]);

    $html = app(DocumentRenderer::class)->html($document);

    expect($html)->toBe(
        '<div class="table-scroll" tabindex="0" role="region" aria-label="Table"><table><tbody><tr><th><p>Stall</p></th><td><p>Oysters</p></td></tr></tbody></table></div>',
    );
});

it('keeps the table-scroll rule in core’s card styles layer', function (): void {
    $css = trim((string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(base_path('resources/css/cards/table.css'))));

    expect($css)->toStartWith('@layer teme.core {')
        ->toContain('.table-scroll')
        ->toContain('overflow-x: auto')
        ->toEndWith('}');
});
