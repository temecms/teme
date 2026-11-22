<?php

declare(strict_types=1);

use App\Domain\Import\Convert\BlockMappings;
use App\Domain\Import\Convert\Blocks\Dropped;
use App\Domain\Import\Phases\ImportMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Core's rows (I-335): text, lists, tables and layouts mapped; dynamic
 * blocks and tables of contents dropped and named; reusable blocks left
 * for the second pass.
 */

beforeEach(function (): void {
    $this->convert = function (array $blocks, array $attachments = []): array {
        $conversion = wpConvert($blocks, $attachments);
        wpAccepted($conversion->document);

        return [
            wpWithoutIds($conversion->document)['content'],
            array_map(fn (ImportMessage $message): array => [$message->code, $message->message, $message->context], $conversion->messages),
            $conversion,
        ];
    };
    $this->text = fn (string $text, string ...$marks): array => $marks === []
        ? ['type' => 'text', 'text' => $text]
        : ['type' => 'text', 'text' => $text, 'marks' => array_map(fn (string $mark): array => ['type' => $mark], $marks)];
    $this->p = fn (string $text, array $attrs = []): array => ['type' => 'paragraph', ...($attrs === [] ? [] : ['attrs' => $attrs]), 'content' => [($this->text)($text)]];
});

it('converts paragraphs with their alignment, and leaves empty ones out', function (): void {
    [$content] = ($this->convert)([
        wpBlock('core/paragraph', '<p class="has-text-align-right">Right <strong>here</strong></p>'),
        wpBlock('core/paragraph', '<p></p>'),
    ]);

    expect($content)->toBe([['type' => 'paragraph', 'attrs' => ['textAlign' => 'right'], 'content' => [($this->text)('Right '), ($this->text)('here', 'bold')]]]);
});

it('converts headings to levels 2 to 4, each with a new ID, keeping their alignment and leaving WordPress\'s anchor', function (): void {
    [$content, , $conversion] = ($this->convert)([
        wpBlock('core/heading', '<h1 class="wp-block-heading">Big</h1>', ['level' => 1]),
        wpBlock('core/heading', '<h2 class="wp-block-heading has-text-align-center" id="getting-there">Getting there</h2>'),
        wpBlock('core/heading', '<h5 class="wp-block-heading">Small</h5>', ['level' => 5]),
    ]);

    $ids = array_map(fn (array $node): string => $node['attrs']['id'], $conversion->document['content']);

    expect($content)->toBe([
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [($this->text)('Big')]],
        ['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => 'center'], 'content' => [($this->text)('Getting there')]],
        ['type' => 'heading', 'attrs' => ['level' => 4], 'content' => [($this->text)('Small')]],
    ])->and(array_unique($ids))->toHaveCount(3);
});

it('converts verse, keeping its lines and marks', function (): void {
    [$content] = ($this->convert)([wpBlock('core/verse', "<pre class=\"wp-block-verse\">Roses are <em>red</em>,\n  violets   blue.</pre>")]);

    expect($content)->toBe([['type' => 'paragraph', 'content' => [
        ($this->text)('Roses are '), ($this->text)('red', 'italic'), ($this->text)(','), ['type' => 'hardBreak'], ($this->text)('violets blue.'),
    ]]]);
});

it('converts quotes and pullquotes, old and new, with their citations inside', function (): void {
    [$content] = ($this->convert)([
        wpBlock('core/quote', '<blockquote class="wp-block-quote">%s<cite>Ada</cite></blockquote>', [], [wpBlock('core/paragraph', '<p>Wise words</p>')]),
        wpBlock('core/quote', '<blockquote class="wp-block-quote"><p>Old words</p><cite>Bea</cite></blockquote>'),
        wpBlock('core/pullquote', '<figure class="wp-block-pullquote"><blockquote><p>Pulled</p><cite>Cy</cite></blockquote></figure>'),
    ]);

    $quote = fn (string $words, string $who): array => ['type' => 'blockquote', 'content' => [($this->p)($words), ($this->p)($who)]];

    expect($content)->toBe([$quote('Wise words', 'Ada'), $quote('Old words', 'Bea'), $quote('Pulled', 'Cy')]);
});

