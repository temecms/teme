<?php

declare(strict_types=1);

use App\Domain\Comments\CommentDocument;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Phases\Comments\CommentWords;
use App\Domain\Import\Phases\ImportMessage;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->item = cmItem(User::factory()->create()->assignRole('administrator'));
});

/**
 * A comment's words as the import keeps them, from the old site at old.example.
 *
 * @return array{document: array<string, mixed>, message: ImportMessage|null}
 */
function cwRead(string $content): array
{
    return app(CommentWords::class)->read($content, new ConversionSite('https://old.example'));
}

/**
 * One text node, as the document holds it.
 *
 * @param  list<array<string, mixed>>  $marks
 *
 * @return array<string, mixed>
 */
function cwText(string $text, array $marks = []): array
{
    return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
}

/**
 * A link mark to this address.
 *
 * @return list<array<string, mixed>>
 */
function cwLink(string $href): array
{
    return [['type' => 'link', 'attrs' => ['href' => $href]]];
}

/**
 * @return array<string, mixed>
 */
function cwParagraph(string $text): array
{
    return ['type' => 'paragraph', 'content' => [cwText($text)]];
}

it('sets paragraphs and line breaks as WordPress shows a comment', function (): void {
    $read = cwRead("First line\nsecond line\n\n\nA new paragraph.\r\n\r\nFish &amp; chips");

    expect($read['document'])->toBe(['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [cwText('First line'), ['type' => 'hardBreak'], cwText('second line')]],
        cwParagraph('A new paragraph.'),
        cwParagraph('Fish & chips'),
    ]])->and($read['message'])->toBeNull();
});

it('keeps bold, italic and code, and the words of formatting a comment doesn’t hold', function (): void {
    $read = cwRead('<strong>Bold</strong> and <em>soft</em> with <code>x = 1</code> and <u>under</u> <del>gone</del>');

    expect($read['document']['content'][0]['content'])->toBe([
        cwText('Bold', [['type' => 'bold']]),
        cwText(' and '),
        cwText('soft', [['type' => 'italic']]),
        cwText(' with '),
        cwText('x = 1', [['type' => 'code']]),
        cwText(' and under gone'),
    ])->and($read['message']?->message)->toBe('Kept its words, but left out some formatting: a comment on Teme holds paragraphs, quotes, bold, italic, code and links.');
});

it('keeps quotes three deep, and a deeper quote’s words in their place', function (): void {
    $read = cwRead('<blockquote>One<blockquote>Two<blockquote>Three<blockquote>Four</blockquote></blockquote></blockquote></blockquote>Reply');

    expect($read['document']['content'])->toBe([
        ['type' => 'blockquote', 'content' => [
            cwParagraph('One'),
            ['type' => 'blockquote', 'content' => [
                cwParagraph('Two'),
                ['type' => 'blockquote', 'content' => [cwParagraph('Three'), cwParagraph('Four')]],
            ]],
        ]],
        cwParagraph('Reply'),
    ])->and($read['message'])->toBeNull();
});

it('keeps a link to another site, and one to the old site’s own item by its ID', function (): void {
    $read = cwRead('See <a href="https://elsewhere.example/a?b=1">this</a>, <a href="https://www.old.example/harbour-news/">our story</a>, <a href="/harbour-news/">again</a> and <a href="mailto:ada@example.com">mail</a>.');

    expect($read['document']['content'][0]['content'])->toBe([
        cwText('See '),
        cwText('this', cwLink('https://elsewhere.example/a?b=1')),
        cwText(', '),
        cwText('our story', cwLink("teme:content/{$this->item->id}")),
        cwText(', '),
        cwText('again', cwLink("teme:content/{$this->item->id}")),
        cwText(' and '),
        cwText('mail', cwLink('mailto:ada@example.com')),
        cwText('.'),
    ])->and($read['message'])->toBeNull();
});

it('keeps the words of a link it can’t keep, saying how many', function (): void {
    $read = cwRead('<a href="https://old.example/category/news/">News</a>, <a href="javascript:alert(1)">this</a> and <a href="#reply">that</a>');

    expect($read['document']['content'][0]['content'])->toBe([cwText('News, this and that')])
        ->and($read['message']?->code)->toBe('comment.words-changed')
        ->and($read['message']?->message)->toBe('Kept its words, but left out the addresses of 3 links: a comment on Teme holds paragraphs, quotes, bold, italic, code and links.')
        ->and($read['message']?->context)->toBe(['links' => 3, 'images' => 0, 'elements' => 0, 'formatting' => false]);
});

it('links the web addresses in its words, as WordPress shows them', function (): void {
    $read = cwRead('Try https://elsewhere.example/page. Or www.example.org, and https://old.example/harbour-news/!');

    expect($read['document']['content'][0]['content'])->toBe([
        cwText('Try '),
        cwText('https://elsewhere.example/page', cwLink('https://elsewhere.example/page')),
        cwText('. Or '),
        cwText('www.example.org', cwLink('https://www.example.org')),
        cwText(', and '),
        cwText('https://old.example/harbour-news/', cwLink("teme:content/{$this->item->id}")),
        cwText('!'),
    ]);
});

it('leaves out images and elements a comment never holds, saying how many', function (): void {
    $read = cwRead('Look <img src="https://old.example/wp-content/uploads/a.jpg" alt="A"> here <img class="wp-smiley" src="x.png" alt=":)"><script>alert(1)</script><iframe src="https://video.example/1"></iframe>');

    expect($read['document']['content'])->toBe([cwParagraph('Look here :)')])
        ->and($read['message']?->message)->toBe('Kept its words, but left out 1 image, 1 script element and 1 iframe element: a comment on Teme holds paragraphs, quotes, bold, italic, code and links.')
        ->and($read['message']?->context)->toBe(['links' => 0, 'images' => 1, 'elements' => 2, 'formatting' => false]);
});

it('keeps headings, lists and preformatted text as paragraphs', function (): void {
    $read = cwRead("<h2>Title</h2>\n<ul><li>One</li><li>Two</li></ul>\n<pre>line one\n\nline three</pre>");

    expect($read['document']['content'])->toBe([
        cwParagraph('Title'),
        cwParagraph('One'),
        cwParagraph('Two'),
        ['type' => 'paragraph', 'content' => [cwText('line one'), ['type' => 'hardBreak'], ['type' => 'hardBreak'], cwText('line three')]],
    ])->and($read['message'])->toBeNull();
});

it('makes one empty paragraph of a comment with no words', function (): void {
    expect(cwRead("  \n\n ")['document'])->toBe(['type' => 'doc', 'content' => [['type' => 'paragraph']]]);
});

it('gives only documents a comment may hold', function (string $content): void {
    expect(fn () => CommentDocument::validate(cwRead($content)['document']))->not->toThrow(Throwable::class);
})->with([
    'plain words' => ["One\ntwo\n\nthree"],
    'formatting' => ['<b>a</b> <i>b</i> <code>c</code> <s>d</s> <sup>e</sup>'],
    'quotes in quotes' => ['<blockquote><blockquote><blockquote><blockquote><blockquote>deep</blockquote></blockquote></blockquote></blockquote></blockquote>'],
    'links of every kind' => ['<a href="tel:123">call</a> <a href="https://x.example">x</a> www.y.example <a href="/nowhere/">here</a>'],
    'a table' => ['<table><tr><td>a</td><td>b</td></tr></table>'],
    'unclosed tags' => ['<strong>bold <em>both</strong> after'],
    'nothing' => [''],
]);
