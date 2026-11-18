<?php

/**
 * Every old address of a category, tag or author the import brought that
 * Teme now gives another address (I-339): kept as an old address that leads
 * to it, by ID, from the companion's urls; one something else holds is said.
 * Items' old addresses are written as items are. Fifty seconds a job.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Addresses;

use App\Domain\Addresses\Operations\Input\KeepOldAddressesInput;
use App\Domain\Addresses\Operations\KeepOldAddresses;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\ClearRunMessages;
use App\Domain\Import\Operations\Input\ClearRunMessagesInput;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class OldAddressesStep implements StepPhase
{
    /** Seconds a job reads for before it hands on to the next. */
    public const int BUDGET = 50;

    /** The kinds of the old site's addresses this step keeps: a term's or an author's. */
    private const array KINDS = ['term', 'author'];

    /** The targets whose rows an old address may point at, and their tables. */
    private const array TABLES = ['term' => 'terms', 'user' => 'users'];

    public function __construct(private OldSiteUrls $urls) {}

    public function key(): string
    {
        return 'old-addresses';
    }

    public function order(): int
    {
        return 350;
    }

    public function label(): string
    {
        return 'Old addresses';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        if ($cursor === null) {
            app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($context->runId, 'old-address.', $context->actorId()));
        }

        $host = OldSiteUrls::hostOf($context->sourceId);
        $started = microtime(true);
        $messages = [];
        $position = $cursor;

        do {
            $page = $this->urls->page($reader, self::KINDS, $position);
            $messages = [...$messages, ...$this->keep($page['kind'], $page['items'], $context, $host)];
            $position = $page['cursor'];
        } while ($position !== null && microtime(true) - $started < self::BUDGET);

        return $position === null ? StepResult::done($messages) : StepResult::continueAt($position, $messages);
    }

    /**
     * Keeps this page's old addresses whose object Teme now gives another;
     * what holds the rest, said. Three queries a page, and one write.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @return list<ImportMessage>
     */
    private function keep(string $kind, array $items, ImportContext $context, string $host): array
    {
        $old = [];

        foreach ($items as $item) {
            $own = OldSiteUrls::own((string) ($item['url'] ?? ''), $host);

            if ($own === null || $own['query'] !== '') {
                continue;
            }

            $path = PublicRequestPath::normalise($own['path']);

            // An address in words is cut short: a message holds at most 1,000 characters.
            if ($path !== '' && ! Paths::taken(explode('/', $path)[0])) {
                $old[] = ['object' => (string) (int) ($item['id'] ?? 0), 'path' => $path, 'shown' => Str::limit($own['path'], 300)];
            }
        }

        if ($old === []) {
            return [];
        }

        $targets = $this->targets($context->sourceId, $kind === 'author' ? 'user' : $kind, array_column($old, 'object'));
        // Keyed apart from PHP's integer keys: a path may be digits, /2024/.
        $held = [];

        foreach (DB::table('paths')->whereIn('path', array_column($old, 'path'))->get(['path', 'is_current', 'target_type', 'target_id']) as $row) {
            $held['#'.$row->path] = ['current' => (bool) $row->is_current, 'type' => (string) $row->target_type, 'id' => (string) $row->target_id];
        }

        $keep = [];
        $messages = [];

        foreach ($old as $entry) {
            $target = $targets['#'.$entry['object']] ?? null;
            $holder = $held['#'.$entry['path']] ?? null;

            // Not brought, or deleted here since: the check lists its address.
            if ($target === null) {
                continue;
            }

            if ($holder === null) {
                $keep['#'.$entry['path']] = ['path' => $entry['path'], 'type' => $target['type'], 'id' => $target['id'], 'shown' => $entry['shown']];
            } elseif ($holder['type'] !== $target['type'] || $holder['id'] !== $target['id']) {
                $messages[] = self::held($kind, $entry['shown'], $target, ['current' => $holder['current'], 'type' => $holder['type'], 'id' => $holder['id']]);
            }
        }

        if ($keep === []) {
            return $messages;
        }

        $result = app(KeepOldAddresses::class)->execute(new KeepOldAddressesInput(
            array_values(array_map(fn (array $one): array => ['path' => $one['path'], 'type' => $one['type'], 'id' => $one['id']], $keep)),
            $context->actorId(),
        ));

        // Taken between this job's read and its write.
        foreach ($result['held'] as $holder) {
            $one = $keep['#'.$holder['path']];
            $messages[] = self::held($kind, $one['shown'], ['type' => $one['type'], 'id' => $one['id']], ['current' => $holder['current'], 'type' => $holder['type'], 'id' => $holder['id']]);
        }

        return $messages;
    }

    /**
     * The Teme objects this source made of these WordPress objects, those
     * still here, by WordPress ID.
     *
     * @param  list<string>  $objects
     *
     * @return array<string, array{type: string, id: string}>
     */
    private function targets(string $sourceId, string $objectType, array $objects): array
    {
        $rows = DB::table('import_map')->where('source_id', $sourceId)->where('object_type', $objectType)
            ->whereIn('object_id', $objects)->whereNotNull('teme_id')->get(['object_id', 'teme_type', 'teme_id']);
        $targets = [];

        foreach (self::TABLES as $type => $table) {
            $ids = $rows->where('teme_type', $type)->pluck('teme_id')->map(fn (mixed $id): string => (string) $id)->all();
            $here = $ids === [] ? [] : DB::table($table)->whereIn('id', $ids)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

            foreach ($rows->where('teme_type', $type) as $row) {
                if (in_array((string) $row->teme_id, $here, true)) {
                    $targets['#'.$row->object_id] = ['type' => $type, 'id' => (string) $row->teme_id];
                }
            }
        }

        return $targets;
    }

    /**
     * An old address something else holds: its live address (old links
     * reach the wrong page), or its old one (it leads there).
     *
     * @param  array{type: string, id: string}  $target
     * @param  array{current: bool, type: string, id: string}  $holder
     */
    private static function held(string $kind, string $shown, array $target, array $holder): ImportMessage
    {
        $was = Paths::describe($target['type'], $target['id']);
        $is = Paths::describe($holder['type'], $holder['id']);
        $context = ['address' => mb_substr($shown, 0, 500), 'kind' => $kind];

        return $holder['current']
            ? ImportMessage::error('old-address.taken', "{$shown} led to {$was} on the old site, but here it belongs to {$is}, so old links to it reach the wrong page. Change one of the two addresses.", $context)
            : new ImportMessage('old-address.held', "{$shown} led to {$was} on the old site, but Teme already keeps it as an old address of {$is}, so it leads there.", $context);
    }
}
