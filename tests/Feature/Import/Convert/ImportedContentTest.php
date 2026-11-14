<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentSchema;
use App\Domain\Import\Convert\KnownTargets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Convert\WordPressContent;

uses(RefreshDatabase::class);

/*
 * Real content that doesn't convert (I-329): the Imported content card,
 * cleaned, its links and pictures resolved as an article's are; and the
 * file, playlist, formula and missing blocks mapped onto what Teme has.
 */

beforeEach(function (): void {
    $this->article = '01k5z9p2qh7n3x4c8v6b0m1a01';
    $this->map = '01K5Z9P2QH7N3X4C8V6B0M1M70';
    $this->report = '01K5Z9P2QH7N3X4C8V6B0M1F80';
    $this->tide = '01K5Z9P2QH7N3X4C8V6B0M1A01';
    $this->harbour = '01K5Z9P2QH7N3X4C8V6B0M1V01';
    $this->targets = new KnownTargets(pages: ['/ferries' => ['kind' => 'content', 'id' => $this->article]]);
});

it('keeps an HTML block holding a table as an Imported content card, cleaned, its links by ID and its pictures from the library', function (): void {
    $conversion = WordPressContent::convert([wpBlock('core/html', '<div class="hours"><table><tr><th scope="row">Monday</th><td><a href="/ferries/" style="color:red">Times</a></td></tr></table><p><img src="https://old.example/wp-content/uploads/map.png" alt="Map" class="wp-image-70"></p><script>x()</script></div>')], $this->targets, attachments: [70 => $this->map]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([WordPressContent::card([
        icEl('table', [icEl('tbody', [icEl('tr', [
            icEl('th', [icText('Monday')], ['scope' => 'row']),
            icEl('td', [icEl('a', [icText('Times')], ['href' => DocumentSchema::linkTo('content', $this->article)])]),
        ])])]),
        icEl('p', [icEl('img', [], ['mediaId' => $this->map, 'alt' => 'Map'])]),
    ], 'html-block')])->and(WordPressContent::said($conversion))->toBe([
        ['block.imported', 'Kept 1 core/html block as imported content: Teme can’t turn it into its own blocks.', ['name' => 'core/html', 'count' => 1]],
        ['imported.removed', 'Left out 1 script element from imported content: the card doesn’t keep it.', ['element' => 'script', 'count' => 1]],
        ['imported.styles', 'Left out the inline styles of 1 element in imported content: Teme’s theme styles it.', ['count' => 1]],
    ]);
});

it('keeps a known player’s frame in an HTML block as an Embed card after the card', function (): void {
    $conversion = WordPressContent::convert([wpBlock('core/html', '<p>Watch this:</p><iframe src="https://www.youtube.com/embed/abc123" width="560"></iframe>')]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::card([icEl('p', [icText('Watch this:')])], 'html-block'),
        WordPressContent::embed('https://www.youtube.com/watch?v=abc123', 'video', 'youtube'),
    ]);
});

it('keeps nothing of an HTML block that held only a script or spacing, and says what it left out', function (): void {
    $conversion = WordPressContent::convert([wpBlock('core/html', '<script>track()</script>'), wpBlock('core/html', '<div>&nbsp;</div>')]);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [['type' => 'paragraph']]))
        ->and(WordPressContent::said($conversion))->toBe([
            ['imported.removed', 'Left out 1 script element from imported content: the card doesn’t keep it.', ['element' => 'script', 'count' => 1]],
        ]);
});

it('keeps a block no row maps that has words of its own as an Imported content card, its inner blocks in their places', function (): void {
    $conversion = WordPressContent::convert([
        wpBlock('acme/notice', '<div class="notice"><p>Careful</p></div>'),
        wpBlock('acme/tabs', '<div class="tabs"><h3>Tab A</h3>%s</div>', [], [wpBlock('core/paragraph', '<p>Inside</p>')]),
    ]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::card([icEl('p', [icText('Careful')])], 'block', 'acme/notice'),
        WordPressContent::card([icEl('h3', [icText('Tab A')]), icEl('p', [icText('Inside')])], 'block', 'acme/tabs'),
    ])->and(array_map(fn (array $said): array => [$said[0], $said[2]], WordPressContent::said($conversion)))->toBe([
        ['block.imported', ['name' => 'acme/notice', 'count' => 1]],
        ['block.imported', ['name' => 'acme/tabs', 'count' => 1]],
    ]);
});

it('converts imported content too large for a card into Teme’s own blocks, and says so', function (): void {
    $items = str_repeat('<li>Stop</li>', 1001);

    $conversion = WordPressContent::convert([wpBlock('core/html', "<ul>{$items}</ul>")]);
    wpAccepted($conversion->document);

    expect($conversion->document['content'])->toHaveCount(1)
        ->and($conversion->document['content'][0]['type'])->toBe('bulletList')
        ->and($conversion->document['content'][0]['content'])->toHaveCount(1001)
        ->and(WordPressContent::said($conversion))->toBe([
            ['imported.too-large', 'Converted 1 piece of imported content from core/html into Teme’s own blocks: it was too large for an Imported content card.', ['name' => 'core/html', 'count' => 1]],
        ]);
});

