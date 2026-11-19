<?php

/**
 * Says what the old site's widgets were (I-343), since none is imported:
 * how many each sidebar held, and of which kinds, from the companion's
 * site report, so the theme's regions can be set up again; mapping them
 * to regions is parked. Once a full run; writes nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Menus;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use Closure;

final readonly class WidgetsStep implements StepPhase
{
    /** WordPress's holder for widgets no sidebar shows, and its kinds' prefix in the report. */
    private const string INACTIVE = 'wp_inactive_widgets';

    private const string INACTIVE_KIND = 'inactive:';

    /** How many sidebars, and kinds, the message names. */
    private const int LISTED = 8;

    public function key(): string
    {
        return 'widgets';
    }

    public function order(): int
    {
        return 890;
    }

    public function label(): string
    {
        return 'Widgets';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return $kind === ImportRunKind::Full;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        $report = $reader->whole('analyze')['report'] ?? null;
        $sections = is_array($report) && is_array($report['sections'] ?? null) ? $report['sections'] : [];
        $widgets = is_array($sections['widgets'] ?? null) ? $sections['widgets'] : [];
        $sidebars = self::counted($widgets['sidebars'] ?? null, fn (string $name): bool => $name !== self::INACTIVE);
        $kinds = self::counted($widgets['widget_types'] ?? null, fn (string $name): bool => ! str_starts_with($name, self::INACTIVE_KIND));

        if ($sidebars === []) {
            return StepResult::done();
        }

        $count = 0;

        foreach ($sidebars as $held) {
            $count += $held;
        }

        $words = ($count === 1 ? '1 widget wasn’t imported' : "{$count} widgets weren’t imported")
            .', since Teme’s theme lays out regions of its own: set those up instead. By sidebar: '.self::listed($sidebars).'.'
            .($kinds === [] ? '' : ' By kind: '.self::listed($kinds).'.');

        return StepResult::done([new ImportMessage('widgets.reported', $words, [
            'count' => $count,
            'sidebars' => array_map(strval(...), array_slice(array_keys($sidebars), 0, self::LISTED)),
            'sidebarCounts' => array_slice(array_values($sidebars), 0, self::LISTED),
            'kinds' => array_map(strval(...), array_slice(array_keys($kinds), 0, self::LISTED)),
            'kindCounts' => array_slice(array_values($kinds), 0, self::LISTED),
        ])]);
    }

    /**
     * The names $keep takes, each with its count above 0, in the report's
     * order, cut to 40 characters.
     *
     * @param  Closure(string): bool  $keep
     *
     * @return array<int|string, int>
     */
    private static function counted(mixed $counts, Closure $keep): array
    {
        $kept = [];

        foreach (is_array($counts) ? $counts : [] as $name => $count) {
            if (is_numeric($count) && (int) $count > 0 && $keep((string) $name)) {
                $short = mb_substr((string) $name, 0, 40);
                $kept[$short] = ($kept[$short] ?? 0) + (int) $count;
            }
        }

        return $kept;
    }

    /**
     * "name (n)" for the first eight, joined as a sentence lists them, then how many more.
     *
     * @param  array<int|string, int>  $counts
     */
    private static function listed(array $counts): string
    {
        $parts = [];

        foreach (array_slice($counts, 0, self::LISTED, true) as $name => $count) {
            $parts[] = "{$name} ({$count})";
        }

        $more = count($counts) - self::LISTED;

        return WordPressMenus::joined($more > 0 ? [...$parts, "{$more} more"] : $parts);
    }
}
