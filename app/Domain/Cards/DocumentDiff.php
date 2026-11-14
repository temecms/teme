<?php

declare(strict_types=1);

namespace App\Domain\Cards;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\DiffOnlyOutputBuilder;

/**
 * Compares two documents block by block, for the revision comparison. Blocks
 * are paired by their `id` where they have one, then by identical content,
 * then by position; paired prose is compared word by word, and a card is
 * described in words rather than diffed (CardType::describeChange()). It
 * computes a view and stores nothing.
 */
final readonly class DocumentDiff
{
    public function __construct(private CardRegistry $cards) {}

    /**
     * The blocks in the later document's order, each removed block where it
     * used to be. Every text in them is escaped HTML.
     *
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     *
     * @return list<DiffBlock>
     */
    public function between(array $before, array $after): array
    {
        $old = self::blocks($before);
        $new = self::blocks($after);
        $pairs = $this->pair($old, $new);
        $kept = self::keptInOrder($pairs);

        // A removed block sits before the block that followed it, or at the end.
        $pairedOld = array_flip($pairs);
        ksort($pairedOld);
        $removedBefore = [];

        foreach (array_keys($old) as $i) {
            if (isset($pairedOld[$i])) {
                continue;
            }

            $anchor = 'end';

            foreach ($pairedOld as $oldIndex => $newIndex) {
                if ($oldIndex > $i) {
                    $anchor = $newIndex;

                    break;
                }
            }

            $removedBefore[$anchor][] = $i;
        }

        $blocks = [];

        foreach ($new as $j => $node) {
            foreach ($removedBefore[$j] ?? [] as $i) {
                $blocks[] = new DiffBlock('removed', $this->text($old[$i]), null, self::type($old[$i]));
            }

            if (! isset($pairs[$j])) {
                $blocks[] = new DiffBlock('added', null, $this->text($node), self::type($node));

                continue;
            }

            $blocks[] = $this->paired($old[$pairs[$j]], $node, moved: ! isset($kept[$j]));
        }

        foreach ($removedBefore['end'] ?? [] as $i) {
            $blocks[] = new DiffBlock('removed', $this->text($old[$i]), null, self::type($old[$i]));
        }

        return $blocks;
    }

    /**
     * A block present in both documents: the same, changed or moved.
     *
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     */
    private function paired(array $before, array $after, bool $moved): DiffBlock
    {
        $type = self::type($after);
        $card = $this->cards->find($type);
        $identical = self::withoutId($before) === self::withoutId($after);

        if ($identical) {
            $text = $this->text($after);

            return new DiffBlock($moved ? 'moved' : 'same', $text, $text, $type);
        }

        if ($card !== null) {
            $description = e($card->describeChange((array) ($before['attrs'] ?? []), (array) ($after['attrs'] ?? [])));

            return new DiffBlock($moved ? 'moved' : 'changed', null, $description, $type);
        }

        return new DiffBlock(
            $moved ? 'moved' : 'changed',
            $this->text($before),
            self::words(self::plain($before), self::plain($after)),
            $type,
        );
    }

    /**
     * Pairs the blocks of the later document with those of the earlier one:
     * by `id`, then by identical content in order, then by position among
     * those left between two matches, when the node types agree.
     *
     * @param  list<array<mixed>>  $old
     * @param  list<array<mixed>>  $new
     *
     * @return array<int, int> later index => earlier index
     */
    private function pair(array $old, array $new): array
    {
        $pairs = [];
        $byId = [];

        foreach ($old as $i => $node) {
            $id = self::id($node);

            if ($id !== null) {
                $byId[$id] = $i;
            }
        }

        foreach ($new as $j => $node) {
            $id = self::id($node);

            if ($id !== null && isset($byId[$id]) && self::type($old[$byId[$id]]) === self::type($node)) {
                $pairs[$j] = $byId[$id];
                unset($byId[$id]);
            }
        }

        $taken = array_flip($pairs);
        $leftOld = array_values(array_filter(array_keys($old), fn (int $i): bool => ! isset($taken[$i])));
        $leftNew = array_values(array_filter(array_keys($new), fn (int $j): bool => ! isset($pairs[$j])));

        // Identical blocks first, in order (a longest common subsequence),
        // then the rest by position within each gap between two of them.
        $key = fn (array $node): string => (string) json_encode(self::withoutId($node));
        $matches = self::commonRun(
            array_map(fn (int $i): string => $key($old[$i]), $leftOld),
            array_map(fn (int $j): string => $key($new[$j]), $leftNew),
        );

        $gapOld = 0;
        $gapNew = 0;

        foreach ([...$matches, [count($leftOld), count($leftNew)]] as [$a, $b]) {
            for ($k = 0; $gapOld + $k < $a && $gapNew + $k < $b; $k++) {
                $i = $leftOld[$gapOld + $k];
                $j = $leftNew[$gapNew + $k];

                if (self::type($old[$i]) === self::type($new[$j])) {
                    $pairs[$j] = $i;
                }
            }

            if ($a < count($leftOld) && $b < count($leftNew)) {
                $pairs[$leftNew[$b]] = $leftOld[$a];
            }

            $gapOld = $a + 1;
            $gapNew = $b + 1;
        }

        ksort($pairs);

        return $pairs;
    }

    /**
     * The index pairs of a longest common subsequence of two lists.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function commonRun(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $length = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $length[$i][$j] = $a[$i] === $b[$j]
                    ? $length[$i + 1][$j + 1] + 1
                    : max($length[$i + 1][$j], $length[$i][$j + 1]);
            }
        }

        $run = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $run[] = [$i, $j];
                $i++;
                $j++;
            } elseif ($length[$i + 1][$j] >= $length[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $run;
    }

    /**
     * The later indices of the longest run of pairs that kept their order;
     * every other pair moved.
     *
     * @param  array<int, int>  $pairs  later index => earlier index, by later index
     *
     * @return array<int, true>
     */
    private static function keptInOrder(array $pairs): array
    {
        $laterIndices = array_keys($pairs);
        $earlier = array_values($pairs);
        $count = count($earlier);

        if ($count === 0) {
            return [];
        }

        // Longest increasing subsequence of the earlier indices.
        $best = array_fill(0, $count, 1);
        $previous = array_fill(0, $count, -1);

        for ($x = 1; $x < $count; $x++) {
            for ($y = 0; $y < $x; $y++) {
                if ($earlier[$y] < $earlier[$x] && $best[$x] < $best[$y] + 1) {
                    $best[$x] = $best[$y] + 1;
                    $previous[$x] = $y;
                }
            }
        }

        $end = (int) array_search(max($best), $best, true);
        $kept = [];

        for ($x = $end; $x !== -1; $x = $previous[$x]) {
            $kept[$laterIndices[$x]] = true;
        }

        return $kept;
    }

    /**
     * The later text with the words that differ marked: `<ins>` for added,
     * `<del>` for removed. Every word is escaped before it is wrapped.
     */
    private static function words(string $before, string $after): string
    {
        $split = fn (string $text): array => preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $diff = (new Differ(new DiffOnlyOutputBuilder))->diffToArray($split($before), $split($after));

        // Runs of words of the same kind, so a changed phrase is one <ins>.
        $runs = [];

        foreach ($diff as [$token, $type]) {
            if (! in_array($type, [Differ::OLD, Differ::ADDED, Differ::REMOVED], true)) {
                continue;
            }

            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][0] === $type) {
                $runs[$last][1] .= e((string) $token);
            } else {
                $runs[] = [$type, e((string) $token)];
            }
        }

        return implode('', array_map(fn (array $run): string => match ($run[0]) {
            Differ::ADDED => '<ins>'.$run[1].'</ins>',
            Differ::REMOVED => '<del>'.$run[1].'</del>',
            default => $run[1],
        }, $runs));
    }

    /**
     * A block's text for the comparison, escaped: prose as words, a card by
     * its label and its own words.
     *
     * @param  array<mixed>  $node
     */
    private function text(array $node): string
    {
        $card = $this->cards->find(self::type($node));

        if ($card === null) {
            return e(self::plain($node));
        }

        $words = trim($card->plainText((array) ($node['attrs'] ?? [])));

        return e($words === '' ? $card->label() : "{$card->label()}: {$words}");
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function plain(array $node): string
    {
        return PlainText::of(['type' => 'doc', 'content' => [$node]]);
    }

    /**
     * @param  array<mixed>  $document
     *
     * @return list<array<mixed>>
     */
    private static function blocks(array $document): array
    {
        return array_values(array_filter((array) ($document['content'] ?? []), 'is_array'));
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function type(array $node): string
    {
        return (string) ($node['type'] ?? '');
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function id(array $node): ?string
    {
        $id = $node['attrs']['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The node as compared: its `id` and those of the blocks inside it
     * aside, which say which block it is, not what it holds. A node left
     * with no attributes has none, as one that never had any (I-3362).
     *
     * @param  array<mixed>  $node
     *
     * @return array<mixed>
     */
    private static function withoutId(array $node): array
    {
        if (isset($node['attrs']) && is_array($node['attrs'])) {
            unset($node['attrs']['id']);

            if ($node['attrs'] === []) {
                unset($node['attrs']);
            }
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(fn (mixed $child): mixed => is_array($child) ? self::withoutId($child) : $child, $node['content']);
        }

        return $node;
    }
}