it('keeps an Imported content card’s links by ID and as paths for the second pass, and links an image from another site', function (): void {
    $conversion = WordPressContent::convert([wpBlock('core/html', '<dl><dt><a href="https://old.example/ferries/">Ferries</a></dt><dd><a href="/later-story/">Later</a> <img src="https://photos.friend.example/cat.jpg" alt="A cat"></dd></dl>')], $this->targets);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([WordPressContent::card([icEl('dl', [
        icEl('dt', [icEl('a', [icText('Ferries')], ['href' => DocumentSchema::linkTo('content', $this->article)])]),
        icEl('dd', [
            icEl('a', [icText('Later')], ['href' => '/later-story/']),
            icText(' '),
            icEl('a', [icText('A cat')], ['href' => 'https://photos.friend.example/cat.jpg']),
        ]),
    ])], 'html-block')])->and($conversion->pendingLinks)->toBe(['/later-story/'])
        ->and(array_column(WordPressContent::said($conversion), 0))->toBe(['image.outside', 'block.imported']);
});

it('converts a File block to a paragraph linking to the file by ID, its name as the words', function (): void {
    $file = fn (string $href, string $name, array $attrs = []): array => wpBlock('core/file', "<div class=\"wp-block-file\"><a id=\"wp-block-file--media-1\" href=\"{$href}\">{$name}</a><a href=\"{$href}\" class=\"wp-block-file__button wp-element-button\" download aria-describedby=\"wp-block-file--media-1\">Download</a></div>", $attrs);

    $conversion = WordPressContent::convert([
        $file('https://old.example/wp-content/uploads/2024/05/report.pdf', 'Annual report', ['id' => 80, 'href' => 'https://old.example/wp-content/uploads/2024/05/report.pdf', 'displayPreview' => true]),
        $file('https://old.example/wp-content/uploads/2023/notes.pdf', ''),
        $file('https://files.other.example/guide.pdf', 'The guide'),
    ], attachments: [80 => $this->report]);
    wpAccepted($conversion->document);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [
        WordPressContent::linked('Annual report', DocumentSchema::linkTo('media', $this->report)),
        WordPressContent::p('notes.pdf'),
        WordPressContent::linked('The guide', 'https://files.other.example/guide.pdf'),
    ]))->and(WordPressContent::said($conversion))->toBe([
        ['file.missing', 'Kept the words of 1 link to a file that isn’t in Teme’s library, without the link: notes.pdf.', ['count' => 1, 'files' => ['notes.pdf']]],
    ]);
});

it('converts a playlist block to one card per file, in order', function (): void {
    $conversion = WordPressContent::convert([wpBlock('core/playlist', '<figure class="wp-block-playlist">%s</figure>', ['type' => 'audio'], [
        wpBlock('core/playlist-track', '', ['id' => 50, 'src' => 'https://old.example/wp-content/uploads/tide.mp3']),
        wpBlock('core/playlist-track', '', ['id' => 60, 'type' => 'video', 'src' => 'https://old.example/wp-content/uploads/harbour.mp4']),
        wpBlock('core/playlist-track', '', ['src' => 'https://old.example/wp-content/uploads/gone.mp3']),
    ])], attachments: [50 => $this->tide, 60 => $this->harbour]);
    wpAccepted($conversion->document);

    expect(array_column($conversion->document['content'], 'type'))->toBe(['audio', 'video'])
        ->and(WordPressContent::said($conversion))->toBe([
            ['media.missing', 'Left out 1 sound or video that isn’t in Teme’s library: gone.mp3.', ['count' => 1, 'files' => ['gone.mp3']]],
        ]);
});

it('keeps a formula as its LaTeX source in a code block', function (): void {
    $conversion = WordPressContent::convert([wpBlock('core/math', '<math display="block"><semantics><mi>e</mi></semantics></math>', ['latex' => 'e^{i\pi} + 1 = 0'])]);
    wpAccepted($conversion->document);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [['type' => 'codeBlock', 'attrs' => ['language' => 'latex'], 'content' => [['type' => 'text', 'text' => 'e^{i\pi} + 1 = 0']]]]))
        ->and(WordPressContent::said($conversion))->toBe([
            ['block.formula', 'Kept 1 formula as its LaTeX source in a code block: Teme can’t show formulas yet.', ['count' => 1]],
        ]);
});

it('converts a missing block as the block it was', function (): void {
    $conversion = WordPressContent::convert([wpBlock('core/missing', '<p>Lost and found</p>', ['originalName' => 'core/paragraph', 'originalContent' => '<p>Lost and found</p>'])]);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [WordPressContent::p('Lost and found')]))
        ->and($conversion->messages)->toBe([]);
});

it('makes cards, files and formulas the server keeps and the editor’s own schema accepts', function (): void {
    $documents = array_map(fn (array $blocks): array => WordPressContent::convert($blocks, $this->targets, attachments: [70 => $this->map, 80 => $this->report])->document, [
        'cards' => [wpBlock('core/html', '<table><tr><td><a href="/ferries/">One</a></td></tr></table>'), wpBlock('acme/notice', '<div><p>Careful</p></div>')],
        'file and formula' => [wpBlock('core/file', '<div class="wp-block-file"><a href="https://old.example/wp-content/uploads/r.pdf">Report</a></div>', ['id' => 80]), wpBlock('core/math', '', ['latex' => 'x^2'])],
    ]);

    foreach ($documents as $document) {
        wpAccepted($document);
    }

    expect(wpEditorCheck($documents))->toBe(array_fill_keys(array_keys($documents), null));
});
