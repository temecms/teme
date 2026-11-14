<?php

declare(strict_types=1);

use App\Domain\Comments\CommentText;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('makes paragraphs from blank lines and line breaks from single ones, as the reader typed them', function (): void {
    expect(CommentText::toDocument("  First line\r\nsecond line\n\n  \nNew paragraph\n"))->toBe([
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'First line'],
                ['type' => 'hardBreak'],
                ['type' => 'text', 'text' => 'second line'],
            ]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'New paragraph']]],
        ],
    ]);
});

it('links web addresses, and leaves the punctuation after them outside the link', function (): void {
    expect(CommentText::toDocument('See https://example.org/tides?day=2, or www.example.net.')['content'][0]['content'])->toBe([
        ['type' => 'text', 'text' => 'See '],
        ['type' => 'text', 'text' => 'https://example.org/tides?day=2', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.org/tides?day=2']]]],
        ['type' => 'text', 'text' => ', or '],
        ['type' => 'text', 'text' => 'www.example.net', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://www.example.net']]]],
        ['type' => 'text', 'text' => '.'],
    ]);
});

it('keeps a bracket the address opened, and leaves out one it didn\'t', function (): void {
    expect(CommentText::toDocument('Tides (see https://en.wikipedia.org/wiki/Tide_(disambiguation)).')['content'][0]['content'])->toBe([
        ['type' => 'text', 'text' => 'Tides (see '],
        ['type' => 'text', 'text' => 'https://en.wikipedia.org/wiki/Tide_(disambiguation)', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://en.wikipedia.org/wiki/Tide_(disambiguation)']]]],
        ['type' => 'text', 'text' => ').'],
    ]);
});

it('links an address on this site to the item it shows, by its ID (rule 9)', function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'https://harbour.example']);
    $story = addrPublished(User::factory()->create()->assignRole('author'), 'Harbour festival');

    expect(CommentText::toDocument('Read https://Harbour.example/harbour-festival/?utm_source=mail first')['content'][0]['content'][1])
        ->toBe(['type' => 'text', 'text' => 'https://Harbour.example/harbour-festival/?utm_source=mail', 'marks' => [['type' => 'link', 'attrs' => ['href' => "teme:content/{$story->id}"]]]]);
});

it('leaves an address on this site that shows no item unlinked, so the site\'s own address is never stored (rule 9)', function (): void {
    config(['app.url' => 'https://harbour.example']);

    expect(CommentText::toDocument('Try https://harbour.example/category/news/')['content'][0]['content'])
        ->toBe([['type' => 'text', 'text' => 'Try https://harbour.example/category/news/']]);
});

it('never links an address with another scheme', function (): void {
    expect(CommentText::toDocument('javascript:alert(1) or ftp://example.org/x, not ada@www.example.org')['content'][0]['content'])
        ->toBe([['type' => 'text', 'text' => 'javascript:alert(1) or ftp://example.org/x, not ada@www.example.org']]);
});

it('reads a document back as the reader wrote it, and counts its links', function (): void {
    $document = CommentText::toDocument("Two links: https://a.example and https://b.example\nand a break\n\nDone.");

    expect(CommentText::plain($document))->toBe("Two links: https://a.example and https://b.example\nand a break\n\nDone.")
        ->and(CommentText::links($document))->toBe(2)
        ->and(CommentText::toDocument('   '))->toBe(['type' => 'doc', 'content' => [['type' => 'paragraph']]]);
});