it('converts code and preformatted text as written, and a separator to a rule', function (): void {
    [$content] = ($this->convert)([
        wpBlock('core/code', "<pre class=\"wp-block-code\"><code>&lt;?php echo \"hi\";\n    return;</code></pre>"),
        wpBlock('core/preformatted', "<pre class=\"wp-block-preformatted\">Line  one\nLine two</pre>"),
        wpBlock('core/separator', '<hr class="wp-block-separator has-alpha-channel-opacity"/>'),
    ]);

    expect($content)->toBe([
        ['type' => 'codeBlock', 'content' => [($this->text)("<?php echo \"hi\";\n    return;")]],
        ['type' => 'codeBlock', 'content' => [($this->text)("Line  one\nLine two")]],
        ['type' => 'horizontalRule'],
    ]);
});

it('converts lists in the format of WordPress 6.1 and later, nested, with their start', function (): void {
    [$content] = ($this->convert)([wpBlock('core/list', '<ol start="2" class="wp-block-list">%s</ol>', ['ordered' => true, 'start' => 2], [
        wpBlock('core/list-item', '<li>Two%s</li>', [], [
            wpBlock('core/list', '<ul class="wp-block-list">%s</ul>', [], [wpBlock('core/list-item', '<li>Inner <em>one</em></li>')]),
        ]),
        wpBlock('core/list-item', '<li>Three</li>'),
    ])]);

    expect($content)->toBe([['type' => 'orderedList', 'attrs' => ['start' => 2], 'content' => [
        ['type' => 'listItem', 'content' => [
            ($this->p)('Two'),
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [($this->text)('Inner '), ($this->text)('one', 'italic')]]]]]],
        ]],
        ['type' => 'listItem', 'content' => [($this->p)('Three')]],
    ]]]);
});

it('converts lists in the older format from their HTML, and a list item outside a list as its words', function (): void {
    [$content] = ($this->convert)([
        wpBlock('core/list', '<ul><li>A<ul><li>B</li></ul></li></ul>'),
        wpBlock('core/list-item', '<li>Stray</li>'),
    ]);

    expect($content)->toBe([
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [
            ($this->p)('A'),
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [($this->p)('B')]]]],
        ]]]],
        ($this->p)('Stray'),
    ]);
});

it('converts a table, its caption a paragraph after it', function (): void {
    [$content] = ($this->convert)([wpBlock('core/table', '<figure class="wp-block-table"><table class="has-fixed-layout"><tbody><tr><td>1</td><td>2</td></tr></tbody></table><figcaption class="wp-element-caption">Numbers</figcaption></figure>')]);

    $cell = fn (string $text): array => ['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null, 'align' => null], 'content' => [($this->p)($text)]];

    expect($content)->toBe([
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [$cell('1'), $cell('2')]]]],
        ($this->p)('Numbers'),
    ]);
});

it('keeps what columns, groups and other layouts hold, in order, without a word', function (): void {
    $column = fn (string $text): array => wpBlock('core/column', '<div class="wp-block-column">%s</div>', [], [wpBlock('core/paragraph', "<p>{$text}</p>")]);

    [$content, $said] = ($this->convert)([
        wpBlock('core/columns', '<div class="wp-block-columns">%s</div>', [], [$column('Left'), $column('Right')]),
        wpBlock('core/group', '<div class="wp-block-group">%s</div>', ['layout' => ['type' => 'flex']], [wpBlock('core/heading', '<h2 class="wp-block-heading">In a row</h2>')]),
        wpBlock('canvas/section', '<div class="cnvs-block-section">%s</div>', [], [wpBlock('core/paragraph', '<p>Canvas</p>')]),
        wpBlock('core/text-columns', '<div class="wp-block-text-columns has-2-columns"><div class="wp-block-column"><p>One</p></div><div class="wp-block-column"><p>Two</p></div></div>', ['columns' => 2]),
    ]);

    expect($content)->toBe([
        ($this->p)('Left'), ($this->p)('Right'),
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [($this->text)('In a row')]],
        ($this->p)('Canvas'), ($this->p)('One'), ($this->p)('Two'),
    ])->and($said)->toBe([]);
});

