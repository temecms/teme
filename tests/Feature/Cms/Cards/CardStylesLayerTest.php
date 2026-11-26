<?php

declare(strict_types=1);

/*
 * Core's card stylesheets sit in the teme.core cascade layer, below the
 * theme's, so a theme restyles a card with ordinary CSS and no `!important`.
 */

it('keeps every core card stylesheet inside the teme.core layer', function (string $file): void {
    $css = trim((string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file)));

    // One layer block holds everything: it opens the file and its closing brace is the last character.
    expect($css)->toStartWith('@layer teme.core {')->toEndWith('}');

    $depth = 0;
    $opened = strpos($css, '{');

    foreach (str_split($css) as $at => $character) {
        $depth += $character === '{' ? 1 : ($character === '}' ? -1 : 0);

        // The layer closes only at the very end, so no rule sits outside it.
        expect($at < $opened || $depth > 0 || $at === strlen($css) - 1)->toBeTrue("a rule in {$file} sits outside the teme.core layer");
    }

    expect($depth)->toBe(0);
})->with(fn (): array => array_map(fn (string $file): array => [$file], glob(dirname(__DIR__, 4).'/resources/css/cards/*.css') ?: []));

it('has a stylesheet to check', function (): void {
    expect(glob(dirname(__DIR__, 4).'/resources/css/cards/*.css'))->not->toBeEmpty();
});
