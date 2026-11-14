<?php

declare(strict_types=1);

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\ConversionItem;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Convert\Shortcodes\Shortcode;
use App\Domain\Import\Convert\Shortcodes\ShortcodeMapping;
use App\Domain\Import\Convert\Shortcodes\ShortcodeMappings;
use App\Domain\Import\Convert\Shortcodes\ShortcodeParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Convert\WordPressContent;

uses(RefreshDatabase::class);

/*
 * I-336's bracket rule: a bracket is a shortcode only when it was
 * registered, belongs to a known family, has a named attribute or is
 * closed; a dead wrapper keeps its words, a dead tag goes, and what an
 * active plugin drew is kept (I-1704).
 */

beforeEach(function (): void {
    // A classic chunk of one paragraph: its words, and what was said.
    $this->line = function (string $html, array $shortcodes = [], ?ConversionItem $item = null): array {
        $conversion = WordPressContent::convert([WordPressContent::classic("<p>{$html}</p>\n")], item: $item, shortcodes: $shortcodes);
        wpAccepted($conversion->document);

        return [implode('', array_column($conversion->document['content'][0]['content'] ?? [], 'text')), WordPressContent::said($conversion)];
    };
    $this->row = fn (array $names, array $nodes): ShortcodeMapping => new class($names, $nodes) implements ShortcodeMapping
    {
        public function __construct(private array $names, private array $nodes) {}

        public function shortcodes(): array
        {
            return $this->names;
        }

        public function convert(Shortcode $shortcode, BlockContext $context): array
        {
            return $this->nodes;
        }
    };
    $this->tag = function (ShortcodeMapping ...$rows): void {
        $names = [];

        foreach ($rows as $index => $row) {
            $this->app->instance("test.shortcode-row.{$index}", $row);
            $names[] = "test.shortcode-row.{$index}";
        }

        $this->app->tag($names, ShortcodeMappings::TAG);
    };
});

it('reads a shortcode’s attributes as WordPress does', function (string $text, array $read): void {
    expect(ShortcodeParser::attributes($text))->toBe($read);
})->with([
    'quoted, unquoted and by place' => [' id="3" Title=\'A b\' size=large 5 "x y"', ['id' => '3', 'title' => 'A b', 'size' => 'large', 0 => '5', 1 => 'x y']],
    'a no-break space between' => [' id="3"'.mb_chr(0xA0).'size=large', ['id' => '3', 'size' => 'large']],
    'unclosed HTML in a value' => [' caption="a <b"', ['caption' => '']],
    'none' => ['', []],
]);

it('counts a bracket as a shortcode only when it was registered, belongs to a known family, has a named attribute or is closed', function (): void {
    [$words, $said] = ($this->line)('Dear [your name], see [State], [mg] and [1](https://e.example/). [td_block_ad_box][ad spot="top"] [note]Read me[/note]');

    expect($words)->toBe('Dear [your name], see [State], [mg] and [1](https://e.example/). Read me')
        ->and($said)->toBe([
            ['shortcode.removed', 'Left out 1 [td_block_ad_box] shortcode: its plugin or theme no longer runs on the old site, so readers saw it as bracketed text.', ['name' => 'td_block_ad_box', 'count' => 1]],
            ['shortcode.removed', 'Left out 1 [ad] shortcode: its plugin or theme no longer runs on the old site, so readers saw it as bracketed text.', ['name' => 'ad', 'count' => 1]],
            ['shortcode.unwrapped', 'Kept the words inside 1 [note] shortcode without it: its plugin or theme no longer runs on the old site.', ['name' => 'note', 'count' => 1]],
        ]);
});

it('unwraps a dead wrapper’s words and the shortcodes nested in it, whatever paragraphs wpautop put inside', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic("<p>[vc_row][vc_column]</p>\n<p>[vc_column_text]Hello</p>\n<p>World[/vc_column_text][/vc_column][/vc_row]</p>\n")]);
    wpAccepted($conversion->document);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [WordPressContent::p('Hello'), WordPressContent::p('World')]))
        ->and(array_map(fn (array $one): array => [$one[0], $one[2]['name']], WordPressContent::said($conversion)))->toBe([
            ['shortcode.unwrapped', 'vc_row'],
            ['shortcode.unwrapped', 'vc_column'],
            ['shortcode.unwrapped', 'vc_column_text'],
        ]);
});

