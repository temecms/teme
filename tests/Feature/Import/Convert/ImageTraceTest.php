<?php

declare(strict_types=1);

use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\Media\ImageTrace;
use App\Domain\Import\Convert\Media\KnownMedia;
use Dom\Element;

/*
 * I-338: an image in WordPress's HTML found among the media Teme imported,
 * by its attachment ID or by its file.
 */

beforeEach(function (): void {
    $this->site = new ConversionSite('https://www.old.example');
    $this->img = fn (string $attributes): Element => HtmlFragment::body("<img {$attributes}>")->querySelector('img');
});

it('gives the path below the uploads folder, decoded and lower-cased, for the site\'s own addresses', function (string $src, string $key): void {
    expect($this->site->uploadsKey($src))->toBe($key)
        ->and($this->site->isOwn($src))->toBeTrue();
})->with([
    'its home, encoded' => ['https://old.example/wp-content/uploads/2024/05/Photo%20One.JPG', '2024/05/photo one.jpg'],
    'www, http and a query' => ['http://www.old.example/wp-content/uploads/2024/05/a.jpg?w=300', '2024/05/a.jpg'],
    'no scheme' => ['//old.example/wp-content/uploads/a.jpg', 'a.jpg'],
    'a path on the site' => ['/wp-content/uploads/2024/05/a.jpg', '2024/05/a.jpg'],
    'Jetpack\'s copy' => ['https://i2.wp.com/old.example/wp-content/uploads/2024/05/a.jpg?resize=300%2C200', '2024/05/a.jpg'],
]);

it('gives no path for another site, an address relative to a page, data, or a place outside the uploads folder', function (string $src): void {
    expect($this->site->uploadsKey($src))->toBeNull();
})->with([
    'another site' => ['https://other.example/wp-content/uploads/2024/05/a.jpg'],
    'Jetpack\'s copy of another site' => ['https://i0.wp.com/other.example/wp-content/uploads/a.jpg'],
    'relative to the page' => ['images/a.jpg'],
    'data' => ['data:image/gif;base64,R0lGODlhAQABAAAAACw='],
    'outside the uploads folder' => ['https://old.example/about/a.jpg'],
    'a user name' => ['https://ada@old.example/wp-content/uploads/a.jpg'],
]);

it('reads uploads kept on another host', function (): void {
    $site = new ConversionSite('https://old.example', 'https://cdn.example/media');

    expect($site->uploadsKey('https://cdn.example/media/2024/A.jpg'))->toBe('2024/a.jpg')
        ->and($site->uploadsKey('https://old.example/wp-content/uploads/2024/a.jpg'))->toBe('2024/a.jpg')
        ->and($site->uploadsKey('https://cdn.example/other/a.jpg'))->toBeNull()
        ->and($site->hostOf('https://WWW.Cdn.Other.example/x.jpg'))->toBe('cdn.other.example');
});

it('traces an image by the block\'s ID, then its class, then a gallery\'s data-id, then a caption\'s ID', function (): void {
    $media = new KnownMedia([10 => 'M10', 11 => 'M11', 12 => 'M12', 13 => 'M13']);
    $find = fn (string $attributes, ?int $id = null, ?int $caption = null): ?string => ImageTrace::find(($this->img)($attributes), $this->site, $media, $id, $caption)['mediaId'];

    expect($find('class="alignnone wp-image-11" data-id="12"', 10))->toBe('M10')
        ->and($find('class="wp-image-11" data-id="12"', 99))->toBe('M11')
        ->and($find('class="size-full" data-id="12"'))->toBe('M12')
        ->and($find('src="https://old.example/x.jpg"', null, 13))->toBe('M13');
});

