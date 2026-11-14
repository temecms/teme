<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Cards\DocumentSchema;
use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Convert\ParsedBlock;

/**
 * The File block as a paragraph linking to the file by its ID, its name as
 * the words (I-1705). Its download button and its embedded viewer are
 * presentation, and are left out.
 */
final readonly class Files implements BlockMapping
{
    public function blocks(): array
    {
        return ['core/file'];
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        $root = HtmlFragment::body($block->innerHtml);
        $link = $root->querySelector('a:not([download])');
        $href = $block->attr('href');
        $address = trim(is_string($href) && $href !== '' ? $href : (string) $link?->getAttribute('href'));
        $words = $link === null ? '' : HtmlFragment::text($link);
        $name = basename(rawurldecode((string) parse_url($address, PHP_URL_PATH)));
        $words = $words !== '' ? $words : $name;

        if ($words === '') {
            return [];
        }

        $found = $context->file($block->intAttr('id'), $address);
        $target = null;

        if ($found['mediaId'] !== null) {
            $target = DocumentSchema::linkTo('media', strtoupper($found['mediaId']));
            $target = DocumentSchema::linkTarget($target) === null ? null : $target;
        } elseif ($found['own'] && $address !== '') {
            $context->report->count('file.missing', '', $found['file']);
        } elseif ($address !== '') {
            $target = $context->links->href($address);
        }

        return [Nodes::paragraph([['type' => 'text', 'text' => $words, ...($target === null ? [] : ['marks' => [['type' => 'link', 'attrs' => ['href' => $target]]]])]])];
    }
}
