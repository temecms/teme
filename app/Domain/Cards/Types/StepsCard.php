<?php

declare(strict_types=1);

namespace App\Domain\Cards\Types;

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardProse;
use App\Domain\Cards\CardReference;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\OutlinedCard;
use App\Domain\Cards\RenderContext;
use App\Domain\Media\ImageMarkup;
use Illuminate\Validation\ValidationException;

/**
 * How to do something: an optional summary, a total time, what it uses up
 * and what it needs, then numbered steps, each a title, short rich text and
 * an optional image. Its steps go to plugins that add structured data
 * (docs/guides/developers/plugins/seo.md); core adds none of its own.
 */
final class StepsCard implements CardType, OutlinedCard
{
    /** The most steps one card holds. */
    public const int MOST_STEPS = 50;

    /** The most materials, and the most tools. */
    public const int MOST_THINGS = 30;

    /** An ISO 8601 duration in days, hours and minutes, such as PT1H30M. */
    public const string DURATION = '/^P(?=\d|T\d)(?:(\d{1,3})D)?(?:T(?=\d)(?:(\d{1,4})H)?(?:(\d{1,5})M)?)?$/';

    public function name(): string
    {
        return 'steps';
    }

    public function label(): string
    {
        return 'Steps';
    }

    public function description(): string
    {
        return 'Numbered steps for how to do something, with the time it takes and what it needs.';
    }

