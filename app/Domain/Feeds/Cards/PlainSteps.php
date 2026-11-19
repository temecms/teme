<?php

/**
 * The Steps card in a feed and the API: the summary, the time and the
 * lists as paragraphs and lists, then the steps as an ordered list, each
 * item its title in bold, its image and its text.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\CardProse;
use App\Domain\Cards\RenderContext;
use App\Domain\Cards\Types\StepsCard;
use App\Domain\Feeds\FeedCardRenderer;
use App\Domain\Feeds\FeedImages;

final readonly class PlainSteps implements FeedCardRenderer
{
    public function __construct(private FeedImages $images) {}

    public function card(): string
    {
        return 'steps';
    }

    public function html(array $attrs, RenderContext $context): string
    {
        $steps = '';

        foreach (StepsCard::steps($attrs) as $step) {
            if ($step['title'] === '') {
                continue;
            }

            $item = $context->media[$step['mediaId']] ?? null;
            $image = $item === null ? '' : $this->images->img($item, null);
            $steps .= '<li><p><strong>'.e($step['title']).'</strong></p>'
                .($image === '' ? '' : '<p>'.$image.'</p>')
                .CardProse::html($step['body'], $context).'</li>';
        }

        if ($steps === '') {
            return '';
        }

        $head = '';
        $summary = trim((string) ($attrs['summary'] ?? ''));
        $time = StepsCard::duration(is_string($attrs['totalTime'] ?? null) ? $attrs['totalTime'] : null);

        if ($summary !== '') {
            $head .= '<p>'.e($summary).'</p>';
        }

        if ($time !== null) {
            $head .= '<p>'.e(__('Total time:')).' '.e($time).'</p>';
        }

        foreach (['materials' => __('You’ll need'), 'tools' => __('Tools')] as $list => $label) {
            $things = StepsCard::things($attrs, $list);

            if ($things !== []) {
                $head .= '<p>'.e($label).'</p><ul>'.implode('', array_map(fn (string $thing): string => '<li>'.e($thing).'</li>', $things)).'</ul>';
            }
        }

        return $head.'<ol>'.$steps.'</ol>';
    }
}
