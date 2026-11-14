<?php

declare(strict_types=1);

use App\Domain\Import\Phases\ImportMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * HTML's block structure (I-1722), as the native parser reads it,
 * into nodes the editor's own schema accepts.
 */

beforeEach(function (): void {
    $this->html = function (string $html, array $attachments = [], array $files = []): array {
        $conversion = wpConvert([wpBlock(null, $html)], $attachments, $files);
        wpAccepted($conversion->document);

        return [wpWithoutIds($conversion->document)['content'], array_map(
            fn (ImportMessage $message): array => [$message->code, $message->context],
            $conversion->messages,
        ), $conversion->document];
    };
    $this->p = fn (string $text, array $attrs = []): array => ['type' => 'paragraph', ...($attrs === [] ? [] : ['attrs' => $attrs]), 'content' => [['type' => 'text', 'text' => $text]]];
});

it('reads paragraphs, headings at levels 2 to 4, rules and code from HTML', function (): void {
    [$content] = ($this->html)("<p class=\"has-text-align-center\">One</p><h1>Title</h1><h3>Three</h3><h6>Six</h6><hr><pre class=\"language-PHP\"><code>echo 1;\n</code></pre>");

    $heading = fn (int $level, string $text): array => ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [['type' => 'text', 'text' => $text]]];

    expect($content)->toBe([
        ($this->p)('One', ['textAlign' => 'center']),
        $heading(2, 'Title'),
        $heading(3, 'Three'),
        $heading(4, 'Six'),
        ['type' => 'horizontalRule'],
        ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'echo 1;']]],
    ]);
});

it('wraps inline content beside blocks in paragraphs of its own', function (): void {
    [$content] = ($this->html)('Before<div><p>Inside</p>After div</div><span>Span</span>');

    expect($content)->toBe([($this->p)('Before'), ($this->p)('Inside'), ($this->p)('After div'), ($this->p)('Span')]);
});

it('reads nested lists, their start and type, each item starting with a paragraph', function (): void {
    [$content] = ($this->html)('<ol start="3" type="a" reversed><li>Three<ul><li>Inner</li></ul></li><li><ul><li>Only nested</li></ul></li></ol><ol start="x"><li>One</li></ol>');

    $bullets = fn (string $text): array => ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [($this->p)($text)]]]];

    expect($content)->toBe([
        ['type' => 'orderedList', 'attrs' => ['start' => 3, 'type' => 'a'], 'content' => [
            ['type' => 'listItem', 'content' => [($this->p)('Three'), $bullets('Inner')]],
            ['type' => 'listItem', 'content' => [['type' => 'paragraph'], $bullets('Only nested')]],
        ]],
        ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [($this->p)('One')]]]],
    ]);
});

it('reads a quote with its citation inside it', function (): void {
    [$content] = ($this->html)('<blockquote><p>Words</p><cite>Someone</cite></blockquote>');

    expect($content)->toBe([['type' => 'blockquote', 'content' => [($this->p)('Words'), ($this->p)('Someone')]]]);
});

it('reads a table with headers, spans and alignment, its caption after it', function (): void {
    [$content] = ($this->html)('<figure class="wp-block-table"><table><thead><tr><th>Name</th><th data-align="right">Qty</th></tr></thead><tbody><tr><td colspan="2" class="has-text-align-center">Both</td></tr><tr><td rowspan="0">Zero</td><td colspan="5000"></td></tr></tbody></table><figcaption>Stock</figcaption></figure>');

    $attrs = fn (int $colspan = 1, ?string $align = null): array => ['colspan' => $colspan, 'rowspan' => 1, 'colwidth' => null, 'align' => $align];
    $cell = fn (string $type, array $attrs, array $content): array => ['type' => $type, 'attrs' => $attrs, 'content' => $content];

    expect($content)->toBe([
        ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [$cell('tableHeader', $attrs(), [($this->p)('Name')]), $cell('tableHeader', $attrs(1, 'right'), [($this->p)('Qty')])]],
            ['type' => 'tableRow', 'content' => [$cell('tableCell', $attrs(2, 'center'), [($this->p)('Both')])]],
            ['type' => 'tableRow', 'content' => [$cell('tableCell', $attrs(), [($this->p)('Zero')]), $cell('tableCell', $attrs(1000), [['type' => 'paragraph']])]],
        ]],
        ($this->p)('Stock'),
    ]);
});