it('pairs shortcodes of one name as HTML pairs elements, and keeps a stray closing tag of an unknown name as text', function (): void {
    [$words, $said] = ($this->line)('[box]Outer [box]inner[/box] end[/box] [/box] [/gone]');

    expect($words)->toBe('Outer inner end [/box] [/gone]')
        ->and(array_map(fn (array $one): array => [$one[0], $one[2]], $said))->toBe([['shortcode.unwrapped', ['name' => 'box', 'count' => 2]]]);
});

it('keeps an escaped shortcode as its literal text', function (): void {
    [$words, $said] = ($this->line)('Write [[gallery]] or [[caption]a picture[/caption]] to show one.');

    expect($words)->toBe('Write [gallery] or [caption]a picture[/caption] to show one.')
        ->and($said)->toBe([]);
});

it('leaves brackets in code, preformatted text and attributes alone', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic('<p>Use <code>[gallery ids="1"]</code> here.</p><pre>[vc_row]x[/vc_row]</pre><p><a href="https://e.example/?q=[ad]">Search</a> [ad spot="x"]</p>')]);
    wpAccepted($conversion->document);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Use '],
            ['type' => 'text', 'text' => '[gallery ids="1"]', 'marks' => [['type' => 'code']]],
            ['type' => 'text', 'text' => ' here.'],
        ]],
        ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => '[vc_row]x[/vc_row]']]],
        WordPressContent::linked('Search', 'https://e.example/?q=[ad]'),
    ]))->and(array_column(WordPressContent::said($conversion), 0))->toBe(['shortcode.removed']);
});

it('finds shortcodes in blocks as well as in classic text, as WordPress runs them over the whole article', function (): void {
    $conversion = WordPressContent::convert([
        wpBlock('core/paragraph', '<p>Before [ad spot="1"] after</p>'),
        wpBlock('core/shortcode', '[td_block_social_counter style="x"]'),
        wpBlock('core/heading', '<h2 class="wp-block-heading">[vc_highlight]Key[/vc_highlight] facts</h2>', ['level' => 2]),
    ]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::p('Before after'),
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Key facts']]],
    ])->and(array_column(WordPressContent::said($conversion), 0))->toBe(['shortcode.removed', 'shortcode.removed', 'shortcode.unwrapped']);
});

it('lets a plugin’s row convert its shortcode', function (): void {
    ($this->tag)(($this->row)(['acme_note'], [Nodes::rule()]));

    $conversion = WordPressContent::convert([WordPressContent::classic("<p>[acme_note]</p>\n")]);

    expect($conversion->document['content'])->toBe([['type' => 'horizontalRule']])
        ->and($conversion->messages)->toBe([]);
});

it('refuses two shortcode rows for one name', function (): void {
    ($this->tag)(($this->row)(['acme_note'], []), ($this->row)(['acme_note'], []));

    expect(fn () => app(ShortcodeMappings::class)->find('acme_note'))->toThrow(LogicException::class, 'Two shortcode mappings convert [acme_note]');
});

it('keeps what a shortcode whose plugin still runs drew as an Imported content card, naming the shortcode', function (): void {
    $conversion = WordPressContent::convert(
        [WordPressContent::classic("<p>Fares:</p>\n<p>[table id=3 /]</p>\n")],
        item: new ConversionItem(12, [['open' => '[table id=3 /]', 'html' => '<table id="tablepress-3" class="tablepress"><thead><tr><th class="column-1">Boat</th></tr></thead><tbody class="row-striping"><tr class="row-1"><td class="column-1">Ada</td></tr></tbody></table>']]),
        shortcodes: ['table'],
    );
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::p('Fares:'),
        WordPressContent::card([icEl('table', [
            icEl('thead', [icEl('tr', [icEl('th', [icText('Boat')])])]),
            icEl('tbody', [icEl('tr', [icEl('td', [icText('Ada')])])]),
        ])], 'shortcode', 'table'),
    ])->and(WordPressContent::said($conversion))->toBe([
        ['shortcode.imported', 'Kept what 1 [table] shortcode drew on the old site as imported content: its plugin still runs there, and Teme has no match for it.', ['name' => 'table', 'count' => 1]],
    ]);
});

it('keeps the words a shortcode whose plugin still runs drew inside a line, in their line', function (): void {
    [$words, $said] = ($this->line)('© [year] Harbour. Take the [tip text="Fast boats"]ferries[/tip].', ['year', 'tip'], new ConversionItem(12, [
        ['open' => '[year]', 'html' => '2026'],
        ['open' => '[tip text=&quot;Fast boats&quot;]', 'html' => '<span class="tip" title="Fast boats">ferries</span>'],
    ]));

    expect($words)->toBe('© 2026 Harbour. Take the ferries.')
        ->and($said)->toBe([
            ['shortcode.inline', 'Kept the words 1 [year] shortcode drew on the old site, in their line: its plugin still runs there.', ['name' => 'year', 'count' => 1]],
            ['shortcode.inline', 'Kept the words 1 [tip] shortcode drew on the old site, in their line: its plugin still runs there.', ['name' => 'tip', 'count' => 1]],
        ]);
});

