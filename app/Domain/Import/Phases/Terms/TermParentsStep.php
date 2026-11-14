<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Terms;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\ImportTerm;
use App\Domain\Taxonomy\Operations\Input\ImportTermInput;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Places each category WordPress lists before its parent (I-397): once
 * every term of the run is here, a term the terms phase left at the top is
 * moved under its parent, two hundred at a time. Reads nothing from the
 * old site; a run carried on runs it again from the start.
 */
final readonly class TermParentsStep implements StepPhase
{
    public const int PAGE = 200;

    public function __construct(private ImportedTerms $terms) {}

    public function key(): string
    {
        return 'term-parents';
    }

    public function order(): int
    {
        return 310;
    }

    public function label(): string
    {
        return 'Category parents';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        $rows = $this->terms->withParents($context->sourceId, (int) $cursor, self::PAGE);
        $messages = [];

        foreach ($rows as $row) {
            $term = Term::query()->find($row['termId']);

            if ($term === null) {
                continue;
            }

            $parentId = $this->terms->byWordPressTerm($context->sourceId, $row['taxonomy'], $row['parent']);

            if ($parentId === null) {
                if ($term->parent_id === null) {
                    $messages[] = new ImportMessage('term.parent-missing', "“{$term->name}” sits under a category that wasn’t imported, so it’s at the top level here.", ['term' => $row['objectId']]);
                }

                continue;
            }

            if ($term->parent_id === $parentId) {
                continue;
            }

            try {
                app(ImportTerm::class)->execute(new ImportTermInput($term->taxonomy, $term->name, $term->slug, $term->description, $parentId, (string) $term->id, $context->actorId()));
            } catch (ValidationException $refused) {
                $reason = (string) (Arr::first(Arr::flatten($refused->errors())) ?? '');
                $messages[] = new ImportMessage('term.parent-refused', "“{$term->name}” couldn’t be placed under its WordPress parent: {$reason}", ['term' => $row['objectId']]);
            }
        }

        return count($rows) < self::PAGE
            ? StepResult::done($messages)
            : StepResult::continueAt((string) $rows[count($rows) - 1]['objectId'], $messages);
    }
}
