<?php

/**
 * The second pass for one imported post or page (I-333): its finished
 * words written through the content domain, and its map row's record of
 * them, in one transaction under the object's lock, or only what it says
 * when there's nothing to write. Only an import's actor may call it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\CompleteImportedContent;
use App\Domain\Contents\Operations\Input\CompleteImportedContentInput;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Concerns\LocksObjects;
use App\Domain\Import\Operations\Concerns\NotesObjects;
use App\Domain\Import\Operations\Input\RecordSecondPassInput;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

final class RecordSecondPass extends BaseOperation
{
    use AuthorizesImports;
    use LocksObjects;
    use NotesObjects;

    public const string WROTE = 'wrote';

    public const string UNCHANGED = 'unchanged';

    public const string SAID = 'said';

    public const string CHANGED_HERE = 'changed-here';

    public const string STALE = 'stale';

    /** The codes this Operation keeps on a row: the second pass's own. */
    private const string PREFIX = 'second-pass.';

    /** The map's object type for a WordPress post or page (I-332). */
    private const string OBJECT = 'post';

    public function __construct(private readonly ImportMap $map) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordSecondPassInput $input */
        if (! Str::isUlid($input->runId) || preg_match('/^[1-9]\d{0,17}$/', $input->objectId) !== 1 || preg_match('/^[0-9a-f]{40}$/', $input->written) !== 1) {
            throw new InvalidArgumentException('Recording the second pass needs the run, a post’s WordPress ID and the import’s record of it.');
        }

        foreach ($input->messages as $message) {
            if (! str_starts_with($message->code, self::PREFIX)) {
                throw new InvalidArgumentException('The second pass records only its own messages.');
            }
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var RecordSecondPassInput $input */
        $run = $this->running($input->runId);

        if ($run === null) {
            return self::STALE;
        }

        $ref = SourceRef::of(self::OBJECT, $input->objectId);
        $this->lockObject($run->source_id, $ref);
        $entry = $this->map->entry($run->source_id, $ref);

        // Read again under the lock: a carried-on run's content phase may have written it since the step read it.
        if ($entry === null || $entry->teme_id === null || ($entry->facts['written'] ?? null) !== $input->written) {
            return self::STALE;
        }

        $contentId = (string) $entry->teme_id;

        if ($input->document === null) {
            if ($input->passed !== null) {
                $working = (string) Content::withTrashed()->whereKey($contentId)->value('working_revision_id');
                $entry->forceFill(['facts' => [...$entry->facts, 'passed' => [...self::passed($input->passed), 'to' => $working]]])->save();
            }

            $this->noteMessages($entry, $run->id, self::PREFIX, $input->messages);

            return self::SAID;
        }

        $done = app(CompleteImportedContent::class)->execute(new CompleteImportedContentInput($contentId, $input->written, $input->document, $input->fields ?? [], $input->actorId()));

        if ($done['changedHere']) {
            return self::CHANGED_HERE;
        }

        $mine = (string) $entry->run_id === $run->id;
        $changes = ['facts' => [...$entry->facts, 'written' => $done['written'], 'passed' => [...self::passed($input->passed ?? []), 'to' => $done['revisionId']]]];

        // Written in this run, so the report lists it here (I-345). Its status is never written:
        // a row an earlier run left failed or needing an update keeps that, and its run, to be tried again.
        if ($done['wrote'] && ! $mine && $entry->status === ImportStatus::Imported) {
            $changes += ['run_id' => $run->id, 'outcome' => ImportOutcome::Updated, 'imported_at' => now()];
        }

        $entry->forceFill($changes)->save();

        if ($done['wrote'] || $mine) {
            $this->noteMessages($entry, $run->id, self::PREFIX, $input->messages);
        }

        return $done['wrote'] ? self::WROTE : self::UNCHANGED;
    }

    /**
     * @param  array<string, mixed>  $passed
     *
     * @return array{from: string, open: bool, blocks: list<string>}
     */
    private static function passed(array $passed): array
    {
        $blocks = array_values(array_filter((array) ($passed['blocks'] ?? []), is_string(...)));

        return ['from' => (string) ($passed['from'] ?? ''), 'open' => (bool) ($passed['open'] ?? false), 'blocks' => $blocks];
    }
}
