<?php

/**
 * WordPress's embed shortcode as an Embed card of the address it holds
 * (I-336).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Shortcodes;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\Blocks\Embeds;
use App\Domain\Import\Convert\Html\HtmlFragment;

final readonly class EmbedShortcode implements ShortcodeMapping
{
    public function shortcodes(): array
    {
        return ['embed'];
    }

    public function convert(Shortcode $shortcode, BlockContext $context): array
    {
        return Embeds::card($context, HtmlFragment::text(HtmlFragment::body($shortcode->content ?? '')));
    }
}
