<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentSchema;
use App\Domain\Import\Convert\Conversion;
use App\Domain\Import\Convert\ConversionItem;
use App\Domain\Import\Convert\KnownTargets;
use App\Domain\Import\Phases\ImportMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Convert\WordPressContent;

uses(RefreshDatabase::class);

/*
 * Classic content (I-336): an address alone on its line as an embed,
 * through WordPress's own provider list, and a whole classic post with its
 * shortcodes, links and media as Teme stores it.
 */

beforeEach(function (): void {
    $this->ferries = '01k5z9p2qh7n3x4c8v6b0m1a01';
    $this->post = function (): Conversion {
        $post = json_decode((string) file_get_contents(base_path('tests/Fixtures/Convert/classic.json')), true);

        return WordPressContent::convert(
            $post['blocks'],
            new KnownTargets(pages: [
                '/ferries' => ['kind' => 'content', 'id' => $this->ferries],
                '/category/boats' => ['kind' => 'term', 'id' => '01k5z9p2qh7n3x4c8v6b0m1t01'],
            ]),
            new ConversionItem($post['id'], $post['shortcodes']),
            attachments: [41 => '01K5Z9P2QH7N3X4C8V6B0M1M41', 1 => '01K5Z9P2QH7N3X4C8V6B0M1M01', 2 => '01K5Z9P2QH7N3X4C8V6B0M1M02'],
            shortcodes: $post['registered'],
        );
    };
});

it('turns an address alone on its line into an Embed card when WordPress’s provider list knows it, and leaves others as text', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic(
        "<p>Look:<br />\nhttps://www.youtube.com/watch?v=abc123<br />\nNice.</p>\n<p>https://twitter.com/ada/status/1</p>\n<p>https://unknown.example/page</p>\n",
    )]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::p('Look:'),
        WordPressContent::embed('https://www.youtube.com/watch?v=abc123', 'video', 'youtube'),
        WordPressContent::p('Nice.'),
        WordPressContent::embed('https://twitter.com/ada/status/1', 'link', null),
        WordPressContent::p('https://unknown.example/page'),
    ]);
});

it('turns only a whole paragraph’s address into an embed in a paragraph block, as WordPress does', function (): void {
    $conversion = WordPressContent::convert([
        wpBlock('core/paragraph', '<p>https://vimeo.com/76979871</p>'),
        wpBlock('core/paragraph', '<p>See<br>https://vimeo.com/1</p>'),
    ]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::embed('https://vimeo.com/76979871', 'video', 'vimeo'),
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'See'], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'https://vimeo.com/1']]],
    ]);
});

it('leaves an address inside a link alone', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic('<p><a href="https://www.youtube.com/watch?v=abc123">https://www.youtube.com/watch?v=abc123</a></p>')]);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [WordPressContent::linked('https://www.youtube.com/watch?v=abc123', 'https://www.youtube.com/watch?v=abc123')]));
});

it('matches an address alone on its line by the provider at its start only', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic("<p>https://old.example/a.tumblr.com/</p>\n<p>https://old.example/share?u=https://youtu.be/x</p>\n")]);
    wpAccepted($conversion->document);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [
        WordPressContent::p('https://old.example/a.tumblr.com/'),
        WordPressContent::p('https://old.example/share?u=https://youtu.be/x'),
    ]))->and($conversion->messages)->toBe([]);
});

it('converts a whole classic post, as the companion sends it, into a document Teme stores', function (): void {
    $conversion = ($this->post)();
    $content = $conversion->document['content'];
    wpAccepted($conversion->document);

    expect(array_column($content, 'type'))->toBe(['heading', 'paragraph', 'image', 'gallery', 'embed', 'paragraph', 'paragraph', 'imported', 'paragraph'])
        ->and($content[1]['content'][3]['marks'][0]['attrs']['href'])->toBe(DocumentSchema::linkTo('content', $this->ferries))
        ->and($content[2]['attrs']['caption'])->toBe('The harbour at six')
        ->and(wpWithoutIds($content[5]))->toBe(wpWithoutIds(WordPressContent::p('Tickets are sold at the kiosk.')))
        ->and(wpWithoutIds($content[6]))->toBe(wpWithoutIds(WordPressContent::p('Fares, as the [your name] team keeps them:')))
        ->and($content[7]['attrs']['sourceName'])->toBe('table')
        ->and($conversion->pendingLinks)->toBe(['/?p=900'])
        ->and(wpEditorCheck(['post' => $conversion->document]))->toBe(['post' => null]);
});

it('says what it left out or kept by name, never in the post’s own words', function (): void {
    $messages = ($this->post)()->messages;
    $said = strtolower(implode(' ', array_map(fn (ImportMessage $message): string => $message->message.' '.json_encode($message->context), $messages)));

    expect(array_map(fn (ImportMessage $message): array => [$message->code, $message->context['name'] ?? null], $messages))->toBe([
        ['shortcode.unwrapped', 'vc_row'],
        ['shortcode.unwrapped', 'vc_column'],
        ['shortcode.unwrapped', 'vc_column_text'],
        ['shortcode.removed', 'td_block_ad_box'],
        ['shortcode.imported', 'table'],
    ]);

    foreach (['harbour', 'ferries', 'tickets', 'kiosk', 'fares', 'questions', 'dawn', 'ada'] as $words) {
        expect(str_contains($said, $words))->toBeFalse("A message holds the post's words: {$words}");
    }
});

it('reads no table and calls no network', function (): void {
    Http::preventStrayRequests();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    ($this->post)();

    expect($queries)->toBe(0);
});
