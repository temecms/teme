<?php

declare(strict_types=1);

use App\Domain\Import\Phases\ImportMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Image and gallery blocks (I-338): cards by media ID, traced
 * to what Teme imported; what can't come across is named.
 */

beforeEach(function (): void {
    $this->media = array_map(fn (): string => (string) Str::ulid(), array_fill_keys(range(1, 40), null));
    $this->convert = function (array $blocks): array {
        $conversion = wpConvert($blocks, $this->media);
        wpAccepted($conversion->document);

        return [
            wpWithoutIds($conversion->document)['content'],
            array_map(fn (ImportMessage $message): array => [$message->code, $message->message, $message->context], $conversion->messages),
        ];
    };
    $this->image = fn (int $id, string $more = '', array $attrs = []): array => wpBlock(
        'core/image',
        "<figure class=\"wp-block-image size-large\"><img src=\"https://old.example/wp-content/uploads/2024/05/i{$id}.jpg\" alt=\"Image {$id}\" class=\"wp-image-{$id}\"/>{$more}</figure>",
        ['id' => $id, 'sizeSlug' => 'large', 'linkDestination' => 'none', ...$attrs],
    );
    $this->card = fn (int $id, array $attrs = []): array => ['type' => 'image', 'attrs' => [
        'mediaId' => $this->media[$id], 'alt' => "Image {$id}", 'decorative' => false, 'caption' => null, 'credit' => null, 'width' => 'column', ...$attrs,
    ]];
});

it('converts an image block to a card, its alt for this use, its width and its caption', function (): void {
    [$content, $said] = ($this->convert)([wpBlock(
        'core/image',
        '<figure class="wp-block-image alignwide size-large"><img src="https://old.example/wp-content/uploads/2024/05/cat-1024x768.jpg" alt="A  cat" class="wp-image-12"/><figcaption class="wp-element-caption">Our <em>cat</em></figcaption></figure>',
        ['id' => 12, 'sizeSlug' => 'large', 'align' => 'wide'],
    )]);

    expect($content)->toBe([($this->card)(12, ['alt' => 'A cat', 'caption' => 'Our cat', 'width' => 'wide'])])
        ->and($said)->toBe([]);
});

it('leaves an empty alt to the library, unless WordPress marked the image decorative', function (): void {
    [$content] = ($this->convert)([
        wpBlock('core/image', '<figure class="wp-block-image"><img src="https://old.example/wp-content/uploads/a.jpg" alt="" class="wp-image-1"/></figure>', ['id' => 1]),
        wpBlock('core/image', '<figure class="wp-block-image"><img src="https://old.example/wp-content/uploads/b.jpg" alt="x" class="wp-image-2"/></figure>', ['id' => 2, 'isDecorative' => true]),
    ]);

    expect($content)->toBe([($this->card)(1, ['alt' => null]), ($this->card)(2, ['alt' => null, 'decorative' => true])]);
});

it('leaves out an image\'s link unless it goes to its own file or page, saying so', function (): void {
    [, $said] = ($this->convert)([
        wpBlock('core/image', '<figure class="wp-block-image"><a href="https://old.example/wp-content/uploads/2024/05/cat.jpg"><img src="https://old.example/wp-content/uploads/2024/05/cat-1024x768.jpg" class="wp-image-12"/></a></figure>', ['id' => 12]),
        wpBlock('core/image', '<figure class="wp-block-image"><a href="https://old.example/?attachment_id=13"><img src="https://old.example/wp-content/uploads/b.jpg" class="wp-image-13"/></a></figure>', ['id' => 13, 'linkDestination' => 'attachment']),
        wpBlock('core/image', '<figure class="wp-block-image"><a href="https://shop.example/cat"><img src="https://old.example/wp-content/uploads/c.jpg" class="wp-image-14"/></a></figure>', ['id' => 14, 'linkDestination' => 'custom']),
    ]);

    expect($said)->toBe([['image.link', 'Left out the link on 1 image: Teme’s images don’t link anywhere.', ['count' => 1]]]);
});

it('keeps a caption too long for the card as a paragraph after it', function (): void {
    $words = trim(str_repeat('word ', 401));

    [$content] = ($this->convert)([($this->image)(3, "<figcaption>{$words}</figcaption>")]);

    expect($content)->toBe([($this->card)(3), ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $words]]]]);
});

