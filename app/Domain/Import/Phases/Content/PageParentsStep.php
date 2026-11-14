<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Content;

use App\Domain\Contents\Tree\SubtreeWords;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\Input\PlaceImportedPageInput;
use App\Domain\Import\Operations\PlaceImportedPage;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\PlacedPage;
use App\Domain\Import\Queries\ImportedPageTree;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Import\Queries\ImportSources;
use App\Domain\Import\Source\SourceReader;

/**
 * Puts the run's pages back under their WordPress parents (I-3363), two
 * hundred at a time, parents first, each through PlaceImportedPage, at its
 * WordPress address. On an old site's catch-up, whose tree was never
 * placed, it does nothing and says so once. Reads nothing from the old site.
 */
final readonly class PageParentsStep implements StepPhase
{
    public const int PAGE = 200;

    public function __construct(private ImportedPageTree $tree, private ImportRuns $runs, private ImportSources $sources) {}

    public function key(): string
    {
        return 'page-parents';
    }

    public function order(): int
    {
        return 510;
    }

    public function label(): string
    {
        return 'Page parents';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        // Decided at the first call: a site's first import, or one whose tree was placed before.
        if ($cursor === null && $this->runs->lastFinished($context->sourceId) !== null && ! $this->tree->placedBefore($context->sourceId)) {
            $site = (string) $this->sources->find($context->sourceId)?->address;

            return StepResult::done([new ImportMessage('page.parents-later', "Page parents weren't brought back for this site yet, so pages stay where they are. Run php artisan teme:import:page-parents {$site} when you're ready.", ['site' => $site])]);
        }

        [$depth, $after] = $cursor === null ? [0, 0] : array_map(intval(...), explode(':', $cursor, 2) + [1 => '0']);
        $rows = $this->tree->batch($context->sourceId, $depth, $after, self::PAGE);
        $messages = [];

        foreach ($rows as $row) {
            $placed = app(PlaceImportedPage::class)->execute(new PlaceImportedPageInput($context->sourceId, $row['object_id'], $row['rank'], $context->actorId()));
            $messages = [...$messages, ...self::said($placed, $row['object_id'])];
        }

        if (count($rows) < self::PAGE) {
            return StepResult::done($messages);
        }

        $last = $rows[count($rows) - 1];

        return StepResult::continueAt($last['depth'].':'.$last['object_id'], $messages);
    }

    /**
     * What the run says of one page: why it stayed where it was, or a note
     * on where it went, and each page under it that kept its address.
     *
     * @return list<ImportMessage>
     */
    private static function said(PlacedPage $placed, string $objectId): array
    {
        $messages = [];

        if ($placed->status !== PlacedPage::UNCHANGED && $placed->code !== null && $placed->note !== null) {
            $messages[] = new ImportMessage($placed->code, $placed->note, ['wordpress_id' => $objectId]);
        }

        foreach ($placed->kept as $kept) {
            $messages[] = new ImportMessage('page.kept-address', app(SubtreeWords::class)->kept($kept['title'], $kept['path'], $kept['address'], $kept['holder']), ['wordpress_id' => $objectId]);
        }

        return $messages;
    }
}
