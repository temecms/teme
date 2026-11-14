<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\Operations\Input\StartPageParentsJobInput;
use App\Domain\Import\Operations\StartPageParentsJob;
use App\Domain\Import\Queries\ImportedPageTree;
use App\Domain\Import\Queries\ImportSources;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * teme:import:page-parents, the operator's Bring back page parents for a WordPress site imported before (I-3363): starts the job that puts its pages back under their WordPress parents, at their WordPress addresses; a queue worker does the work, and this never waits for it.
 */
final class BringBackImportedPageParents extends Command
{
    /** @var string */
    protected $signature = 'teme:import:page-parents
        {address : The WordPress site’s address, as Tools › Import connected it}
        {--notes : List the pages that stayed where they are, and why}';

    /** @var string */
    protected $description = "Put an imported WordPress site's pages back under their parents, at their WordPress addresses";

    public function handle(ImportSources $sources, ImportedPageTree $tree): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $address = (string) $this->argument('address');
        $source = $sources->forAddress($address);

        if ($source === null) {
            $this->error("Teme doesn’t know {$address}. Connect it first, in Tools › Import or with php artisan teme:import {$address} --user=NAME.");

            return self::FAILURE;
        }

        if ((bool) $this->option('notes')) {
            $this->printNotes($tree, (string) $source->id);

            return self::SUCCESS;
        }

        try {
            app(StartPageParentsJob::class)->execute(new StartPageParentsJobInput((string) $source->id));
        } catch (ValidationException $refusal) {
            foreach (Arr::flatten($refusal->errors()) as $words) {
                $this->error((string) $words);
            }

            return self::FAILURE;
        }

        $this->info("Started bringing back the page parents of {$source->address}. A queue worker carries it out: Tools › Jobs shows how it's going.");

        return self::SUCCESS;
    }

    /**
     * Prints each page left where it was, with why, fifty at a time to the end.
     */
    private function printNotes(ImportedPageTree $tree, string $sourceId): void
    {
        $after = null;
        $any = false;

        do {
            $notes = $tree->notes($sourceId, $after);

            foreach ($notes as $note) {
                $this->line("{$note['title']}: {$note['note']}");
                $after = $note['object_id'];
                $any = true;
            }
        } while (count($notes) === 50);

        if (! $any) {
            $this->line('No page stayed where it was.');
        }
    }
}
