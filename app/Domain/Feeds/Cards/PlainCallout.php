<?php

/**
 * The callout card in a feed: its text as a paragraph, since its tone is a colour on the page.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\RenderContext;
use App\Domain\Feeds\FeedCardRenderer;

final readonly class PlainCallout implements FeedCardRenderer
{
    public function card(): string
    {
        return 'callout';
    }

    public function html(array $attrs, RenderContext $context): string
    {
        $text = trim((string) ($attrs['text'] ?? ''));

        return $text === '' ? '' : '<p>'.e($text).'</p>';
    }
}
