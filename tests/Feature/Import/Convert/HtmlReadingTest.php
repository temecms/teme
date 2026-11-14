<?php

declare(strict_types=1);

use App\Domain\Import\Convert\ConversionReport;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\Html\InlineContent;
use App\Domain\Import\Phases\ImportMessage;

/*
 * I-1722: WordPress's HTML is read by PHP's own HTML5 parser first; tiptap-php
 * only ever reads a textblock's inline HTML, serialised from that tree, for
 * its marks.
 */

beforeEach(function (): void {
    $this->report = new ConversionReport;
    $this->inline = function (string $html): array {
        $paragraph = HtmlFragment::body("<p>{$html}</p>")->querySelector('p');

        return app(InlineContent::class)->read($paragraph, $this->report)['nodes'];
    };
    $this->said = fn (): array => array_map(
        fn (ImportMessage $message): array => [$message->code, $message->message, $message->context],
        $this->report->messages(),
    );
});

it('parses broken markup as a browser does, in UTF-8', function (): void {
    $body = HtmlFragment::body('<p>Café <b>bold <i>both</b> after</p><p>Next');

    // HTML5 reopens the unclosed <i> in the next paragraph.
    expect($body->innerHTML)->toBe('<p>Café <b>bold <i>both</i></b><i> after</i></p><p><i>Next</i></p>');
});

it('collapses runs of spaces and line breaks outside preformatted text, as a browser shows them', function (): void {
    $body = HtmlFragment::body("<p>One\n   two\t three</p><pre>  keep\n    this</pre>");

    expect($body->querySelector('p')->textContent)->toBe('One two three')
        ->and($body->querySelector('pre')->textContent)->toBe("  keep\n    this")
        ->and(HtmlFragment::words("  a \n b  "))->toBe('a b');
});

it('reads marks from tags and from styles', function (): void {
    $nodes = ($this->inline)('<strong>a</strong> <em>b</em> <u>c</u> <s>d</s> <code>e</code> <mark>f</mark> <sub>g</sub> <sup>h</sup> <span style="font-weight: 700">i</span> <span style="font-weight: 400">j</span>');

    $marked = fn (string $text, string $mark): array => ['type' => 'text', 'text' => $text, 'marks' => [['type' => $mark]]];
    $space = ['type' => 'text', 'text' => ' '];

    expect($nodes)->toBe([
        $marked('a', 'bold'), $space, $marked('b', 'italic'), $space, $marked('c', 'underline'), $space,
        $marked('d', 'strike'), $space, $marked('e', 'code'), $space, $marked('f', 'highlight'), $space,
        $marked('g', 'subscript'), $space, $marked('h', 'superscript'), $space, $marked('i', 'bold'),
        ['type' => 'text', 'text' => ' j'],
    ]);
});

it('keeps a link\'s address and a target of _blank, and nothing else', function (): void {
    $nodes = ($this->inline)('<a href="https://example.com/x" target="_blank" rel="noopener" class="c" title="T">Go</a> <a href="/about/" target="_self">About</a> <a href="#part">Part</a> <a href="mailto:ada@example.com">Mail</a>');

    $link = fn (string $text, array $attrs): array => ['type' => 'text', 'text' => $text, 'marks' => [['type' => 'link', 'attrs' => $attrs]]];
    $space = ['type' => 'text', 'text' => ' '];

    expect($nodes)->toBe([
        $link('Go', ['href' => 'https://example.com/x', 'target' => '_blank']), $space,
        $link('About', ['href' => '/about/']), $space,
        $link('Part', ['href' => '#part']), $space,
        $link('Mail', ['href' => 'mailto:ada@example.com']),
    ]);
});

it('removes a link Teme can\'t keep, keeping its words, and gives a scheme to //host', function (): void {
    $nodes = ($this->inline)('<a href="javascript:alert(1)">One</a> <a href="ftp://files.example/x">two</a> <a href="page.html">three</a> <a name="top">four</a> <a href="//cdn.example/x">five</a>');

    expect($nodes)->toBe([
        ['type' => 'text', 'text' => 'One two three four '],
        ['type' => 'text', 'text' => 'five', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://cdn.example/x']]]],
    ])->and(($this->said)())->toBe([
        ['link.removed', 'Kept the words of 3 links whose addresses Teme can’t keep, without the links.', ['count' => 3]],
    ]);
});

it('keeps a link on inline code, and code alone otherwise, as the editor\'s code mark requires', function (): void {
    $nodes = ($this->inline)('<a href="https://docs.example/"><code>fn()</code></a> and <b><code>x</code></b>');

    expect($nodes)->toBe([
        ['type' => 'text', 'text' => 'fn()', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://docs.example/']]]],
        ['type' => 'text', 'text' => ' and '],
        ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'code']]],
    ]);
});