it('leaves out an image Teme doesn\'t have, naming its file, and links images from other sites, naming their host', function (): void {
    [$content, $said] = ($this->convert)([
        wpBlock('core/image', '<figure class="wp-block-image"><img src="https://old.example/wp-content/uploads/2024/05/gone-300x200.jpg" class="wp-image-99"/></figure>', ['id' => 99]),
        wpBlock('core/image', '<figure class="wp-block-image"><img src="https://cdn.other.example/pic.jpg"/></figure>'),
        wpBlock('core/image', '<figure class="wp-block-image"><img src="https://cdn.other.example/pic2.jpg"/></figure>'),
    ]);

    expect($content)->toBe([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'pic.jpg', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://cdn.other.example/pic.jpg']]]]]], ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'pic2.jpg', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://cdn.other.example/pic2.jpg']]]]]]])
        ->and($said)->toBe([
            ['image.missing', 'Left out 1 image that isn’t in Teme’s library: gone-300x200.jpg.', ['count' => 1, 'files' => ['gone-300x200.jpg']]],
            ['image.outside', 'Linked to 2 images on other sites instead of showing them: cdn.other.example.', ['count' => 2, 'hosts' => ['cdn.other.example']]],
        ]);
});

it('converts a gallery of WordPress 5.9 and later to a Gallery card, keeping its caption and naming its images\' captions', function (): void {
    [$content, $said] = ($this->convert)([wpBlock(
        'core/gallery',
        '<figure class="wp-block-gallery has-nested-images columns-2 is-cropped">%s<figcaption class="blocks-gallery-caption wp-element-caption">Trip</figcaption></figure>',
        ['linkTo' => 'none'],
        [($this->image)(1, '<figcaption class="wp-element-caption">One</figcaption>'), ($this->image)(2)],
    )]);

    expect($content)->toBe([['type' => 'gallery', 'attrs' => ['mediaIds' => [$this->media[1], $this->media[2]], 'caption' => 'Trip', 'layout' => 'grid']]])
        ->and($said)->toBe([['gallery.captions', 'Left out 1 caption on a gallery’s image: a Teme gallery has one caption.', ['count' => 1]]]);
});

it('converts an older gallery from its ids and its HTML', function (): void {
    [$content] = ($this->convert)([wpBlock(
        'core/gallery',
        '<figure class="wp-block-gallery columns-3"><ul class="blocks-gallery-grid"><li class="blocks-gallery-item"><figure><img src="https://old.example/wp-content/uploads/a.jpg" data-id="1" class="wp-image-1"/></figure></li><li class="blocks-gallery-item"><figure><img src="https://old.example/wp-content/uploads/b.jpg" data-id="2"/></figure></li><li class="blocks-gallery-item"><figure><img src="https://old.example/wp-content/uploads/c.jpg"/></figure></li></ul></figure>',
        ['ids' => [1, 2, 3], 'columns' => 3],
    )]);

    expect($content)->toBe([['type' => 'gallery', 'attrs' => ['mediaIds' => [$this->media[1], $this->media[2], $this->media[3]], 'caption' => null, 'layout' => 'grid']]]);
});

it('splits a gallery of more than 24 images, and keeps a gallery of one as its Image card with the gallery\'s caption', function (): void {
    [$content] = ($this->convert)([
        wpBlock('core/gallery', '<figure class="wp-block-gallery has-nested-images">%s</figure>', [], array_map(fn (int $id): array => ($this->image)($id), range(1, 25))),
        wpBlock('core/gallery', '<figure class="wp-block-gallery has-nested-images">%s<figcaption class="blocks-gallery-caption">Solo</figcaption></figure>', [], [($this->image)(30)]),
        wpBlock('core/gallery', '<figure class="wp-block-gallery has-nested-images">%s<figcaption class="blocks-gallery-caption">Gallery words</figcaption></figure>', [], [($this->image)(31, '<figcaption class="wp-element-caption">Own</figcaption>')]),
    ]);

    expect($content[0]['type'])->toBe('gallery')
        ->and($content[0]['attrs']['mediaIds'])->toBe(array_map(fn (int $id): string => $this->media[$id], range(1, 24)))
        ->and($content[1])->toBe(($this->card)(25))
        ->and($content[2])->toBe(($this->card)(30, ['caption' => 'Solo']))
        ->and($content[3])->toBe(($this->card)(31, ['caption' => 'Own']))
        ->and($content[4])->toBe(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Gallery words']]])
        ->and($content)->toHaveCount(5);
});

it('keeps what is left of a gallery whose images Teme lacks', function (): void {
    $gone = fn (int $id): array => wpBlock('core/image', "<figure class=\"wp-block-image\"><img src=\"https://old.example/wp-content/uploads/gone{$id}.jpg\" class=\"wp-image-{$id}\"/></figure>", ['id' => $id]);

    [$content, $said] = ($this->convert)([wpBlock('core/gallery', '<figure class="wp-block-gallery has-nested-images">%s</figure>', [], [($this->image)(1), $gone(91), $gone(92)])]);

    expect($content)->toBe([($this->card)(1)])
        ->and($said)->toBe([['image.missing', 'Left out 2 images that aren’t in Teme’s library: gone91.jpg, gone92.jpg.', ['count' => 2, 'files' => ['gone91.jpg', 'gone92.jpg']]]]);
});