    public function since(): string
    {
        return '1.1.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            'summary' => new CardAttribute('summary', ['nullable', 'string', 'max:1000']),
            'totalTime' => new CardAttribute('totalTime', ['nullable', 'string', 'regex:'.self::DURATION]),
            'materials' => new CardAttribute('materials', ['array', 'list', 'max:'.self::MOST_THINGS], []),
            'tools' => new CardAttribute('tools', ['array', 'list', 'max:'.self::MOST_THINGS], []),
            'steps' => new CardAttribute('steps', ['required', 'array', 'list', 'min:1', 'max:'.self::MOST_STEPS]),
        ];
    }

    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Steps', 'Cards', ['steps', 'how to', 'howto', 'instructions', 'recipe', 'guide'], 'steps');
    }

    public function renderHtml(array $attrs, RenderContext $context): string
    {
        return $this->renderInOutline($attrs, $context, 2);
    }

    public function renderInOutline(array $attrs, RenderContext $context, int $level): string
    {
        $steps = '';

        foreach (self::steps($attrs) as $step) {
            if ($step['title'] === '') {
                continue;
            }

            $item = $context->media[$step['mediaId']] ?? null;
            $image = $item === null ? '' : app(ImageMarkup::class)->item($item, 'content');

            $steps .= sprintf(
                '<li class="card-steps__step" id="%1$s"><h%2$d class="card-steps__title">%3$s</h%2$d>%4$s%5$s</li>',
                e(self::fragment($step['id'])),
                $level,
                e($step['title']),
                $image === '' ? '' : '<figure class="card-steps__image">'.$image.'</figure>',
                CardProse::html($step['body'], $context),
            );
        }

        if ($steps === '') {
            return '';
        }

        return '<div class="card-steps">'.self::head($attrs).'<ol class="card-steps__list">'.$steps.'</ol></div>';
    }

    public function plainText(array $attrs): string
    {
        $words = [trim((string) ($attrs['summary'] ?? '')), ...self::things($attrs, 'materials'), ...self::things($attrs, 'tools')];

        foreach (self::steps($attrs) as $step) {
            $words[] = $step['title'];
            $words[] = CardProse::text($step['body']);
        }

        return trim(implode(' ', array_filter($words, fn (string $text): bool => $text !== '')));
    }

    public function references(array $attrs): array
    {
        $references = [];

        foreach (self::steps($attrs) as $step) {
            if ($step['mediaId'] !== '') {
                $references[] = CardReference::media($step['mediaId']);
            }

            array_push($references, ...CardProse::references($step['body']));
        }

        return array_values(array_unique($references, SORT_REGULAR));
    }

    /**
     * Each material and tool is a short line; each step is an id, a title,
     * a body and an image by ID at most, and no id is used twice.
     */
    public function validate(array $attrs): void
    {
        foreach (['materials', 'tools'] as $list) {
            foreach ((array) ($attrs[$list] ?? []) as $index => $thing) {
                if (! is_string($thing) || trim($thing) === '' || mb_strlen($thing) > 200) {
                    throw ValidationException::withMessages(["{$list}.{$index}" => __('Each line is up to 200 characters, and not empty.')]);
                }
            }
        }

        $seen = [];

        foreach ((array) ($attrs['steps'] ?? []) as $index => $step) {
            if (! is_array($step) || array_diff(array_keys($step), ['id', 'title', 'body', 'mediaId']) !== []) {
                throw ValidationException::withMessages(["steps.{$index}" => __('Each step has an id, a title, its text and an image, and nothing else.')]);
            }

            $id = $step['id'] ?? null;

            if (! is_string($id) || preg_match(DocumentSchema::NODE_ID, $id) !== 1 || isset($seen[$id])) {
                throw ValidationException::withMessages(["steps.{$index}.id" => __('Each step needs its own id.')]);
            }

            $seen[$id] = true;
            $title = $step['title'] ?? null;

            if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 200) {
                throw ValidationException::withMessages(["steps.{$index}.title" => __('Give each step a title of up to 200 characters.')]);
            }

            if (($step['body'] ?? null) !== null) {
                CardProse::check($step['body'], "steps.{$index}.body", required: false);
            }

            $media = $step['mediaId'] ?? null;

            if ($media !== null && (! is_string($media) || strlen($media) !== 26)) {
                throw ValidationException::withMessages(["steps.{$index}.mediaId" => __('Choose an image from the library.')]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $parts = [];

        foreach (['summary' => 'summary changed', 'totalTime' => 'time changed', 'materials' => 'materials changed', 'tools' => 'tools changed', 'steps' => 'steps changed'] as $key => $words) {
            if (ChangeList::differs($before, $after, $key)) {
                $parts[] = $words;
            }
        }

        return ChangeList::line($this->label(), $parts);
    }

    /**
     * The card's steps as far as they can be read.
     *
     * @param  array<string, mixed>  $attrs
     *
     * @return list<array{id: string, title: string, body: mixed, mediaId: string}>
     */
    public static function steps(array $attrs): array
    {
        $steps = [];

        foreach ((array) ($attrs['steps'] ?? []) as $step) {
            if (! is_array($step)) {
                continue;
            }

            $steps[] = [
                'id' => is_string($step['id'] ?? null) ? $step['id'] : '',
                'title' => is_string($step['title'] ?? null) ? trim($step['title']) : '',
                'body' => $step['body'] ?? null,
                'mediaId' => is_string($step['mediaId'] ?? null) ? $step['mediaId'] : '',
            ];
        }

        return $steps;
    }

    /**
     * The materials or the tools, each trimmed, empty lines left out.
     *
     * @param  array<string, mixed>  $attrs
     *
     * @return list<string>
     */
    public static function things(array $attrs, string $list): array
    {
        return array_values(array_filter(
            array_map(fn (mixed $thing): string => is_string($thing) ? trim($thing) : '', (array) ($attrs[$list] ?? [])),
            fn (string $thing): bool => $thing !== '',
        ));
    }

    /**
     * The place on the page a step stands at.
     */
    public static function fragment(string $id): string
    {
        return 'step-'.strtolower($id);
    }

    /**
     * A duration as words, such as "1 hour 30 minutes"; null when it isn't one.
     */
    public static function duration(?string $iso): ?string
    {
        if ($iso === null || preg_match(self::DURATION, $iso, $parts) !== 1) {
            return null;
        }

        $words = [];

        foreach ([1 => ':count day|:count days', 2 => ':count hour|:count hours', 3 => ':count minute|:count minutes'] as $index => $choice) {
            $count = (int) ($parts[$index] ?? 0);

            if ($count > 0) {
                $words[] = trans_choice($choice, $count);
            }
        }

        return $words === [] ? null : implode(' ', $words);
    }

    /**
     * The summary, the time and the lists above the steps.
     *
     * @param  array<string, mixed>  $attrs
     */
    private static function head(array $attrs): string
    {
        $html = '';
        $summary = trim((string) ($attrs['summary'] ?? ''));
        $time = is_string($attrs['totalTime'] ?? null) ? $attrs['totalTime'] : null;
        $words = self::duration($time);

        if ($summary !== '') {
            $html .= '<p class="card-steps__summary">'.e($summary).'</p>';
        }

        if ($words !== null) {
            $html .= '<p class="card-steps__time">'.e(__('Total time:')).' <time datetime="'.e((string) $time).'">'.e($words).'</time></p>';
        }

        foreach (['materials' => __('You’ll need'), 'tools' => __('Tools')] as $list => $label) {
            $things = self::things($attrs, $list);

            if ($things !== []) {
                $html .= '<div class="card-steps__'.$list.'"><p class="card-steps__label">'.e($label).'</p><ul>'
                    .implode('', array_map(fn (string $thing): string => '<li>'.e($thing).'</li>', $things)).'</ul></div>';
            }
        }

        return $html;
    }
}
