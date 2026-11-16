<?php

declare(strict_types=1);

use App\Domain\Plugins\Manifest as PluginManifest;
use App\Domain\Support\HttpsLink;
use App\Domain\Themes\Manifest as ThemeManifest;

it('keeps an https address and refuses everything else', function (string $value, string $expected): void {
    expect(HttpsLink::or($value))->toBe($expected);
})->with([
    'https' => ['https://example.com/plugins', 'https://example.com/plugins'],
    'https in capitals' => ['HTTPS://example.com', 'HTTPS://example.com'],
    'javascript' => ['javascript:alert(1)', ''],
    'javascript with spaces' => ['  javascript:alert(1)', ''],
    'data' => ['data:text/html,<script>alert(1)</script>', ''],
    'http' => ['http://example.com', ''],
    'no host' => ['https://', ''],
    'too long' => ['https://example.com/'.str_repeat('a', 2000), ''],
    'empty' => ['', ''],
]);

it('never hands a link from a manifest that is not an https address to the screen', function (): void {
    $manifest = new PluginManifest([
        'author_url' => 'javascript:alert(1)',
        'homepage' => 'https://example.com/plugin',
    ]);

    expect($manifest->authorUrl())->toBe('')
        ->and($manifest->homepage())->toBe('https://example.com/plugin');
});

it('never hands a theme manifest link that is not an https address to the screen', function (): void {
    $manifest = new ThemeManifest([
        'author_url' => 'https://example.com/studio',
        'homepage' => 'javascript:alert(1)',
    ]);

    expect($manifest->authorUrl())->toBe('https://example.com/studio')
        ->and($manifest->homepage())->toBe('');
});
