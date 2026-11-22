<?php

/**
 * At the end of an import from a newer export file, lists what an earlier
 * import brought that the new file no longer holds: never deleted here,
 * since a file may leave things out for its own reasons.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Finish;

use App\Domain\Import\File\ItemRows;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\ClearRunMessages;
use App\Domain\Import\Operations\Input\ClearRunMessagesInput;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use Illuminate\Support\Facades\DB;

/**
 * A run of a file compares the site's map with the file's rows: each post,
 * page, media file and comment in the map that the file doesn't hold gets a
 * line in the report. A run of the companion has its own step for what the
 * old site deleted (DeletedStep).
 */
final readonly class FileMissingStep implements StepPhase
{
    /** Objects a page. */
    public const int PAGE = 500;

    /** Each kind of object the map keeps that a file holds, and its words. */
    private const array KINDS = [
        ['post', 'Post or page'],
        ['attachment', 'Media file'],
        ['comment', 'Comment'],
    ];

    public function key(): string
    {
        return 'file-missing';
    }

    public function order(): int
    {
        // Beside the companion's deleted step (1250), which a file's run passes over.
        return 1260;
    }

    public function label(): string
    {
        return 'Missing from the file';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return $kind === ImportRunKind::Full;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        // Only a run of an export file compares with its file.
        if (! $context->readsFile()) {
            return StepResult::done();
        }

        if ($cursor === null) {
            app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($context->runId, 'file-missing.', $context->actorId()));
        }

        $after = (int) ($cursor ?? 0);
        $rows = DB::select(<<<'SQL'
            SELECT m.id, m.object_type, m.object_id
            FROM import_map m
            WHERE m.source_id = ? AND m.object_type IN ('post', 'attachment', 'comment') AND m.teme_id IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM import_file_rows r
                    WHERE r.file_id = ?
                        AND r.list = CASE m.object_type WHEN 'post' THEN ? WHEN 'attachment' THEN ? ELSE ? END
                        AND r.object_id::text = m.object_id
                )
            ORDER BY m.id
            OFFSET ? LIMIT ?
            SQL, [$context->sourceId, $context->fileId, ItemRows::CONTENT, ItemRows::MEDIA, ItemRows::COMMENTS, $after, self::PAGE]);

        $messages = [];

        foreach ($rows as $row) {
            $words = array_column(self::KINDS, 1, 0)[(string) $row->object_type];
            $messages[] = new ImportMessage(
                'file-missing.item',
                "In the last file, not in this one: {$words} {$row->object_id} on WordPress. It stays here as it is.",
                ['type' => (string) $row->object_type, 'id' => (string) $row->object_id],
            );
        }

        return count($rows) < self::PAGE ? StepResult::done($messages) : StepResult::continueAt((string) ($after + self::PAGE), $messages);
    }
}
