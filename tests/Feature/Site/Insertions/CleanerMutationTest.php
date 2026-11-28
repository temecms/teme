<?php

declare(strict_types=1);

use App\Domain\Site\Insertions\InsertionCleaner;

/*
 * Markup a browser reads differently from the cleaner (mutation XSS) must
 * never come out of the cleaner as something that runs. The cleaner parses
 * with scripting off and serialises; the reader's browser parses again with
 * scripting on. Each case is a known way the two parses disagree.
 */

dataset('mutations', [
    'noscript raw text breakout' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>"></p></noscript>'],
    'MathML mglyph with a style breakout' => ['<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>'],
    'MathML malignmark with a style breakout' => ['<math><mtext><table><malignmark><style><img src=x onerror=alert(1)>'],
    'form and mglyph' => ['<form><math><mtext></form><form><mglyph><style></math><img src=x onerror=alert(1)>'],
    'noembed raw text breakout' => ['<noembed><p title="</noembed><img src=x onerror=alert(1)>"></p></noembed>'],
    'noframes raw text breakout' => ['<noframes><p title="</noframes><img src=x onerror=alert(1)>"></p></noframes>'],
    'declarative shadow root' => ['<template shadowrootmode="open"><img src=x onerror=alert(1)><script>alert(2)</script></template>'],
    'template content' => ['<template><script>alert(1)</script><img src=x onerror=alert(2)></template>'],
    'nested template in svg' => ['<svg><template><img src=x onerror=alert(1)></template></svg>'],
]);

it('never lets a mutation come back as something that runs', function (string $html): void {
    $cleaned = InsertionCleaner::clean($html)['html'];
    $lower = strtolower($cleaned);

    expect($lower)->not->toContain('onerror')
        ->and($lower)->not->toContain('<script>')
        ->and($lower)->not->toContain('alert(')
        ->and($lower)->not->toContain('<noscript')
        ->and($lower)->not->toContain('<noembed')
        ->and($lower)->not->toContain('<noframes')
        ->and($lower)->not->toContain('<mglyph')
        ->and($lower)->not->toContain('<malignmark')
        ->and($lower)->not->toContain('<template');
})->with('mutations');

it('gives markup that cleans to itself again', function (string $html): void {
    $once = InsertionCleaner::clean($html)['html'];

    expect(InsertionCleaner::clean($once)['html'])->toBe($once);
})->with('mutations');

it('strips core\'s own data-teme hooks from plugin markup', function (): void {
    $cleaned = InsertionCleaner::clean('<div data-teme-region-min="900" DATA-TEME-REGION-SRC="https://evil.example/x" data-plugin="kept">Ad</div>')['html'];

    expect(strtolower($cleaned))->not->toContain('data-teme-')
        ->and($cleaned)->toContain('data-plugin="kept"')
        ->and($cleaned)->toContain('Ad');
});

it('keeps ordinary markup as it was', function (): void {
    $html = '<aside class="ad"><p>Hello <strong>readers</strong></p><img src="https://ads.example/a.png" alt="An ad"><svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"></circle></svg><math><mi>x</mi></math></aside>';

    expect(InsertionCleaner::clean($html)['html'])->toBe($html);
});

it('drops a base and a meta http-equiv from plugin markup, and keeps an ordinary meta', function (): void {
    $cleaned = InsertionCleaner::clean('<base href="https://evil.example/"><meta http-equiv="refresh" content="0;url=https://evil.example/"><meta itemprop="name" content="Ad"><p>Ad</p>')['html'];
    $lower = strtolower($cleaned);

    expect($lower)->not->toContain('<base')
        ->and($lower)->not->toContain('http-equiv')
        ->and($lower)->not->toContain('evil.example')
        ->and($cleaned)->toContain('<meta itemprop="name" content="Ad">')
        ->and($cleaned)->toContain('<p>Ad</p>');
});

it('drops an object data address that runs script, and keeps an https one', function (): void {
    $unsafe = InsertionCleaner::clean('<object data="javascript:alert(1)"></object><object data="data:text/html,<script>alert(2)</script>"></object>')['html'];
    $safe = InsertionCleaner::clean('<object data="https://ads.example/a.svg"></object>')['html'];

    expect(strtolower($unsafe))->not->toContain('javascript:')
        ->and(strtolower($unsafe))->not->toContain('data:text/html')
        ->and($safe)->toContain('data="https://ads.example/a.svg"');
});
