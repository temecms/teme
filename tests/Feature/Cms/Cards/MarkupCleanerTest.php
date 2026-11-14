<?php

declare(strict_types=1);

use App\Domain\Cards\Imported\MarkupCleaner;
use App\Domain\Cards\Imported\MarkupTree;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->clean = fn (string $html, ?Closure $href = null, ?Closure $picture = null) => app(MarkupCleaner::class)->clean($html, $href, $picture);
});

it('keeps every element on the list with its listed attributes, as the tree the card stores (I-329)', function (): void {
    $cleaned = ($this->clean)(
        '<table class="tablepress" id="tablepress-3"><caption>High water, this week</caption><thead><tr class="row-1"><th scope="col" class="column-1">Day</th><th scope="col">Time</th></tr></thead>'
        .'<tbody><tr><td>Monday</td><td>6:12<sup>am</sup></td></tr><tr><td colspan="2"><em>No sailings</em> on Tuesday &amp; Wednesday</td></tr></tbody></table>'."\n"
        .'<dl><dt>Ebb</dt><dd>The tide going out. See <a href="https://example.org/tides">the tide tables</a>.</dd></dl>'."\n"
        .'<p>H<sub>2</sub>O, <b>always</b> <code>cold</code>.<br>Mind the steps.</p>',
    );

    expect($cleaned->parts)->toEqual(icFixture()['content'][0]['attrs']['markup'])
        ->and($cleaned->removed)->toBe([]);
});

it('renames WordPress\'s older elements to the listed ones that mean the same (I-329)', function (): void {
    expect(($this->clean)('<h1>Title</h1><h5>Small</h5><h6>Smaller</h6><p><b>B</b> <i>I</i> <tt>T</tt> <kbd>K</kbd></p>')->parts)->toEqual([
        icEl('h2', [icText('Title')]), icEl('h4', [icText('Small')]), icEl('h4', [icText('Smaller')]),
        icEl('p', [icEl('strong', [icText('B')]), icText(' '), icEl('em', [icText('I')]), icText(' '), icEl('code', [icText('T')]), icText(' '), icEl('code', [icText('K')])]),
    ]);
});

it('removes scripts, styles, forms, frames and rules with all they hold, and counts each by name (I-336)', function (): void {
    $cleaned = ($this->clean)('<p onclick="steal()" style="color:red">Tide<script>steal()</script></p><style>p{}</style><form><input name="q"><button>Go</button></form><iframe src="https://tracker.example/"></iframe><hr><p>Next</p>');

    expect($cleaned->parts)->toEqual([icEl('p', [icText('Tide')]), icEl('p', [icText('Next')])])
        ->and($cleaned->removed)->toBe(['event-attribute' => 1, 'form' => 1, 'hr' => 1, 'iframe' => 1, 'script' => 1, 'style' => 1, 'style-attribute' => 1]);
});

it('unwraps what isn\'t on the list but keeps its words, and two divs stay two paragraphs (I-329)', function (): void {
    expect(($this->clean)('<div><span class="x">One</span></div><div><font face="Arial">Two</font> <u>three</u></div><section><p>Four</p><ul><li>Five</li></ul></section>')->parts)->toEqual([
        icEl('p', [icText('One')]), icEl('p', [icText('Two three')]), icEl('p', [icText('Four')]), icEl('ul', [icEl('li', [icText('Five')])]),
    ]);
});

it('gathers loose words at the top and in a quote into paragraphs, and drops the spaces between blocks (I-329)', function (): void {
    expect(($this->clean)("Loose words<blockquote>Said <em>so</em><p>Then this</p></blockquote>\n\n<p>After</p>")->parts)->toEqual([
        icEl('p', [icText('Loose words')]),
        icEl('blockquote', [icEl('p', [icText('Said '), icEl('em', [icText('so')])]), icEl('p', [icText('Then this')])]),
        icEl('p', [icText('After')]),
    ]);
});

it('puts stray parts where their parent wants them, and flattens blocks found inside a line (I-329)', function (): void {
    expect(($this->clean)('<ul>Stray<li>One</li></ul><dl><div><dt>Term</dt><dd>Meaning</dd></div></dl><a href="https://example.org/"><h2>A heading in a link</h2></a>')->parts)->toEqual([
        icEl('ul', [icEl('li', [icText('Stray')]), icEl('li', [icText('One')])]),
        icEl('dl', [icEl('dt', [icText('Term')]), icEl('dd', [icText('Meaning')])]),
        icEl('p', [icEl('a', [icText('A heading in a link')], ['href' => 'https://example.org/'])]),
    ]);
});

