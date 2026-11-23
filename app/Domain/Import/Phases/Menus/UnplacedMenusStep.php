<?php

/**
 * Says once how many menus this run brought: they come across unplaced,
 * since where menus sit belongs to the new site's theme, set up in Teme,
 * not copied from WordPress. Writes nothing.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Menus;

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use Illuminate\Support\Facades\DB;

final readonly class UnplacedMenusStep implements StepPhase
{
    public function key(): string
    {
        return 'unplaced-menus';
    }

    /** After the menus (800), and after the menu places an earlier release ran at 810, so a run that was there still says it. */
    public function order(): int
    {
        return 820;
    }

    public function label(): string
    {
        return 'Unplaced menus';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        $made = DB::table('import_map')
            ->where('source_id', $context->sourceId)
            ->where('run_id', $context->runId)
            ->where('object_type', 'menu')
            ->where('outcome', ImportOutcome::Created->value)
            ->whereNotNull('teme_id')
            ->count();

        if ($made === 0) {
            return StepResult::done();
        }

        return StepResult::done([new ImportMessage('menus.unplaced', $made === 1
            ? '1 menu came across; place it under Content › Navigation.'
            : number_format($made).' menus came across; place them under Content › Navigation.', ['count' => $made])]);
    }
}
