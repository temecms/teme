<?php

declare(strict_types=1);

namespace App\Domain\Ai\Queries;

use App\Domain\Ai\AiTasks;
use Illuminate\Support\Facades\DB;

/**
 * What wrote the assistant's revisions on an article's History pane (I-1307):
 * the option's words or the task's, and, for those who may open AI activity,
 * the request's row. One statement on the index of revisions, and none for a
 * page with no assistant revision.
 */
final readonly class AssistantRevisions
{
    public function __construct(private AiTasks $tasks) {}

    /**
     * Returns, for every one of these revisions, what wrote it: the option's
     * words, else the task's label, in its key's words once the task has
     * gone, else null once the request has been pruned; and the request's
     * row ID, only when $linked. Every revision asked about has an entry,
     * whether or not its row is still in the record.
     *
     * @param  list<string>  $revisionIds
     *
     * @return array<string, array{label: string|null, row: string|null}>
     */
    public function among(array $revisionIds, bool $linked): array
    {
        $found = [];

        foreach ($revisionIds as $id) {
            $found[$id] = ['label' => null, 'row' => null];
        }

        if ($revisionIds === []) {
            return $found;
        }

        $labels = [];

        foreach ($this->tasks->all() as $task) {
            $labels[$task->key()] = $task->label();
        }

        foreach (DB::table('ai_activity')->whereIn('revision_id', $revisionIds)->get(['id', 'revision_id', 'task', 'variant']) as $row) {
            $task = (string) $row->task;

            $found[(string) $row->revision_id] = [
                'label' => $row->variant !== null ? AiActivityList::words((string) $row->variant) : ($labels[$task] ?? AiActivityList::words($task)),
                'row' => $linked ? (string) $row->id : null,
            ];
        }

        return $found;
    }
}
