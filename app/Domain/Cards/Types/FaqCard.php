<?php

declare(strict_types=1);

namespace App\Domain\Cards\Types;

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardProse;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\OutlinedCard;
use App\Domain\Cards\RenderContext;
use Illuminate\Validation\ValidationException;

/**
 * Questions and their answers, each answer short rich text. On the page
 * each question opens its answer, with no script; in feeds and the API they
 * are headings and paragraphs. Its pairs go to plugins that add structured
 * data (docs/guides/developers/plugins/seo.md); core adds none of its own.
 */
final class FaqCard implements CardType, OutlinedCard
{
    /** The most questions one card holds. */
    public const int MOST_ITEMS = 50;

    /** The longest question, in characters. */
    public const int MOST_QUESTION = 300;

    public function name(): string
    {
        return 'faq';
    }

    public function label(): string
    {
        return 'FAQ';
    }

    public function description(): string
    {
        return 'Questions and their answers. Each question opens its answer.';
    }

    public function since(): string
    {
        return '1.1.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            'items' => new CardAttribute('items', ['required', 'array', 'list', 'min:1', 'max:'.self::MOST_ITEMS]),
        ];
    }

    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('FAQ', 'Cards', ['faq', 'questions', 'answers', 'accordion', 'q&a'], 'faq');
    }

    public function renderHtml(array $attrs, RenderContext $context): string
    {
        return $this->renderInOutline($attrs, $context, 2);
    }

    public function renderInOutline(array $attrs, RenderContext $context, int $level): string
    {
        $items = '';

        foreach (self::items($attrs) as $item) {
            if ($item['question'] === '') {
                continue;
            }

            $items .= sprintf(
                '<details class="card-faq__item"><summary><h%2$d class="card-faq__question">%1$s</h%2$d></summary><div class="card-faq__answer">%3$s</div></details>',
                e($item['question']),
                $level,
                CardProse::html($item['answer'], $context),
            );
        }

        return $items === '' ? '' : '<div class="card-faq">'.$items.'</div>';
    }

    public function plainText(array $attrs): string
    {
        $words = [];

        foreach (self::items($attrs) as $item) {
            $words[] = $item['question'];
            $words[] = CardProse::text($item['answer']);
        }

        return trim(implode(' ', array_filter($words, fn (string $text): bool => $text !== '')));
    }

    public function references(array $attrs): array
    {
        $references = [];

        foreach (self::items($attrs) as $item) {
            array_push($references, ...CardProse::references($item['answer']));
        }

        return array_values(array_unique($references, SORT_REGULAR));
    }

    /**
     * Each item is an id, a question and an answer, and nothing else; no
     * id is used twice.
     */
    public function validate(array $attrs): void
    {
        $seen = [];

        foreach ((array) ($attrs['items'] ?? []) as $index => $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['id', 'question', 'answer']) !== []) {
                throw ValidationException::withMessages(["items.{$index}" => __('Each question has an id, its words and an answer, and nothing else.')]);
            }

            $id = $item['id'] ?? null;

            if (! is_string($id) || preg_match(DocumentSchema::NODE_ID, $id) !== 1 || isset($seen[$id])) {
                throw ValidationException::withMessages(["items.{$index}.id" => __('Each question needs its own id.')]);
            }

            $seen[$id] = true;
            $question = $item['question'] ?? null;

            if (! is_string($question) || trim($question) === '') {
                throw ValidationException::withMessages(["items.{$index}.question" => __('Write the question.')]);
            }

            if (mb_strlen($question) > self::MOST_QUESTION) {
                throw ValidationException::withMessages(["items.{$index}.question" => __('A question can be at most :count characters.', ['count' => self::MOST_QUESTION])]);
            }

            CardProse::check($item['answer'] ?? null, "items.{$index}.answer");
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $was = count((array) ($before['items'] ?? []));
        $now = count((array) ($after['items'] ?? []));
        $parts = [];

        if ($now > $was) {
            $parts[] = 'questions added';
        } elseif ($now < $was) {
            $parts[] = 'questions removed';
        } elseif (ChangeList::differs($before, $after, 'items')) {
            $parts[] = 'questions changed';
        }

        return ChangeList::line($this->label(), $parts);
    }

    /**
     * The card's items as far as they can be read: an id, the question's
     * words trimmed, and the answer as stored.
     *
     * @param  array<string, mixed>  $attrs
     *
     * @return list<array{id: string, question: string, answer: mixed}>
     */
    public static function items(array $attrs): array
    {
        $items = [];

        foreach ((array) ($attrs['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = [
                'id' => is_string($item['id'] ?? null) ? $item['id'] : '',
                'question' => is_string($item['question'] ?? null) ? trim($item['question']) : '',
                'answer' => $item['answer'] ?? null,
            ];
        }

        return $items;
    }
}
