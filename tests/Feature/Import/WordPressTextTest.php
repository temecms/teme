<?php

declare(strict_types=1);

use App\Domain\Import\Phases\Terms\WordPressSlugs;
use App\Domain\Import\Phases\Terms\WordPressText;

it('keeps a WordPress slug Teme can use, decoded, and makes one from the rest', function (string $wordpress, string $slug, bool $kept): void {
    expect(WordPressSlugs::fromWordPress($wordpress))->toBe(['slug' => $slug, 'kept' => $kept]);
})->with([
    'plain' => ['owls', 'owls', true],
    'letters percent-encoded (I-397)' => ['caf%c3%a9', 'café', true],
    'percent-encoded in capitals' => ['CAF%C3%89', 'café', true],
    'an underscore' => ['night_and_day', 'night-and-day', false],
    'hyphens doubled' => ['a--b', 'a-b', false],
    'an encoded dash alone' => ['%e2%80%93', '', false],
    'a byte that isn\'t UTF-8' => ['caf%e9', 'caf', false],
    'longer than Teme keeps' => [str_repeat('a', 200), str_repeat('a', 190), false],
]);

it('undoes WordPress\'s escaping of a name exactly once', function (string $stored, string $name): void {
    expect(WordPressText::decoded($stored))->toBe($name);
})->with([
    'an ampersand WordPress escaped' => ['Night &amp; Day', 'Night & Day'],
    'an entity an editor typed, which WordPress escaped again' => [' Fish &amp;amp; Chips ', 'Fish &amp; Chips'],
]);

it('keeps a description as plain text, and nothing as none', function (string $html, ?string $text): void {
    expect(WordPressText::plain($html))->toBe($text);
})->with([
    'formatting and a link' => ['<p>All about <strong>animals</strong> &amp; <a href="https://old.example/zoo/">more</a>.</p>', 'All about animals & more.'],
    'two paragraphs' => ['<p>One.</p><p>Two.</p>', "One.\nTwo."],
]);