it('leaves out what an article never keeps, counting each by element', function (): void {
    [$content, $said] = ($this->html)('<p>Kept</p><script>x()</script><style>p{}</style><form><input name="q"><button>Go</button></form><iframe src="https://x.example"></iframe><noscript><img src="a.jpg"></noscript>');

    expect($content)->toBe([($this->p)('Kept')])
        ->and($said)->toBe([
            ['html.removed', ['element' => 'script', 'count' => 1]],
            ['html.removed', ['element' => 'style', 'count' => 1]],
            ['html.removed', ['element' => 'form', 'count' => 1]],
            ['html.removed', ['element' => 'iframe', 'count' => 1]],
        ]);
});

it('leaves out spacer paragraphs of no-break spaces', function (): void {
    [$content] = ($this->html)('<p>&nbsp;</p><p>Words</p><p> </p>');

    expect($content)->toBe([($this->p)('Words')]);
});

it('keeps the editor\'s content rules for empty items, cells and quotes', function (): void {
    [$content, , $document] = ($this->html)('<ul><li></li><li>Two</li></ul><blockquote><cite></cite></blockquote><table><tr><td></td></tr></table>');

    expect($content)->toBe([
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph']]], ['type' => 'listItem', 'content' => [($this->p)('Two')]]]],
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
            ['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null, 'align' => null], 'content' => [['type' => 'paragraph']]],
        ]]]],
    ])->and(wpEditorCheck(['empty parts' => $document]))->toBe(['empty parts' => null]);
});

it('keeps a list nested five deep within the document\'s depth, its words in order', function (): void {
    [$content, $said, $document] = ($this->html)('<ul><li>1<ul><li>2<ul><li>3<ul><li>4<ul><li>5</li></ul></li></ul></li></ul></li></ul></li></ul>');

    $item = fn (array ...$blocks): array => ['type' => 'listItem', 'content' => $blocks];
    $list = fn (array ...$items): array => ['type' => 'bulletList', 'content' => $items];

    expect($content)->toBe([
        $list($item(($this->p)('1'), $list($item(($this->p)('2'), $list($item(($this->p)('3'), ($this->p)('4'), ($this->p)('5'))))))),
    ])->and($said)->toBe([['block.too-deep', ['count' => 2]]])
        ->and(wpEditorCheck(['deep' => $document]))->toBe(['deep' => null]);
});

it('moves an image out of its paragraph, and makes a figure\'s image a card with its caption', function (): void {
    [$cat, $dog] = [(string) Str::ulid(), (string) Str::ulid()];

    [$content, $said] = ($this->html)('<p>See <img class="wp-image-7" src="https://old.example/wp-content/uploads/a.jpg" alt="A cat"> here</p><figure class="alignwide"><img src="https://old.example/wp-content/uploads/b.jpg" alt=""><figcaption>The <a href="https://x.example">dog</a></figcaption></figure>', [7 => $cat], ['b.jpg' => $dog]);

    expect($content)->toBe([
        ($this->p)('See here'),
        ['type' => 'image', 'attrs' => ['mediaId' => $cat, 'alt' => 'A cat', 'decorative' => false, 'caption' => null, 'credit' => null, 'width' => 'column']],
        ['type' => 'image', 'attrs' => ['mediaId' => $dog, 'alt' => null, 'decorative' => false, 'caption' => 'The dog', 'credit' => null, 'width' => 'wide']],
    ])->and($said)->toBe([['caption.links', ['count' => 1]]]);
});

it('reads a classic chunk from the HTML WordPress\'s wpautop made of it', function (): void {
    $conversion = wpConvert([[...wpBlock(null, "Line one\nLine two\n\nNext"), 'autop' => "<p>Line one<br />\nLine two</p>\n<p>Next</p>\n"]]);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Line one'], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'Line two']]],
        ($this->p)('Next'),
    ]));
});
