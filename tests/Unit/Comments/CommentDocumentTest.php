<?php

declare(strict_types=1);

use App\Domain\Cards\Exceptions\InvalidDocumentException;
use App\Domain\Comments\CommentDocument;

it('accepts what a comment may hold: paragraphs, quotes, line breaks, bold, italic, code and links (I-267)', function (): void {
    $document = ['type' => 'doc', 'content' => [
        ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quoted']]]]],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Bold', 'marks' => [['type' => 'bold']]],
            ['type' => 'hardBreak'],
            ['type' => 'text', 'text' => 'code', 'marks' => [['type' => 'code'], ['type' => 'italic']]],
            ['type' => 'text', 'text' => 'an item', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r']]]],
            ['type' => 'text', 'text' => 'a site', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.org']]]],
        ]],
        ['type' => 'paragraph'],
    ]];

    // Throwable, not InvalidDocumentException: a valid document raises nothing at all (B-349).
    expect(fn () => CommentDocument::validate($document))->not->toThrow(Throwable::class);
});

it('refuses anything else, naming the part that failed', function (array $document, string $path): void {
    expect(fn () => CommentDocument::validate($document))->toThrow(function (InvalidDocumentException $refused) use ($path): void {
        expect($refused->path)->toBe($path);
    });
})->with([
    'a heading' => [['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Hi']]]]], 'content.0'],
    'a mark a comment doesn\'t take' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'highlight']]]]]]], 'content.0.content.0.marks.0'],
    'a link that opens a new window' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.org', 'target' => '_blank']]]]]]]], 'content.0.content.0.marks.0.attrs'],
    'a javascript link' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]]]], 'content.0.content.0.marks.0.attrs.href'],
    'quotes four deep' => [['type' => 'doc', 'content' => [['type' => 'blockquote', 'content' => [['type' => 'blockquote', 'content' => [['type' => 'blockquote', 'content' => [['type' => 'blockquote', 'content' => [['type' => 'paragraph']]]]]]]]]]], 'content.0.content.0.content.0.content.0'],
]);
