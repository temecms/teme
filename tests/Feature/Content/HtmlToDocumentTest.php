<?php

declare(strict_types=1);

use App\Domain\Plugins\Seams\PluginServices;
use Teme\Contracts\Content\HtmlToDocument;

/**
 * The node types of a document's top level, in order.
 *
 * @param  array<string, mixed>  $document
 *
 * @return list<string>
 */
function htmlDocTypes(array $document): array
{
    return array_map(fn (array $node): string => $node['type'], $document['content']);
}

it('is bound in core and offered to plugins', function (): void {
    expect(app(HtmlToDocument::class))->toBeInstanceOf(HtmlToDocument::class)
        ->and(PluginServices::OFFERED)->toContain(HtmlToDocument::class);
});

it('converts headings and paragraphs', function (): void {
    $document = app(HtmlToDocument::class)->convert('<h2>Install</h2><p>Run the <strong>installer</strong>.</p><h3>Then</h3>');

    expect($document['type'])->toBe('doc')
        ->and(htmlDocTypes($document))->toBe(['heading', 'paragraph', 'heading'])
        ->and($document['content'][0]['attrs']['level'])->toBe(2)
        ->and($document['content'][2]['attrs']['level'])->toBe(3)
        ->and(json_encode($document['content'][1]))->toContain('"bold"')->toContain('installer');
});

it('converts bulleted and numbered lists', function (): void {
    $document = app(HtmlToDocument::class)->convert('<ul><li>One</li><li>Two</li></ul><ol><li>First</li></ol>');

    expect(htmlDocTypes($document))->toBe(['bulletList', 'orderedList'])
        ->and(array_column($document['content'][0]['content'], 'type'))->toBe(['listItem', 'listItem']);
});

it('converts a table', function (): void {
    $document = app(HtmlToDocument::class)->convert('<table><tr><th>Key</th><th>Words</th></tr><tr><td>http</td><td>Reaches the web</td></tr></table>');

    expect(htmlDocTypes($document))->toBe(['table'])
        ->and(count($document['content'][0]['content']))->toBe(2)
        ->and(json_encode($document))->toContain('Reaches the web');
});

it('converts an image, kept at the address given', function (): void {
    $document = app(HtmlToDocument::class)->convert('<p><img src="https://example.org/shot.png" alt="The screen"></p>');

    expect(json_encode($document, JSON_UNESCAPED_SLASHES))->toContain('https://example.org/shot.png')->toContain('The screen');
});

it('converts code', function (): void {
    $document = app(HtmlToDocument::class)->convert('<pre><code class="language-php">echo 1;</code></pre><p>Call <code>run()</code>.</p>');

    expect(htmlDocTypes($document))->toBe(['codeBlock', 'paragraph'])
        ->and(json_encode($document['content'][0]))->toContain('echo 1;')
        ->and(json_encode($document['content'][1]))->toContain('"code"');
});

it('leaves out script', function (): void {
    $document = app(HtmlToDocument::class)->convert('<p>Kept</p><script>alert(1)</script>');

    expect(json_encode($document))->toContain('Kept')->not->toContain('alert');
});