it('leaves out a shortcode whose plugin still runs when the export did not carry what it drew, keeping the words inside it', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic("<p>[contact-form-7 id=\"5\" title=\"Contact\"]</p>\n<p>[highlight]Key point[/highlight]</p>\n")], shortcodes: ['contact-form-7', 'highlight']);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [WordPressContent::p('Key point')]))
        ->and(WordPressContent::said($conversion))->toBe([
            ['shortcode.unrendered', 'Left out 1 [contact-form-7] shortcode, keeping any words inside it: its plugin still runs on the old site, but the export didn’t carry what it drew.', ['name' => 'contact-form-7', 'count' => 1]],
            ['shortcode.unrendered', 'Left out 1 [highlight] shortcode, keeping any words inside it: its plugin still runs on the old site, but the export didn’t carry what it drew.', ['name' => 'highlight', 'count' => 1]],
        ]);
});

it('gives each shortcode written alike the next of what the plugin drew, in order, even words that read as JSON', function (): void {
    $conversion = WordPressContent::convert(
        [WordPressContent::classic("<p>[price]</p>\n<p>[price]</p>\n<p>[price]</p>\n")],
        item: new ConversionItem(12, [['open' => '[price]', 'html' => '42'], ['open' => '[price]', 'html' => 'true']]),
        shortcodes: ['price'],
    );
    wpAccepted($conversion->document);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [WordPressContent::p('42'), WordPressContent::p('true')]))
        ->and(array_column(WordPressContent::said($conversion), 0))->toBe(['shortcode.inline', 'shortcode.unrendered']);
});

it('puts a plugin’s unclosed inline output into its line closed, so the words after it stay its own', function (): void {
    $conversion = WordPressContent::convert(
        [WordPressContent::classic("<p>Only [price] today.</p>\n")],
        item: new ConversionItem(12, [['open' => '[price]', 'html' => '<strong>£3']]),
        shortcodes: ['price'],
    );
    wpAccepted($conversion->document);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Only '],
        ['type' => 'text', 'text' => '£3', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => ' today.'],
    ]]]));
});

it('keeps a plugin’s output that holds an image as an Imported content card, even inside a line', function (): void {
    $conversion = WordPressContent::convert(
        [WordPressContent::classic("<p>Awarded [badge]</p>\n")],
        item: new ConversionItem(12, [['open' => '[badge]', 'html' => '<img src="https://old.example/wp-content/uploads/badge.png" alt="Gold" class="wp-image-90">']]),
        attachments: [90 => '01K5Z9P2QH7N3X4C8V6B0M1M90'],
        shortcodes: ['badge'],
    );
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::p('Awarded'),
        WordPressContent::card([icEl('p', [icEl('img', [], ['mediaId' => '01K5Z9P2QH7N3X4C8V6B0M1M90', 'alt' => 'Gold'])])], 'shortcode', 'badge'),
    ])->and(array_column(WordPressContent::said($conversion), 0))->toBe(['shortcode.imported']);
});

it('reads 20,000 opening tags that nothing closes in under a second', function (): void {
    $started = hrtime(true);
    $conversion = WordPressContent::convert([WordPressContent::classic('<p>'.str_repeat('[a]', 20000)."</p>\n")]);

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
        ->and($conversion->document['content'][0]['content'][0]['text'])->toBe(str_repeat('[a]', 20000))
        ->and($conversion->messages)->toBe([]);
});

it('reads shortcodes nested 5,000 deep in under a second, unwrapping 64 levels and keeping the rest as text', function (): void {
    $started = hrtime(true);
    $conversion = WordPressContent::convert([WordPressContent::classic('<p>'.str_repeat('[a]', 5000).'x'.str_repeat('[/a]', 5000)."</p>\n")]);

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
        ->and($conversion->document['content'][0]['content'][0]['text'])->toBe(str_repeat('[a]', 4936).'x'.str_repeat('[/a]', 4936))
        ->and(WordPressContent::said($conversion))->toBe([
            ['shortcode.unwrapped', 'Kept the words inside 64 [a] shortcodes without them: its plugin or theme no longer runs on the old site.', ['name' => 'a', 'count' => 64]],
        ]);
});
