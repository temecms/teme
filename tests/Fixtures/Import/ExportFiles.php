<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Domain\Import\File\ContentBlocks;

/**
 * What the export file reader's tests share: where its fixtures are, and
 * the block parser's stand-in until the parser's own part joins it.
 */
final class ExportFiles
{
    /**
     * The path of one of the reader's fixtures.
     */
    public static function path(string $name): string
    {
        return __DIR__.'/file/'.$name;
    }

    /**
     * The whole content as one classic chunk, with its paragraphs marked so a test sees them.
     */
    public static function blocks(): ContentBlocks
    {
        return ContentBlocks::using(
            fn (string $html): array => $html === '' ? [] : [['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => [$html]]],
            fn (string $html): string => '<p>'.trim($html).'</p>',
        );
    }
}
