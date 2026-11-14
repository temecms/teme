<?php

declare(strict_types=1);

use App\Domain\Cards\CardManifest;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * I-453: a pasted image waits in the editor as a node of the editor's own,
 * run in Node by tests/Fixtures/pasted-images.mjs through the editor's real
 * schema and rules.
 */
beforeEach(function (): void {
    $this->run = fn (array $input): array => guardNode('pasted-images.mjs', [
        'manifest' => CardManifest::forBrowser(app(CardRegistry::class)),
        'images' => [],
        'figures' => [],
        'sources' => [],
        'documents' => [],
        ...$input,
    ]);
    $this->pasted = fn (string $src = 'https://photos.example/harbour.jpg'): array => ['type' => 'pastedImage', 'attrs' => ['src' => $src]];
    $this->words = fn (string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
});

it('reads a pasted image\'s address, alt text and caption as an Image card keeps them, and nothing else from the paste (I-453)', function (): void {
    $answer = ($this->run)([
        'images' => [
            ['src' => ' https://photos.example/harbour.jpg ', 'alt' => "  The harbour\n at dawn "],
            ['src' => '//photos.example/gulls.jpg', 'alt' => ''],
            ['src' => 'https://photos.example/x.jpg', 'alt' => str_repeat('a', 1200), 'state' => 'importing', 'message' => 'Planted'],
        ],
        'figures' => [['images' => [['src' => 'https://photos.example/net.jpg', 'alt' => 'Nets']], 'caption' => " Morning \n light "]],
    ]);

    expect($answer['rules'])->toBe([['tag' => 'figure', 'priority' => 60], ['tag' => 'img[src]', 'priority' => 50]])
        ->and($answer['images'][0])->toBe(['src' => 'https://photos.example/harbour.jpg', 'alt' => 'The harbour at dawn', 'caption' => null, 'state' => 'waiting', 'message' => null])
        ->and($answer['images'][1])->toBe(['src' => 'https://photos.example/gulls.jpg', 'alt' => null, 'caption' => null, 'state' => 'waiting', 'message' => null])
        ->and(mb_strlen((string) $answer['images'][2]['alt']))->toBe(1000)
        ->and([$answer['images'][2]['state'], $answer['images'][2]['message']])->toBe(['waiting', null])
        ->and($answer['figures'][0])->toBe(['src' => 'https://photos.example/net.jpg', 'alt' => 'Nets', 'caption' => 'Morning light', 'state' => 'waiting', 'message' => null]);
});

it('leaves a figure of several images, of none, or holding a table or a list beside its image, to the rules inside it', function (): void {
    $answer = ($this->run)(['figures' => [
        ['images' => [['src' => 'https://photos.example/a.jpg'], ['src' => 'https://photos.example/b.jpg']], 'caption' => 'A gallery'],
        ['images' => [], 'caption' => 'A table'],
        ['images' => [['src' => 'https://photos.example/tides.png']], 'caption' => 'Tides', 'holds' => ['table']],
        ['images' => [['src' => 'https://photos.example/step.png']], 'caption' => null, 'holds' => ['ol']],
    ]]);

    expect($answer['figures'])->toBe([false, false, false, false]);
});

it('says why an address can\'t be imported, and names an image by its file and its site', function (): void {
    $answer = ($this->run)(['sources' => [
        'https://photos.example/2026/harbour%20at%20dawn.jpg?ver=3',
        'http://photos.example/',
        'data:image/png;base64,iVBORw0KGgo=',
        'file:///C:/Users/ada/clip_image001.png',
        'blob:https://docs.example/1b2c',
        '/wp-content/uploads/harbour.jpg',
        'harbour.jpg',
    ]]);

    expect($answer['problems'])->toBe([null, null, 'inside', 'computer', 'computer', 'incomplete', 'incomplete'])
        ->and($answer['labels'][0])->toBe(['file' => 'harbour at dawn.jpg', 'site' => 'photos.example'])
        ->and($answer['labels'][1])->toBe(['file' => '', 'site' => 'photos.example'])
        ->and($answer['labels'][2])->toBe(['file' => '', 'site' => '']);
});

it('leaves pasted images out of what is saved, keeping every block the editor needs to load it again (I-453)', function (): void {
    $cell = fn (array $content): array => ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => $content]]]]];

    $answer = ($this->run)(['documents' => [
        ['type' => 'doc', 'content' => [($this->words)('Gates open at six.'), ($this->pasted)(), ($this->words)('Ferries every hour.')]],
        ['type' => 'doc', 'content' => [['type' => 'blockquote', 'content' => [($this->pasted)()]]]],
        ['type' => 'doc', 'content' => [$cell([($this->pasted)()])]],
        ['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [($this->words)('Nets'), ($this->pasted)()]]]]]],
        ['type' => 'doc', 'content' => [($this->pasted)()]],
    ]]);

    foreach ($answer['documents'] as $document) {
        expect((string) json_encode($document))->not->toContain('pastedImage')
            ->and(fn () => app(DocumentValidator::class)->validate(DocumentNormaliser::normalise($document), allowUnfinishedCards: true))->not->toThrow(Throwable::class);
    }

    expect(array_column($answer['documents'][0]['content'], 'type'))->toBe(['paragraph', 'paragraph'])
        ->and($answer['documents'][1]['content'][0]['content'])->toBe([['type' => 'paragraph']])
        ->and($answer['documents'][2]['content'][0]['content'][0]['content'][0]['content'])->toBe([['type' => 'paragraph']])
        ->and(array_column($answer['documents'][3]['content'][0]['content'][0]['content'], 'type'))->toBe(['paragraph'])
        ->and($answer['documents'][4]['content'])->toBe([['type' => 'paragraph']]);
});

it('draws a pasted image as an img, so a copy within the editor pastes as one again', function (): void {
    expect(($this->run)([])['html'])->toBe(['img', ['src' => 'https://photos.example/harbour.jpg', 'alt' => 'The harbour at dawn']]);
});