it('keeps a details block\'s summary as a bold paragraph, and an accordion\'s heading as a heading', function (): void {
    [$content] = ($this->convert)([
        wpBlock('core/details', '<details class="wp-block-details"><summary>More <em>info</em></summary>%s</details>', [], [wpBlock('core/paragraph', '<p>Hidden</p>')]),
        wpBlock('core/accordion-heading', '<h3 class="wp-block-accordion-heading"><button class="wp-block-accordion-heading__toggle"><span class="wp-block-accordion-heading__toggle-title">Shipping</span><span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span></button></h3>'),
    ]);

    expect($content)->toBe([
        ['type' => 'paragraph', 'content' => [($this->text)('More ', 'bold'), ($this->text)('info', 'italic', 'bold')]],
        ($this->p)('Hidden'),
        ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [($this->text)('Shipping')]],
    ]);
});

it('keeps a cover\'s blocks, leaving its background image out and saying so', function (): void {
    [$content, $said] = ($this->convert)([wpBlock(
        'core/cover',
        '<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-5" src="https://old.example/wp-content/uploads/hero.jpg"/><div class="wp-block-cover__inner-container">%s</div></div>',
        ['url' => 'https://old.example/wp-content/uploads/hero.jpg', 'id' => 5],
        [wpBlock('core/heading', '<h2 class="wp-block-heading">Welcome</h2>')],
    )]);

    expect($content)->toBe([['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [($this->text)('Welcome')]]])
        ->and($said)->toBe([['block.background', 'Left out the background image of 1 core/cover block; what it held is kept.', ['name' => 'core/cover', 'count' => 1]]]);
});

it('puts a media and text block\'s image before its words', function (): void {
    $map = (string) Str::ulid();

    [$content] = ($this->convert)([wpBlock(
        'core/media-text',
        '<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="https://old.example/wp-content/uploads/m.jpg" alt="Map" class="wp-image-9 size-full"/></figure><div class="wp-block-media-text__content">%s</div></div>',
        ['mediaId' => 9, 'mediaType' => 'image'],
        [wpBlock('core/paragraph', '<p>Beside</p>')],
    )], [9 => $map]);

    expect($content)->toBe([
        ['type' => 'image', 'attrs' => ['mediaId' => $map, 'alt' => 'Map', 'decorative' => false, 'caption' => null, 'credit' => null, 'width' => 'column']],
        ($this->p)('Beside'),
    ]);
});

it('drops core\'s dynamic blocks and tables of contents, naming each, and joins an article\'s pages', function (): void {
    [$content, $said] = ($this->convert)([
        wpBlock('core/latest-posts', '', ['postsToShow' => 5]),
        wpBlock('core/latest-posts', ''),
        wpBlock('core/query', '<div class="wp-block-query">%s</div>', [], [wpBlock('core/post-template', '')]),
        wpBlock('simpletoc/toc', '<h2>Contents</h2><ul><li><a href="#a">A</a></li></ul>'),
        wpBlock('core/nextpage', '<!--nextpage-->'),
        wpBlock('core/nextpage', ''),
        wpBlock('core/spacer', '<div style="height:50px" aria-hidden="true" class="wp-block-spacer"></div>'),
        wpBlock('core/more', '<!--more-->'),
    ]);

    expect($content)->toBe([['type' => 'paragraph']])
        ->and($said)->toBe([
            ['block.dynamic', 'Left out 2 core/latest-posts blocks: WordPress drew them when the page loaded, and on Teme the theme does that job.', ['name' => 'core/latest-posts', 'count' => 2]],
            ['block.dynamic', 'Left out 1 core/query block: WordPress drew it when the page loaded, and on Teme the theme does that job.', ['name' => 'core/query', 'count' => 1]],
            ['block.contents-list', 'Left out 1 table of contents (simpletoc/toc): Teme and its theme don’t draw one; a table-of-contents plugin can.', ['name' => 'simpletoc/toc', 'count' => 1]],
            ['block.page-break', 'Joined the article’s 3 pages into one: Teme shows each article on a single page.', ['count' => 2]],
        ]);
});

it('finds every core row by each name it claims', function (): void {
    $mappings = app(BlockMappings::class);

    foreach (BlockMappings::CORE as $class) {
        foreach (app($class)->blocks() as $name) {
            expect($mappings->find(str_replace('*', 'any-block', $name)))->toBeInstanceOf($class);
        }
    }

    expect(Dropped::DYNAMIC)->toHaveCount(69)
        ->and(array_unique(Dropped::DYNAMIC))->toHaveCount(69);
});

it('notes where reusable blocks go, without writing them into the document', function (): void {
    [$content, , $conversion] = ($this->convert)([
        wpBlock('core/paragraph', '<p>A</p>'),
        wpBlock('core/block', '', ['ref' => 41]),
        wpBlock('core/paragraph', '<p>B</p>'),
        wpBlock('core/columns', '<div class="wp-block-columns">%s</div>', [], [wpBlock('core/column', '<div class="wp-block-column">%s</div>', [], [wpBlock('core/block', '', ['ref' => 42])])]),
        wpBlock('core/quote', '<blockquote class="wp-block-quote">%s</blockquote>', [], [wpBlock('core/paragraph', '<p>C</p>'), wpBlock('core/block', '', ['ref' => 43])]),
        wpBlock('core/block', ''),
    ]);

    expect($content)->toBe([($this->p)('A'), ($this->p)('B'), ['type' => 'blockquote', 'content' => [($this->p)('C')]]])
        ->and(array_map(fn ($reference): array => $reference->toArray(), $conversion->references))->toBe([
            ['type' => 'post', 'id' => '41', 'at' => [1]],
            ['type' => 'post', 'id' => '42', 'at' => [2]],
            ['type' => 'post', 'id' => '43', 'at' => [2, 1]],
        ]);
});

it('takes a block\'s attributes in whatever shape the companion\'s JSON gives them', function (): void {
    [$content] = ($this->convert)([
        ['blockName' => 'core/heading', 'attrs' => ['level' => '3'], 'innerHTML' => '<h3>Q</h3>'],
        ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => 'none', 'innerHTML' => '<p>P</p>', 'innerContent' => null],
        ['blockName' => 'Core/Separator', 'attrs' => [0 => 'x']],
    ]);

    expect($content)->toBe([
        ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [($this->text)('Q')]],
        ($this->p)('P'),
        ['type' => 'horizontalRule'],
    ]);
});

it('keeps every word of a paragraph block whose paragraph holds paragraphs, each its own paragraph, without a word', function (): void {
    [$content, $said] = ($this->convert)([
        wpBlock('core/paragraph', '<p class="wp-block-paragraph"><p>Aichmophobia is a fear of sharp things.</p></p>'),
        wpBlock('core/paragraph', '<p><p>First <em>inner</em> words.</p><p class="has-text-align-right">Second inner words.</p></p>'),
    ]);

    expect($content)->toBe([
        ($this->p)('Aichmophobia is a fear of sharp things.'),
        ['type' => 'paragraph', 'content' => [($this->text)('First '), ($this->text)('inner', 'italic'), ($this->text)(' words.')]],
        ($this->p)('Second inner words.', ['textAlign' => 'right']),
    ])->and($said)->toBe([]);
});

it('keeps the words around paragraphs or a list inside a paragraph, in order, each alignment on the words it was on', function (): void {
    [$content, $said] = ($this->convert)([
        wpBlock('core/paragraph', '<p class="has-text-align-center">Before <p>Inside</p> after</p>'),
        wpBlock('core/paragraph', '<p>Names: <ul><li>Ada</li><li>Bea</li></ul></p>'),
    ]);

    expect($content)->toBe([
        ($this->p)('Before', ['textAlign' => 'center']),
        ($this->p)('Inside'),
        ($this->p)('after'),
        ($this->p)('Names:'),
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [($this->p)('Ada')]],
            ['type' => 'listItem', 'content' => [($this->p)('Bea')]],
        ]],
    ])->and($said)->toBe([]);
});
