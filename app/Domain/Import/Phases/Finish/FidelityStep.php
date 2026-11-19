<?php

/**
 * The fidelity check (I-337): each post and page whose words changed since
 * it was last compared, and is as the import left it, read beside
 * WordPress's rendered page; more than 2% of its words lost is said on the
 * item, with where the missing text begins. Only the pages that hold an
 * item to check are read. Fifty seconds a job.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Finish;

use App\Domain\Cards\DocumentRenderer;
use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\Input\RecordFidelityInput;
use App\Domain\Import\Operations\RecordFidelity;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Facades\DB;

final readonly class FidelityStep implements StepPhase
{
    /** Seconds a job works before it hands on to the next. */
    public const int BUDGET = 50;

    /** Rows read at once. */
    public const int BATCH = 100;

    /** The companion's rendered pages hold twenty items at most. */
    public const int PAGE = 20;

    /** Every status the content phase imports a post or page from but the trash. */
    public const string STATUSES = 'publish,future,draft,pending,private';

    /** The map's object type for a WordPress post or page (I-332). */
    private const string OBJECT = 'post';

    public function __construct(private ContentTypeRegistry $types, private DocumentRenderer $renderer) {}

    public function key(): string
    {
        return 'fidelity';
    }

    public function order(): int
    {
        return 1200;
    }

    public function label(): string
    {
        return 'Compared with WordPress';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        $started = microtime(true);
        $reads = 0;
        $position = $cursor ?? '';
        $query = ['types' => implode(',', $this->wordpressTypes()), 'statuses' => self::STATUSES, 'include' => 'rendered'];

        do {
            $due = self::due($context->sourceId, $position);

            // A batch cut short is read again from the same place: what it checked is no longer due.
            if (! $this->check($due, $context, $reader, $query, $started, $reads)) {
                return StepResult::continueAt($position === '' ? '0' : $position);
            }

            if ($due !== []) {
                $position = (string) $due[count($due) - 1]->object_id;
            }
        } while (count($due) === self::BATCH && microtime(true) - $started < self::BUDGET);

        return count($due) < self::BATCH ? StepResult::done() : StepResult::continueAt($position);
    }

    /**
     * The next rows after $after whose item, out of the trash, has a
     * working revision other than the one last compared.
     *
     * @return list<object>
     */
    private static function due(string $sourceId, string $after): array
    {
        return DB::table('import_map as m')
            ->join('contents as c', 'c.id', '=', 'm.teme_id')
            ->where('m.source_id', $sourceId)->where('m.object_type', self::OBJECT)->where('m.object_id', '>', $after)
            ->where('m.teme_type', 'content')->whereNull('c.deleted_at')
            ->whereRaw("c.working_revision_id::text IS DISTINCT FROM (m.facts->>'checked')")
            ->orderBy('m.object_id')->limit(self::BATCH)
            ->get(['m.object_id', 'm.teme_id', 'm.facts'])->all();
    }

    /**
     * Compares these rows' items with WordPress's rendered pages, read by
     * keyset jumps in WordPress's ID order; whether the batch was done
     * before the job's time ran out. $reads counts the job's reads.
     *
     * @param  list<object>  $due
     * @param  array<string, string>  $query
     */
    private function check(array $due, ImportContext $context, SourceReader $reader, array $query, float $started, int &$reads): bool
    {
        $waiting = [];

        foreach ($due as $row) {
            $content = Content::query()->find((string) $row->teme_id);
            $facts = (array) json_decode((string) $row->facts, true);

            // A person's save here is theirs, never compared (I-332).
            if ($content !== null && ImportedContents::fingerprint($content) === ($facts['written'] ?? null)) {
                $waiting[(int) $row->object_id] = $content;
            }
        }

        ksort($waiting);

        while ($waiting !== []) {
            // A read must end inside the job: the client waits 45 seconds for an answer, the job 75.
            if ($reads > 0 && ! self::inTime($started, $context)) {
                return false;
            }

            if ($reads > 0 && $context->pauseMs > 0) {
                usleep(min($context->pauseMs, 5000) * 1000);
            }

            $page = $reader->page('content', $query, array_key_first($waiting) - 1, self::PAGE);
            $reads++;

            foreach ($page->items as $item) {
                $id = (int) ($item['id'] ?? 0);

                if (isset($waiting[$id]) && is_string($item['rendered'] ?? null)) {
                    $this->compare($waiting[$id], $id, $item['rendered'], $context);
                    unset($waiting[$id]);
                }
            }

            // Up to the page's last item, anything the page didn't hold isn't on WordPress's list.
            $last = $page->nextAfterId ?? PHP_INT_MAX;

            foreach ($waiting as $id => $content) {
                if ($id > $last) {
                    break;
                }

                $this->record($context, $id, (string) $content->working_revision_id, null, null);
                unset($waiting[$id]);
            }
        }

        return true;
    }

    /**
     * Whether a read started now, after its pause, ends inside the job:
     * elapsed and pause below BUDGET less the 25 seconds an answer may take
     * beyond it.
     */
    private static function inTime(float $started, ImportContext $context): bool
    {
        return microtime(true) - $started + min($context->pauseMs, 5000) / 1000 < self::BUDGET - 25;
    }

    private function compare(Content $content, int $id, string $rendered, ImportContext $context): void
    {
        $document = ContentRevision::query()->findOrFail($content->working_revision_id)->document;
        $fidelity = Fidelity::compare($rendered, $this->renderer->html($document));

        $this->record($context, $id, (string) $content->working_revision_id, $fidelity->total === 0 ? null : $fidelity->share, $fidelity->flagged() ? self::lost($fidelity) : null);
    }

    /**
     * Hands one item's comparison to RecordFidelity for the run.
     */
    private function record(ImportContext $context, int $id, string $checked, ?float $lost, ?ImportMessage $message): void
    {
        app(RecordFidelity::class)->execute(new RecordFidelityInput($context->runId, (string) $id, $checked, $lost, $message, $context->actorId()));
    }

    /**
     * The WordPress names of the listed types that have one, without the
     * reusable block.
     *
     * @return list<string>
     */
    private function wordpressTypes(): array
    {
        $types = [];

        foreach ($this->types->all() as $type) {
            if ($type->listed && $type->wordpress !== null) {
                $types[] = $type->wordpress;
            }
        }

        return $types;
    }

    /**
     * The warning for an item that lost more than it may: the share, the
     * counts and where the missing text begins; its context holds numbers
     * only, never the old site's words.
     */
    private static function lost(Fidelity $fidelity): ImportMessage
    {
        $share = number_format($fidelity->share, 1);
        $counts = "{$share}% of its words on WordPress aren’t here (".number_format($fidelity->lost).' of '.number_format($fidelity->total).')';
        $begins = $fidelity->passages === [] ? '' : ' Missing text begins: '.implode('; ', array_map(fn (string $passage): string => "“{$passage}”", $fidelity->passages)).'.';

        return new ImportMessage(
            'fidelity.lost',
            "About {$counts}.{$begins} Compare it with the old page and add what’s missing.",
            ['share' => $fidelity->share, 'lost' => $fidelity->lost, 'total' => $fidelity->total, 'passages' => count($fidelity->passages)],
        );
    }
}
