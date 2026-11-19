<?php

/**
 * The FAQ card in a feed and the API: each question a level-3 heading and
 * its answer below it, nothing folded away.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\CardProse;
use App\Domain\Cards\RenderContext;
use App\Domain\Cards\Types\FaqCard;
use App\Domain\Feeds\FeedCardRenderer;

final readonly class PlainFaq implements FeedCardRenderer
{
    public function card(): string
    {
        return 'faq';
    }

    public function html(array $attrs, RenderContext $context): string
    {
        $html = '';

        foreach (FaqCard::items($attrs) as $item) {
            if ($item['question'] !== '') {
                $html .= '<h3>'.e($item['question']).'</h3>'.CardProse::html($item['answer'], $context);
            }
        }

        return $html;
    }
}