it('traces an image by its file name across sizes, copies, Jetpack\'s copies and encodings', function (string $src, string $found): void {
    $media = new KnownMedia([], ['2024/05/photo one.jpg' => 'MF', '2024/05/big.jpg' => 'MB']);

    expect(ImageTrace::find(($this->img)("src=\"{$src}\""), $this->site, $media)['mediaId'])->toBe($found);
})->with([
    'a size' => ['https://old.example/wp-content/uploads/2024/05/Photo%20One-1024x683.jpg', 'MF'],
    'the scaled copy' => ['https://old.example/wp-content/uploads/2024/05/big-scaled.jpg', 'MB'],
    'a size of the scaled copy' => ['https://old.example/wp-content/uploads/2024/05/big-scaled-300x200.jpg', 'MB'],
    'the rotated copy over http' => ['http://old.example/wp-content/uploads/2024/05/big-rotated.jpg', 'MB'],
    'Jetpack\'s copy' => ['https://i1.wp.com/www.old.example/wp-content/uploads/2024/05/big.jpg?w=600', 'MB'],
]);

it('reads the real address behind a lazy-loading placeholder', function (string $attribute): void {
    $media = new KnownMedia([], ['2024/05/big.jpg' => 'MB']);
    $img = ($this->img)("src=\"data:image/gif;base64,R0lGODlhAQABAAAAACw=\" {$attribute}=\"https://old.example/wp-content/uploads/2024/05/big-768x512.jpg\"");

    expect(ImageTrace::find($img, $this->site, $media)['mediaId'])->toBe('MB')
        ->and(ImageTrace::source($img))->toBe('https://old.example/wp-content/uploads/2024/05/big-768x512.jpg');
})->with(['data-lazy-src', 'data-src']);

it('never matches a file of the same name on another site', function (): void {
    $media = new KnownMedia([], ['2024/05/big.jpg' => 'MB']);

    expect(ImageTrace::find(($this->img)('src="https://other.example/wp-content/uploads/2024/05/big.jpg"'), $this->site, $media))
        ->toBe(['mediaId' => null, 'own' => false, 'file' => 'big.jpg', 'host' => 'other.example']);
});

it('says an own image Teme lacks is missing, naming its file', function (): void {
    $media = new KnownMedia;

    expect(ImageTrace::find(($this->img)('src="https://old.example/wp-content/uploads/2024/05/gone-300x200.jpg"'), $this->site, $media))
        ->toBe(['mediaId' => null, 'own' => true, 'file' => 'gone-300x200.jpg', 'host' => null])
        ->and(ImageTrace::find(($this->img)('class="wp-image-5" src="https://cdn.other.example/gone.jpg"'), $this->site, $media))
        ->toBe(['mediaId' => null, 'own' => true, 'file' => 'gone.jpg', 'host' => null]);
});

it('makes an address absolute against the old site\'s home, and a file map key from a path', function (): void {
    expect($this->site->absolute('/shop/'))->toBe('https://www.old.example/shop/')
        ->and($this->site->absolute('//x.example/a'))->toBe('https://x.example/a')
        ->and($this->site->absolute(' https://y.example/ '))->toBe('https://y.example/')
        ->and($this->site->absolute('shop.html'))->toBeNull()
        ->and($this->site->absolute(''))->toBeNull()
        ->and(ImageTrace::original('2024/05/big-scaled-300x200.jpg'))->toBe('2024/05/big.jpg')
        ->and(ConversionSite::fileKey('2024/05/Photo%20One-1024x683.JPG'))->toBe('2024/05/photo one-1024x683.jpg')
        ->and(ConversionSite::fileKey('2024/05/Photo One.JPG'))->toBe('2024/05/photo one.jpg')
        ->and(ConversionSite::fileKey('2024/05/Ünïcode-Ä.JPG'))->toBe('2024/05/Ünïcode-Ä.jpg')
        ->and($this->site->uploadsKey('https://old.example/wp-content/uploads/2024/05/Ünïcode-Ä.JPG'))->toBe(ConversionSite::fileKey('2024/05/Ünïcode-Ä.JPG'));
});
