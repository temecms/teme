<?php

declare(strict_types=1);

use App\Domain\Import\File\Paragraphs;

/*
 * Classic-editor HTML made into paragraphs as WordPress shows it, after
 * wpautop()'s documented behaviour. Each fixture pairs the stored HTML with
 * the HTML it must become.
 */

it('makes paragraphs of classic HTML as WordPress shows it', function (string $name): void {
    $dir = base_path('tests/Fixtures/Import/file/paragraphs');

    expect(Paragraphs::make((string) file_get_contents("{$dir}/{$name}.html")))
        ->toBe((string) file_get_contents("{$dir}/{$name}.autop.html"));
})->with([
    'blank lines separate paragraphs' => 'blank-lines',
    'single newlines become line breaks' => 'line-breaks',
    'nothing inside pre is wrapped' => 'pre',
    'nothing inside script or style is wrapped' => 'script-and-style',
    'block-level elements are never wrapped' => 'block-level',
    'a blockquote holds its paragraph' => 'blockquote',
    'shortcodes on their own line stay unwrapped' => 'shortcodes',
    'only whitespace' => 'blank',
]);

it('reads windows and old mac line endings as newlines', function (): void {
    expect(Paragraphs::make("One\r\nline\r\n\r\nTwo\rthree"))->toBe("<p>One<br />\nline</p>\n<p>Two<br />\nthree</p>\n");
});

it('gives the real-markup samples\' freeform chunks the paragraphs wpautop() gave them', function (string $file): void {
    /** @var array{blocks: list<array<string, mixed>>} $fixture */
    $fixture = json_decode((string) file_get_contents(base_path("tests/Fixtures/Import/real-markup/{$file}")), true, 512, JSON_THROW_ON_ERROR);
    $freeform = array_values(array_filter($fixture['blocks'], fn (array $block): bool => $block['blockName'] === null));

    expect($freeform)->not->toBe([]);

    foreach ($freeform as $block) {
        expect(Paragraphs::make($block['innerHTML']))->toBe($block['autop']);
    }
})->with(['tagdiv-row.json', 'wpbakery-row.json']);

/*
 * Classic posts written for these tests, and the HTML WordPress's own wpautop()
 * made of them (with its default shortcodes registered, and shortcode_unautop()
 * after it, as a site shows a post), produced by running WordPress outside Teme.
 * They leave out where WordPress's output is broken HTML: it wraps a script in
 * paragraphs and leaves a div's first paragraph unopened. Teme's own fixtures in
 * paragraphs/ hold the right result for those.
 */
it('makes the paragraphs WordPress makes of a classic post', function (string $name): void {
    $dir = base_path('tests/Fixtures/Import/file/wordpress');

    expect(Paragraphs::make((string) file_get_contents("{$dir}/{$name}.html")))
        ->toBe((string) file_get_contents("{$dir}/{$name}.autop.html"));
})->with(['classic-article', 'classic-shortcodes']);
