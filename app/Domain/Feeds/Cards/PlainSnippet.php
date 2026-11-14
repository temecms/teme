<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\RenderContext;
use App\Domain\Feeds\FeedCardRenderer;

/**
 * The snippet card in a feed: its code escaped in a `pre`, with no highlighting, and its caption.
 */
final readonly class PlainSnippet implements FeedCardRenderer
{
    public function card(): string
    {
        return 'snippet';
    }

    public function html(array $attrs, RenderContext $context): string
    {
        $code = (string) ($attrs['code'] ?? '');

        if ($code === '') {
            return '';
        }

        $caption = trim((string) ($attrs['caption'] ?? ''));

        return '<figure><pre><code>'.e($code).'</code></pre>'.($caption === '' ? '' : '<figcaption>'.e($caption).'</figcaption>').'</figure>';
    }
}
