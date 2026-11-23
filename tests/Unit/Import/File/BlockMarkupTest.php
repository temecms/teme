<?php

declare(strict_types=1);

use App\Domain\Import\File\BlockMarkup;

/*
 * The parser's contract is the shape WordPress's own block parser gives
 * (developer.wordpress.org, "Block grammar" and "Markup representation of a
 * block"). Each fixture pairs post_content with the blocks it must become.
 */

it('reads each kind of block markup into the blocks WordPress makes of it', function (string $name): void {
    $dir = base_path('tests/Fixtures/Import/file/blocks');
    $html = (string) file_get_contents("{$dir}/{$name}.html");
    $expected = json_decode((string) file_get_contents("{$dir}/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);

    expect(BlockMarkup::parse($html))->toBe($expected);
})->with([
    'classic text, a freeform block' => 'freeform',
    'core names and namespaced names' => 'names',
    'void and empty blocks' => 'void',
    'nested blocks' => 'nested',
    'an unclosed block, closed at the end of its parent' => 'unclosed',
    'a stray closer, kept as text' => 'stray-closer',
    'an arrow inside an attribute string' => 'arrow-in-attrs',
    'unicode escapes in attributes' => 'unicode-attrs',
    'attributes that are not valid JSON' => 'invalid-attrs',
]);

it('reads no blocks from an empty post', function (): void {
    expect(BlockMarkup::parse(''))->toBe([]);
});

it('gives back the real-markup samples\' blocks from the markup they were stored as', function (string $file): void {
    /** @var array{blocks: list<array<string, mixed>>} $fixture */
    $fixture = json_decode((string) file_get_contents(base_path("tests/Fixtures/Import/real-markup/{$file}")), true, 512, JSON_THROW_ON_ERROR);

    $serialize = function (array $block) use (&$serialize): string {
        if ($block['blockName'] === null) {
            return $block['innerHTML'];
        }

        $name = str_starts_with($block['blockName'], 'core/') ? substr($block['blockName'], 5) : $block['blockName'];
        $attrs = $block['attrs'] === [] ? '' : str_replace('--', '\\u002d\\u002d', (string) json_encode(
            $block['attrs'],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )).' ';

        if ($block['innerContent'] === []) {
            return "<!-- wp:{$name} {$attrs}/-->";
        }

        $children = $block['innerBlocks'];
        $inner = '';

        foreach ($block['innerContent'] as $piece) {
            $inner .= $piece ?? $serialize(array_shift($children));
        }

        return "<!-- wp:{$name} {$attrs}-->{$inner}<!-- /wp:{$name} -->";
    };

    $withoutAutop = function (array $block) use (&$withoutAutop): array {
        unset($block['autop']);
        $block['innerBlocks'] = array_map($withoutAutop, $block['innerBlocks']);

        return $block;
    };

    $expected = array_map($withoutAutop, $fixture['blocks']);
    $html = implode('', array_map($serialize, $fixture['blocks']));

    expect(BlockMarkup::parse($html))->toBe($expected);
})->with(fn (): array => array_map('basename', glob(dirname(__DIR__, 3).'/Fixtures/Import/real-markup/*.json') ?: []));

/*
 * Posts written for these tests, and the blocks WordPress's own parse_blocks()
 * made of them, produced by running WordPress outside Teme: Teme's reader must
 * agree with it on well-formed markup, which is what sites store. On broken
 * markup the two differ on purpose: WordPress gives an unclosed block's text
 * twice and turns everything after a stray closer into one freeform block;
 * Teme keeps each piece of text once, in its block (the fixtures in blocks/).
 */
it('reads stored posts into exactly the blocks WordPress makes of them', function (string $name): void {
    $dir = base_path('tests/Fixtures/Import/file/wordpress');
    $expected = json_decode((string) file_get_contents("{$dir}/{$name}.parsed.json"), true, 512, JSON_THROW_ON_ERROR);

    expect(BlockMarkup::parse((string) file_get_contents("{$dir}/{$name}.html")))->toBe($expected);
})->with(['post-gutenberg', 'post-layout', 'post-embeds', 'post-mixed-classic']);
