<?php

/**
 * Before anything is written (I-1706): each address of the old site's
 * posts, pages, categories and tags that Teme already gives something this
 * import didn't make, listed, and the run stopped when there is any. Read a
 * page at a time from the companion's urls, fifty seconds a job.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Addresses;

use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\ClearRunMessages;
use App\Domain\Import\Operations\Input\ClearRunMessagesInput;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportStopped;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AddressClashStep implements StepPhase
{
    /** Seconds a job reads for before it hands on to the next. */
    public const int BUDGET = 50;

    /** The kinds of the old site's addresses an import writes paths for. */
    private const array KINDS = ['post', 'term'];

    private const string ONE = 'One address of the old site already belongs to something else in Teme, so the import stopped before writing anything. The report names it: rename or delete what Teme has there, then carry on the import.';

    private const string MORE = '%s addresses of the old site already belong to something else in Teme, so the import stopped before writing anything. The report lists them: rename or delete what Teme has at each, then carry on the import.';

    public function __construct(private OldSiteUrls $urls, private ContentTypeRegistry $types) {}

    public function key(): string
    {
        return 'address-clash';
    }

    public function order(): int
    {
        return 50;
    }

    public function label(): string
    {
        return 'Addresses both sites claim';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        if ($cursor === null) {
            app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($context->runId, 'clash.', $context->actorId()));
        }

        [$found, $position] = self::position($cursor);
        $host = OldSiteUrls::hostOf($context->sourceId);
        $started = microtime(true);
        $messages = [];

        do {
            $page = $this->urls->page($reader, self::KINDS, $position);
            $messages = [...$messages, ...$this->clashes($page['kind'], $page['items'], $context->sourceId, $host)];
            $position = $page['cursor'];
        } while ($position !== null && microtime(true) - $started < self::BUDGET);

        $found += count($messages);

        if ($position !== null) {
            return StepResult::continueAt("{$found}|{$position}", $messages);
        }

        if ($found === 0) {
            return StepResult::done();
        }

        throw ImportStopped::because('address-clash', $found === 1 ? self::ONE : sprintf(self::MORE, number_format($found)), $messages);
    }

    /**
     * How many clashes earlier jobs found, and where the list stands.
     *
     * @return array{0: int, 1: string|null}
     */
    private static function position(?string $cursor): array
    {
        if ($cursor === null || preg_match('/^(\d{1,9})\|(.+)$/', $cursor, $match) !== 1) {
            return [0, null];
        }

        return [(int) $match[1], $match[2]];
    }

    /**
     * This page's addresses that Teme already gives something this source
     * didn't make, as error-level messages: one query for the page.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @return list<ImportMessage>
     */
    private function clashes(string $kind, array $items, string $sourceId, string $host): array
    {
        $wanted = [];

        foreach ($items as $item) {
            $what = $this->what($kind, $item);
            $own = $what === null ? null : OldSiteUrls::own((string) ($item['url'] ?? ''), $host);

            if ($own === null || $own['query'] !== '') {
                continue;
            }

            $path = PublicRequestPath::normalise($own['path']);

            // An address in words is cut short: a message holds at most 1,000 characters.
            if ($path !== '') {
                $wanted[] = ['path' => $path, 'shown' => Str::limit($own['path'], 300), 'what' => (string) $what, 'id' => (int) ($item['id'] ?? 0)];
            }
        }

        if ($wanted === []) {
            return [];
        }

        $held = DB::table('paths')
            ->where('is_current', true)
            ->whereIn('path', array_column($wanted, 'path'))
            ->whereNotExists(fn (Builder $query): Builder => $query->select(DB::raw(1))->from('import_map')
                ->where('import_map.source_id', $sourceId)
                ->whereColumn('import_map.teme_type', 'paths.target_type')
                ->whereColumn('import_map.teme_id', 'paths.target_id'))
            ->get(['path', 'target_type', 'target_id']);

        // Keyed apart from PHP's integer keys: a path may be digits, /2024/.
        $holders = [];

        foreach ($held as $row) {
            $holders['#'.$row->path] = Paths::describe((string) $row->target_type, (string) $row->target_id);
        }

        $messages = [];

        foreach ($wanted as $entry) {
            $holder = $holders['#'.$entry['path']] ?? null;

            if ($holder !== null) {
                $messages[] = ImportMessage::error('clash.address', "{$entry['shown']} is {$holder} here, and the old site has {$entry['what']} there.", [
                    'address' => mb_substr($entry['shown'], 0, 500),
                    'kind' => $kind,
                    'id' => $entry['id'],
                ]);
            }
        }

        return $messages;
    }

    /**
     * What the address was on the old site, when the import brings it: "a
     * post", "the category"; null for anything else.
     *
     * @param  array<string, mixed>  $item
     */
    private function what(string $kind, array $item): ?string
    {
        if ($kind === 'term') {
            $taxonomy = WordPressAddresses::temeTaxonomy((string) ($item['taxonomy'] ?? ''));

            return $taxonomy === null ? null : 'the '.mb_strtolower($taxonomy->singular);
        }

        $type = WordPressAddresses::temeType((string) ($item['type'] ?? ''));

        if ($type === null) {
            return null;
        }

        $noun = mb_strtolower($this->types->get($type)->singular);

        return (preg_match('/^[aeiou]/', $noun) === 1 ? 'an ' : 'a ').$noun;
    }
}
