<?php

declare(strict_types=1);

use App\Domain\Cards\BlockIds;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Import\Convert\Conversion;
use App\Domain\Import\Convert\PendingReference;
use App\Domain\Import\Phases\ImportMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * The converter's promises (I-452): what it makes is stored as it
 * is, the editor's own schema accepts it, it says what it left out without
 * the article's words, and it reads no table and calls no network.
 */

beforeEach(function (): void {
    $this->harbour = (string) Str::ulid();
    $this->article = fn (): Conversion => wpConvert(
        json_decode((string) file_get_contents(base_path('tests/Fixtures/Convert/article.json')), true)['blocks'],
        [41 => $this->harbour],
    );
});

it('converts a whole article, as the companion sends it, into a document Teme stores', function (): void {
    $conversion = ($this->article)();
    $content = $conversion->document['content'];

    wpAccepted($conversion->document);

    expect(array_column($content, 'type'))->toBe(['heading', 'paragraph', 'image', 'bulletList', 'blockquote', 'table', 'heading', 'paragraph', 'embed', 'imported', 'horizontalRule', 'paragraph'])
        ->and($content[1]['content'])->toBe([
            ['type' => 'text', 'text' => 'Boats leave the '],
            ['type' => 'text', 'text' => 'harbour', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' at dawn. '],
            ['type' => 'text', 'text' => 'See the ferries', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/ferries/']]]],
            ['type' => 'text', 'text' => '.'],
        ])
        ->and($content[2]['attrs']['mediaId'])->toBe($this->harbour)
        ->and($content[2]['attrs']['caption'])->toBe('The harbour at six')
        ->and(array_map(fn (PendingReference $reference): array => $reference->toArray(), $conversion->references))->toBe([['type' => 'post', 'id' => '77', 'at' => [9]]])
        ->and($conversion->pendingLinks)->toBe(['/ferries/'])
        ->and(wpEditorCheck(['article' => $conversion->document]))->toBe(['article' => null]);
});

it('gives every heading, paragraph, quote, list item and table it makes an id of its own, and every card keeps the one it made (I-3362)', function (): void {
    $found = [];
    $walk = function (array $node) use (&$walk, &$found): void {
        if (in_array($node['type'] ?? null, BlockIds::types(), true) || isset($node['attrs']['id'])) {
            $found[] = [$node['type'], $node['attrs']['id'] ?? null];
        }

        foreach ($node['content'] ?? [] as $child) {
            $walk($child);
        }
    };
    $walk(($this->article)()->document);
    $ids = array_column($found, 1);

    expect(array_values(array_unique(array_column($found, 0))))->toContain('heading', 'paragraph', 'blockquote', 'listItem', 'table', 'image', 'embed')
        ->and(array_filter($ids, fn (mixed $id): bool => ! is_string($id) || preg_match(DocumentSchema::NODE_ID, $id) !== 1))->toBe([])
        ->and(array_unique($ids))->toHaveCount(count($ids));
});

it('says what it left out by name, never in the article\'s own words', function (): void {
    $messages = ($this->article)()->messages;
    $said = strtolower(implode(' ', array_map(fn (ImportMessage $message): string => $message->message.' '.json_encode($message->context), $messages)));

    expect(array_map(fn (ImportMessage $message): array => [$message->code, $message->context['name'] ?? null], $messages))->toBe([
        ['block.contents-list', 'simpletoc/toc'],
        ['block.dynamic', 'core/latest-posts'],
        ['block.imported', 'core/html'],
    ]);

    foreach (['harbour', 'boats', 'ferries', 'crossing', 'kiosk', 'dog', 'open 9', 'thanks'] as $words) {
        expect(str_contains($said, $words))->toBeFalse("A message holds the article's words: {$words}");
    }
});

it('reads no table and calls no network', function (): void {
    Http::preventStrayRequests();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    ($this->article)();

    expect($queries)->toBe(0);
});

it('gives the same document for the same tree, but for new IDs', function (): void {
    [$first, $second] = [($this->article)(), ($this->article)()];

    expect(wpWithoutIds($first->document))->toBe(wpWithoutIds($second->document))
        ->and($first->document['content'][0]['attrs']['id'])->not->toBe($second->document['content'][0]['attrs']['id']);
});

it('converts a classic post, one freeform chunk, from the HTML wpautop made of it', function (): void {
    $conversion = wpConvert([['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "Hello\n\nWorld", 'innerContent' => ["Hello\n\nWorld"], 'autop' => "<p>Hello</p>\n<p>World</p>\n"]]);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'World']]],
    ]))->and($conversion->messages)->toBe([]);
});

it('makes only documents the editor\'s own schema accepts', function (): void {
    $media = [1 => (string) Str::ulid(), 2 => (string) Str::ulid()];
    $image = fn (int $id): array => wpBlock('core/image', "<figure class=\"wp-block-image\"><img src=\"https://old.example/wp-content/uploads/i{$id}.jpg\" class=\"wp-image-{$id}\"/></figure>", ['id' => $id]);

    $documents = array_map(fn (array $blocks): array => wpConvert($blocks, $media)->document, [
        'text' => [
            wpBlock('core/paragraph', '<p>Plain, <a href="https://e.example/"><code>linked code</code></a>, <b><code>bold code</code></b>, <sub>s</sub><sup>t</sup> and <mark>m</mark>.</p>'),
            wpBlock('core/heading', '<h6 id="x">Six</h6>', ['level' => 6]),
            wpBlock('core/verse', "<pre class=\"wp-block-verse\">One\n\nTwo</pre>"),
        ],
        'lists and quotes' => [
            wpBlock('core/list', '<ol start="0" type="i"><li><ul><li>Deep</li></ul></li><li><p>Para</p><blockquote>Q</blockquote></li></ol>'),
            wpBlock('core/quote', '<blockquote class="wp-block-quote"><cite>Only a citation</cite></blockquote>'),
        ],
        'table' => [wpBlock('core/table', '<figure class="wp-block-table"><table><tbody><tr><td><ul><li>In a cell</li></ul></td><td></td></tr></tbody></table></figure>')],
        'cards' => [
            $image(1),
            wpBlock('core/gallery', '<figure class="wp-block-gallery">%s</figure>', [], [$image(1), $image(2)]),
            wpBlock('core/embed', '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://vimeo.com/1</div></figure>', ['url' => 'https://vimeo.com/1']),
            wpBlock('core/buttons', '<div class="wp-block-buttons">%s</div>', [], [wpBlock('core/button', '<div class="wp-block-button"><a href="https://e.example/">Go</a></div>')]),
        ],
        'faq' => [wpBlock('rank-math/faq-block', '<div class="rank-math-list-item"><h2 class="rank-math-question">Q?</h2><div class="rank-math-answer">Just words</div></div>')],
        'nothing' => [wpBlock('core/latest-posts', '')],
    ]);

    foreach ($documents as $document) {
        wpAccepted($document);
    }

    expect(wpEditorCheck($documents))->toBe(array_fill_keys(array_keys($documents), null));
});
