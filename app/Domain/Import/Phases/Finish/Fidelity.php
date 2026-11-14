<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Finish;

use Illuminate\Support\Str;

/**
 * How much of an item's words on WordPress Teme's copy lost, counted word by
 * word, and where the missing passages begin (I-337).
 */
final readonly class Fidelity
{
    /** The share of WordPress's words lost, in percent, above which an item is flagged. */
    public const float MOST_LOST = 2.0;

    /** Missing passages named. */
    public const int PASSAGES = 3;

    /** Words of a missing passage shown. */
    public const int PASSAGE_WORDS = 8;

    /**
     * @param  list<string>  $passages  each missing passage's first words, as WordPress has them
     */
    private function __construct(
        public int $total,
        public int $lost,
        public float $share,
        public array $passages,
    ) {}

    /**
     * Compares WordPress's rendered HTML with Teme's drawn HTML.
     */
    public static function compare(string $wordpressHtml, string $temeHtml): self
    {
        $blocks = FidelityText::blocks($wordpressHtml);
        $theirs = FidelityText::words(implode(' ', $blocks));
        $ours = FidelityText::words(implode(' ', FidelityText::blocks($temeHtml)));
        $have = array_count_values($ours);
        $lost = 0;

        foreach (array_count_values($theirs) as $word => $count) {
            $lost += max(0, $count - ($have[$word] ?? 0));
        }

        $total = count($theirs);
        $share = $total === 0 ? 0.0 : round($lost / $total * 100, 1);

        return new self($total, $lost, $share, $lost === 0 ? [] : self::passages($blocks, ' '.implode(' ', $ours).' '));
    }

    /**
     * Whether more than the most may be lost of the words WordPress has.
     */
    public function flagged(): bool
    {
        return $this->total > 0 && $this->lost / $this->total * 100 > self::MOST_LOST;
    }

    /**
     * Where the sentences Teme doesn't have begin, at most PASSAGES of them.
     *
     * @param  list<string>  $blocks
     * @param  string  $ours  Teme's words joined by single spaces, with a space at each end
     *
     * @return list<string>
     */
    private static function passages(array $blocks, string $ours): array
    {
        $found = [];

        foreach ($blocks as $block) {
            foreach (preg_split('/(?<=[.!?…])\s+/u', $block) ?: [] as $sentence) {
                $words = FidelityText::words($sentence);

                // A sentence of three words or more whose words, in order, Teme doesn't have.
                if (count($words) < 3 || str_contains($ours, ' '.implode(' ', $words).' ')) {
                    continue;
                }

                $shown = preg_split('/\s+/u', trim($sentence)) ?: [];
                $found[] = Str::limit(implode(' ', array_slice($shown, 0, self::PASSAGE_WORDS)), 100, '').(count($shown) > self::PASSAGE_WORDS ? '…' : '');

                if (count($found) === self::PASSAGES) {
                    return $found;
                }
            }
        }

        return $found;
    }
}