it('keeps a link through the converter\'s lookup, and unwraps one it can\'t keep, counting it (I-329)', function (): void {
    $article = strtolower((string) Str::ulid());
    $href = fn (string $address): ?string => match ($address) {
        'https://old.example/harbour/' => "teme:content/{$article}",
        'https://old.example/gone/' => null,
        default => $address,
    };

    $cleaned = ($this->clean)('<p><a href="https://old.example/harbour/">Harbour</a> and <a href="javascript:alert(1)">this</a> and <a href="https://old.example/gone/">gone</a></p>', $href);

    expect($cleaned->parts)->toEqual([icEl('p', [icEl('a', [icText('Harbour')], ['href' => "teme:content/{$article}"]), icText(' and this and gone')])])
        ->and($cleaned->removed)->toBe(['link' => 2]);
});

it('keeps a picture the library has, by its media ID and alt text, and counts one it hasn\'t (I-329)', function (): void {
    $id = (string) Str::ulid();
    $picture = fn (string $src, string $class): ?string => str_contains($class, 'wp-image-12') && str_ends_with($src, '/quay.jpg') ? $id : null;

    $cleaned = ($this->clean)('<p>Quay <img src="https://old.example/wp-content/uploads/quay.jpg" class="alignnone wp-image-12" alt=" The quay "><img src="https://elsewhere.example/x.png" alt="x"></p>', null, $picture);

    expect($cleaned->parts)->toEqual([icEl('p', [icText('Quay '), icEl('img', [], ['mediaId' => $id, 'alt' => 'The quay'])])])
        ->and($cleaned->removed)->toBe(['image' => 1]);
});

it('keeps characters as text, folds spaces outside pre and keeps them inside (I-329)', function (): void {
    expect(($this->clean)("<p>There’s   a\n<b>tide</b> &lt;now&gt; &amp; then</p><pre>  line one\n    line two</pre>")->parts)->toEqual([
        icEl('p', [icText('There’s a '), icEl('strong', [icText('tide')]), icText(' <now> & then')]),
        icEl('pre', [icText("  line one\n    line two")]),
    ]);
});

it('drops WordPress\'s block comments, empty paragraphs and spacer paragraphs (I-329)', function (): void {
    expect(($this->clean)('<!-- wp:html --><p>&nbsp;</p><p></p><p><br></p><p>Kept</p><!-- /wp:html -->')->parts)->toEqual([icEl('p', [icText('Kept')])]);
});

it('cleans the output of a shortcode whose plugin still runs on the old site (I-1704)', function (): void {
    $cleaned = ($this->clean)(
        '<table id="tablepress-3" class="tablepress tablepress-id-3"><thead><tr class="row-1"><th class="column-1">Ferry</th><th class="column-2">Leaves</th></tr></thead>'
        .'<tbody class="row-striping row-hover"><tr class="row-2"><td class="column-1">Bonny</td><td class="column-2">07:30</td></tr></tbody></table>'
        .'<script>jQuery(\'#tablepress-3\').dataTable();</script>',
    );

    expect($cleaned->parts)->toEqual([icEl('table', [
        icEl('thead', [icEl('tr', [icEl('th', [icText('Ferry')]), icEl('th', [icText('Leaves')])])]),
        icEl('tbody', [icEl('tr', [icEl('td', [icText('Bonny')]), icEl('td', [icText('07:30')])])]),
    ])])
        ->and($cleaned->removed)->toBe(['script' => 1])
        ->and($cleaned->fits())->toBeTrue()
        ->and($cleaned->text())->toBe('Ferry Leaves Bonny 07:30');
});

it('never makes a tree the card would refuse (I-329)', function (string $html): void {
    $cleaned = ($this->clean)($html, null, fn (): string => (string) Str::ulid());

    expect(MarkupTree::problem($cleaned->parts))->toBeNull();
})->with([
    'a table in a table' => ['<table><tr><td><table><tr><td>Nested</td></tr></table></td></tr></table>'],
    'a list in a list' => ['<ul><li>One<ul><li>Two</li></ul></li></ul><ol start="3" type="a"><li>Three</li></ol>'],
    'unclosed paragraphs' => ['<p>One<p>Two<div>Three</div>'],
    'items and cells with no list or table' => ['<li>Lonely item</li><td>Lonely cell</td><dt>Lonely term</dt>'],
    'a picture in a heading in a cell' => ['<table><tr><th colspan="99999" scope="ROW"><h3>Quay <img src="x.jpg"></h3></th></tr></table>'],
    'everything at once' => ['<div><figure><img src="a.jpg" alt="A"><figcaption>Caption <a href="#top">top</a></figcaption></figure><details><summary>More</summary><p>Hidden</p></details><blockquote><div>Quoted</div><cite>Someone</cite></blockquote></div>'],
]);

it('says whether a chunk fits the card: not when nothing is left, nor past 2,000 parts (I-329)', function (): void {
    expect(($this->clean)('<p>Tide</p>')->fits())->toBeTrue()
        ->and(($this->clean)('<script>steal()</script>')->fits())->toBeFalse()
        ->and(($this->clean)(str_repeat('<p>x</p>', 2001))->fits())->toBeFalse();
});
