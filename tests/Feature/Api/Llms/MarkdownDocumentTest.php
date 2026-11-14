<?php

declare(strict_types=1);

use App\Domain\Api\Llms\MarkdownDocument;
use App\Domain\Cards\CardRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Api\DeliverySite;

uses(RefreshDatabase::class);

/**
 * A document of these top-level nodes.
 *
 * @param  list<array<string, mixed>>  $nodes
 *
 * @return array<string, mixed>
 */
function mdDoc(array $nodes): array
{
    return ['type' => 'doc', 'content' => $nodes];
}

/**
 * A paragraph of text nodes, each with its marks.
 *
 * @param  list<array<string, mixed>|string>  $runs
 *
 * @return array<string, mixed>
 */
function mdParagraph(array $runs): array
{
    return ['type' => 'paragraph', 'content' => array_map(fn (array|string $run): array => is_string($run) ? ['type' => 'text', 'text' => $run] : $run, $runs)];
}

/**
 * A text node with marks.
 *
 * @param  list<array<string, mixed>>  $marks
 *
 * @return array<string, mixed>
 */
function mdText(string $text, array $marks): array
{
    return ['type' => 'text', 'text' => $text, 'marks' => $marks];
}

dataset('markdown blocks', [
    'a heading' => [[['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Fares']]]], '## Fares'],
    'a paragraph' => [[mdParagraph(['Plain words.'])], 'Plain words.'],
    'two paragraphs' => [[mdParagraph(['One.']), mdParagraph(['Two.'])], "One.\n\nTwo."],
    'a bullet list' => [[['type' => 'bulletList', 'content' => [
        ['type' => 'listItem', 'content' => [mdParagraph(['First'])]],
        ['type' => 'listItem', 'content' => [mdParagraph(['Second'])]],
    ]]], "- First\n- Second"],
    'an ordered list' => [[['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [
        ['type' => 'listItem', 'content' => [mdParagraph(['Third'])]],
        ['type' => 'listItem', 'content' => [mdParagraph(['Fourth'])]],
    ]]], "3. Third\n4. Fourth"],
    'a task list' => [[['type' => 'taskList', 'content' => [
        ['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [mdParagraph(['Done'])]],
        ['type' => 'taskItem', 'attrs' => ['checked' => false], 'content' => [mdParagraph(['To do'])]],
    ]]], "- [x] Done\n- [ ] To do"],
    'a list inside a list' => [[['type' => 'bulletList', 'content' => [
        ['type' => 'listItem', 'content' => [mdParagraph(['Outer']), ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [mdParagraph(['Inner'])]],
        ]]]],
    ]]], "- Outer\n  - Inner"],
    'a quote' => [[['type' => 'blockquote', 'content' => [mdParagraph(['Said it.']), mdParagraph(['Twice.'])]]], "> Said it.\n>\n> Twice."],
    'a code block' => [[['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => "echo 1;\n"]]]], "```php\necho 1;\n```"],
    'a code block holding a fence' => [[['type' => 'codeBlock', 'attrs' => ['language' => null], 'content' => [['type' => 'text', 'text' => "```\nx\n```"]]]], "````\n```\nx\n```\n````"],
    'a rule' => [[['type' => 'horizontalRule']], '---'],
    'a table' => [[['type' => 'table', 'content' => [
        ['type' => 'tableRow', 'content' => [
            ['type' => 'tableHeader', 'content' => [mdParagraph(['Route'])]],
            ['type' => 'tableHeader', 'content' => [mdParagraph(['Fare'])]],
        ]],
        ['type' => 'tableRow', 'content' => [
            ['type' => 'tableCell', 'content' => [mdParagraph(['North | south'])]],
            ['type' => 'tableCell', 'content' => [mdParagraph(['£4'])]],
        ]],
    ]]], "| Route | Fare |\n| --- | --- |\n| North \\| south | £4 |"],
]);

it('writes headings, paragraphs, lists, task lists, quotes, code, rules and tables', function (array $nodes, string $expected): void {
    expect(MarkdownDocument::of(mdDoc($nodes)))->toBe($expected);
})->with('markdown blocks');

it('writes bold, italic, strike and code marks, and drops the others', function (): void {
    $document = mdDoc([mdParagraph([
        mdText('bold', [['type' => 'bold']]), ' ',
        mdText('italic', [['type' => 'italic']]), ' ',
        mdText('struck', [['type' => 'strike']]), ' ',
        mdText('code', [['type' => 'code']]), ' ',
        mdText('both', [['type' => 'bold'], ['type' => 'italic']]), ' ',
        mdText('plain', [['type' => 'underline'], ['type' => 'highlight']]),
    ])]);

    expect(MarkdownDocument::of($document))->toBe('**bold** *italic* ~~struck~~ `code` ***both*** plain');
});

it('writes a link by ID at its address now, and its text alone when the target has none', function (): void {
    $site = DeliverySite::build();
    $link = fn (string $text, string $href): array => mdText($text, [['type' => 'link', 'attrs' => ['href' => $href]]]);
    $document = mdDoc([mdParagraph([
        $link('The sequel', 'teme:content/'.$site->sequel->id), ' and ',
        $link('the unfinished cut', 'teme:content/'.$site->draft->id), ' and ',
        $link('elsewhere', 'https://example.org/page'),
    ])]);

    $path = (string) DB::table('paths')->where('target_id', $site->sequel->id)->where('is_current', true)->value('path');

    expect(MarkdownDocument::of($document))->toBe('[The sequel](http://localhost/'.$path.'/) and the unfinished cut and [elsewhere](https://example.org/page)');
});

it('writes an image card as an image at its widest size with its alt text', function (): void {
    $site = DeliverySite::build();
    $card = ['type' => 'image', 'attrs' => ['id' => '01KA0DE1S1TE0000000000IM01', 'mediaId' => $site->shell->id, 'alt' => 'The shell [over] the field', 'decorative' => false]];

    $written = MarkdownDocument::of(mdDoc([$card]));

    expect($written)->toStartWith('![The shell \[over\] the field](http://localhost/')
        ->toEndWith(')')
        ->not->toContain('<')
        ->not->toContain('teme:');
});

it('writes any other card as its plain text, and nothing for an empty one', function (): void {
    $cards = app(CardRegistry::class)->all();

    expect($cards)->not->toBeEmpty();

    foreach ($cards as $card) {
        if ($card->name() === 'image') {
            continue;
        }

        $written = MarkdownDocument::of(mdDoc([['type' => $card->name(), 'attrs' => []]]));

        expect($written)->not->toContain('<', $card->name())
            ->not->toContain('teme:', $card->name());

        if (trim($card->plainText([])) === '') {
            expect($written)->toBe('', $card->name());
        }
    }

    $button = ['type' => 'button', 'attrs' => ['id' => '01KA0DE1S1TE0000000000BT01', 'label' => 'Read more', 'href' => 'https://example.org/more']];

    expect(MarkdownDocument::of(mdDoc([$button])))->toBe(trim(app(CardRegistry::class)->find('button')?->plainText($button['attrs']) ?? 'x'));
});

it('escapes text that would read as Markdown', function (): void {
    $document = mdDoc([
        mdParagraph(['*not bold* and `not code` and [not a link](x)']),
        mdParagraph(['# not a heading']),
        mdParagraph(['1. not a list']),
        mdParagraph(['- not a bullet']),
        mdParagraph(['<b>not html</b>']),
    ]);

    expect(MarkdownDocument::of($document))->toBe(implode("\n\n", [
        '\*not bold\* and \`not code\` and \[not a link\](x)',
        '\# not a heading',
        '1\. not a list',
        '\- not a bullet',
        '\<b\>not html\</b\>',
    ]));
});

it('writes no HTML', function (): void {
    $document = mdDoc([
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => '<script>x</script>']]],
        mdParagraph([mdText('<img src=x>', [['type' => 'bold']])]),
    ]);

    expect(MarkdownDocument::of($document))->not->toMatch('/(?<!\\\\)<[a-z\/]/i');
});

it('writes nothing for an empty document or a node it does not know', function (): void {
    expect(MarkdownDocument::of(mdDoc([])))->toBe('')
        ->and(MarkdownDocument::of(mdDoc([['type' => 'nonsense']])))->toBe('');
});