it('reads a paragraph that is only a number, a word JSON knows, or a quoted string as text', function (string $text): void {
    expect(($this->inline)(htmlspecialchars($text)))->toBe([['type' => 'text', 'text' => $text]]);
})->with(['2024', 'true', 'null', '"Yes"', '[1]', '{}']);

it('turns line breaks into hard breaks, trimming spaces beside them and at the ends', function (): void {
    expect(($this->inline)('  First line <br> second <br><br> third <br> '))->toBe([
        ['type' => 'text', 'text' => 'First line'],
        ['type' => 'hardBreak'],
        ['type' => 'text', 'text' => 'second'],
        ['type' => 'hardBreak'],
        ['type' => 'hardBreak'],
        ['type' => 'text', 'text' => 'third'],
    ]);
});

it('joins spaces across marks as a browser shows them', function (): void {
    expect(($this->inline)('Hello <b> world</b>'))->toBe([
        ['type' => 'text', 'text' => 'Hello '],
        ['type' => 'text', 'text' => 'world', 'marks' => [['type' => 'bold']]],
    ]);
});

it('takes images out for the caller, an emoji image becoming its character', function (): void {
    $paragraph = HtmlFragment::body('<p>A <img src="https://old.example/wp-content/uploads/a.jpg" alt="A"> b <img class="wp-smiley" alt="☺" src="https://s.w.org/images/core/emoji/x.png"> <a href="https://old.example/wp-content/uploads/b.jpg"><img src="https://old.example/wp-content/uploads/b-300x200.jpg"></a></p>')->querySelector('p');

    $read = app(InlineContent::class)->read($paragraph, $this->report);

    expect($read['nodes'])->toBe([['type' => 'text', 'text' => 'A b ☺']])
        ->and(array_map(fn (array $image): array => [$image['element']->getAttribute('src'), $image['link']], $read['images']))->toBe([
            ['https://old.example/wp-content/uploads/a.jpg', null],
            ['https://old.example/wp-content/uploads/b-300x200.jpg', 'https://old.example/wp-content/uploads/b.jpg'],
        ]);
});

it('leaves out a textblock with only spaces and no-break spaces', function (): void {
    expect(($this->inline)('&nbsp; &nbsp;'))->toBe([]);
});

it('says each kind of message once per name, with its count and a singular for one', function (): void {
    $this->report->count('block.dynamic', 'core/latest-posts');
    $this->report->count('block.dynamic', 'core/rss');
    $this->report->count('block.dynamic', 'core/latest-posts');
    $this->report->count('html.removed', 'script');
    $this->report->count('image.outside', '', 'cdn.other.example');
    $this->report->count('block.page-break');

    expect(($this->said)())->toBe([
        ['block.dynamic', 'Left out 2 core/latest-posts blocks: WordPress drew them when the page loaded, and on Teme the theme does that job.', ['name' => 'core/latest-posts', 'count' => 2]],
        ['block.dynamic', 'Left out 1 core/rss block: WordPress drew it when the page loaded, and on Teme the theme does that job.', ['name' => 'core/rss', 'count' => 1]],
        ['html.removed', 'Left out 1 script element, which Teme doesn’t keep in an article.', ['element' => 'script', 'count' => 1]],
        ['image.outside', 'Linked to 1 image on another site instead of showing it: cdn.other.example.', ['count' => 1, 'hosts' => ['cdn.other.example']]],
        ['block.page-break', 'Joined the article’s 2 pages into one: Teme shows each article on a single page.', ['count' => 1]],
    ]);
});

it('names at most ten files, then how many more', function (): void {
    foreach (range(1, 12) as $number) {
        $this->report->count('image.missing', '', "f{$number}.jpg");
    }

    $this->report->count('image.missing', '', 'f1.jpg');
    [$message] = $this->report->messages();

    expect($message->message)->toBe('Left out 13 images that aren’t in Teme’s library: f1.jpg, f2.jpg, f3.jpg, f4.jpg, f5.jpg, f6.jpg, f7.jpg, f8.jpg, f9.jpg, f10.jpg and 2 more.')
        ->and($message->context['files'])->toHaveCount(10)
        ->and($message->context['count'])->toBe(13);
});

it('refuses a code it has no words for', function (): void {
    $this->report->count('block.nonsense');

    expect(fn () => $this->report->messages())->toThrow(LogicException::class, 'No words for the import message [block.nonsense].');
});
