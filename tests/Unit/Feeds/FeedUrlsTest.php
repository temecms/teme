<?php

declare(strict_types=1);

use App\Domain\Feeds\FeedUrls;

it('makes an address absolute from the site\'s own address, and leaves an absolute one as it is', function (): void {
    $urls = new FeedUrls;
    $origin = rtrim(url('/'), '/');
    $scheme = (string) parse_url($origin, PHP_URL_SCHEME);

    expect($urls->origin())->toBe($origin)
        ->and($urls->absolute('/storage/media/a.webp'))->toBe($origin.'/storage/media/a.webp')
        ->and($urls->absolute('/about/'))->toBe($origin.'/about/')
        ->and($urls->absolute('//cdn.example/a.webp'))->toBe($scheme.'://cdn.example/a.webp')
        ->and($urls->absolute('https://cdn.example/a.webp?x=1&y=2'))->toBe('https://cdn.example/a.webp?x=1&y=2')
        ->and($urls->absolute('HTTP://Cdn.example/a'))->toBe('HTTP://Cdn.example/a')
        ->and($urls->absolute('mailto:jane@example.test'))->toBe('mailto:jane@example.test');
});
