<?php

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Types\ContentType;

/**
 * The words of Select all matching (I-143): a run's label, its closing words,
 * what it has skipped so far and how far a failed one got, each naming the type.
 */
final readonly class BulkRunWords
{
    /** The actions a run takes, as the bar names them. */
    public const array ACTIONS = ['trash', 'restore', 'delete'];

    /** Skipped: the person may not change the item. */
    public const string NOT_ALLOWED = 'not-allowed';

    /** Skipped: the item no longer exists. */
    public const string GONE = 'gone';

    /** Skipped: the Operation refused the item in its own words. */
    public const string REFUSED = 'refused';

    /**
     * A run's name in Tools › Jobs, the header and the inbox: its action, the
     * list's tab, and whether a search or a filter narrows it.
     */
    public function label(ContentType $type, string $action, string $tab, string $tabLabel, bool $narrowed): string
    {
        $plural = mb_strtolower($type->plural);

        return match ($action) {
            'restore' => $narrowed
                ? __('Restore matching :plural from the trash', ['plural' => $plural])
                : __('Restore all :plural from the trash', ['plural' => $plural]),
            'delete' => $narrowed
                ? __('Delete matching :plural in the trash permanently', ['plural' => $plural])
                : __('Delete all :plural in the trash permanently', ['plural' => $plural]),
            default => match (true) {
                $narrowed => __('Move matching :plural to the trash', ['plural' => $plural]),
                $tab === 'all' => __('Move all :plural to the trash', ['plural' => $plural]),
                $tab === 'mine' => __('Move all your :plural to the trash', ['plural' => $plural]),
                default => __('Move all :plural in the :tab tab to the trash', ['plural' => $plural, 'tab' => $tabLabel]),
            },
        };
    }

    /**
     * A run's closing words: what was done, then what was skipped and why.
     *
     * @param  array<string, int>  $skipped  the count for each reason
     */
    public function closing(ContentType $type, string $action, int $changed, array $skipped, ?string $refusal): string
    {
        $plural = mb_strtolower($type->plural);
        $noun = mb_strtolower($changed === 1 ? $type->singular : $type->plural);
        $count = number_format($changed);

        // The bar's own toasts' words (I-143), and their none.
        $done = match (true) {
            $changed === 0 && $action === 'trash' => __('No :plural were moved to the trash.', ['plural' => $plural]),
            $changed === 0 && $action === 'restore' => __('No :plural were restored.', ['plural' => $plural]),
            $changed === 0 => __('No :plural were deleted.', ['plural' => $plural]),
            $action === 'trash' => __('Moved :count :noun to the trash.', ['count' => $count, 'noun' => $noun]),
            $action === 'restore' => __('Restored :count :noun.', ['count' => $count, 'noun' => $noun]),
            default => __('Deleted :count :noun permanently.', ['count' => $count, 'noun' => $noun]),
        };

        $notAllowed = (int) ($skipped[self::NOT_ALLOWED] ?? 0);
        $gone = (int) ($skipped[self::GONE] ?? 0);
        $refused = (int) ($skipped[self::REFUSED] ?? 0);
        $total = $notAllowed + $gone + $refused;

        if ($total === 0) {
            return $done;
        }

        $words = (string) $refusal;
        $all = number_format($total);

        // One reason: the count once. Several: each clause with its own.
        if (count(array_filter([$notAllowed, $gone, $refused])) === 1) {
            return $done.' '.match (true) {
                $notAllowed > 0 => __('Skipped :count you may not change.', ['count' => $all]),
                $gone > 0 => trans_choice('Skipped :count that no longer exists.|Skipped :count that no longer exist.', $total, ['count' => $all]),
                default => trans_choice('Skipped :count that was refused: :words|Skipped :count that were refused: :words', $total, ['count' => $all, 'words' => $words]),
            };
        }

        $clauses = array_values(array_filter([
            $notAllowed > 0 ? __(':count you may not change', ['count' => number_format($notAllowed)]) : null,
            $gone > 0 ? trans_choice(':count that no longer exists|:count that no longer exist', $gone, ['count' => number_format($gone)]) : null,
            $refused > 0 ? __(':count refused: :words', ['count' => number_format($refused), 'words' => $words]) : null,
        ]));
        $last = (string) array_pop($clauses);

        // A refusal's own words end the sentence with their own full stop.
        return $done.' '.__('Skipped :count: :clauses', ['count' => $all, 'clauses' => implode(', ', $clauses).' and '.$last]).($refused > 0 ? '' : '.');
    }

    /**
     * A step's message while the run goes on; null while nothing is skipped.
     */
    public function soFar(int $skipped): ?string
    {
        return $skipped === 0 ? null : __('Skipped :count so far.', ['count' => number_format($skipped)]);
    }

    /**
     * A failed run's words: how far it got before the server stopped it.
     */
    public function stopped(ContentType $type, int $handled): string
    {
        if ($handled === 0) {
            return __('Teme stopped before any :plural were handled, because of a problem on the server.', ['plural' => mb_strtolower($type->plural)]);
        }

        return __('Teme stopped after :count :noun because of a problem on the server.', [
            'count' => number_format($handled),
            'noun' => mb_strtolower($handled === 1 ? $type->singular : $type->plural),
        ]);
    }

    /**
     * What the list says when a run starts.
     */
    public function started(string $label): string
    {
        return __('Started: :label. It runs in the background, and your inbox tells you when it’s done.', ['label' => $label]);
    }
}
