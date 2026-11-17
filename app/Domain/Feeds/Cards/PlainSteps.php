<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\CardProse;
use App\Domain\Cards\RenderContext;
use App\Domain\Cards\Types\StepsCard;
use App\Domain\Feeds\FeedCardRenderer;
use App\Domain\Feeds\FeedImages;

/**
 * The Steps card in a feed and the API: the summary, the time and the
 * lists as paragraphs and lists, then each step as a level-3 heading
 * numbered in its words, its image and its text.
 */
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
        $position = 0;

        foreach (StepsCard::steps($attrs) as $step) {
            if ($step['title'] === '') {
                continue;
            }

            $item = $context->media[$step['mediaId']] ?? null;
            $image = $item === null ? '' : $this->images->img($item, null);
            $steps .= '<h3>'.e(__(':number. :title', ['number' => ++$position, 'title' => $step['title']])).'</h3>'
                .($image === '' ? '' : '<p>'.$image.'</p>')
                .CardProse::html($step['body'], $context);
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

        return $head.$steps;
    }
}
