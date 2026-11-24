<?php

/**
 * Before anything is written (I-1706): each address of the old site's
 * posts, pages, categories and tags that Teme already gives something this
 * import didn't make, listed. As WordPress's importer does, the run carries
 * on: a category or tag files its posts under the one Teme has at its
 * address, and a post or page takes the next free one, each a warning. A
 * category or tag whose address something else holds stops the run, since
 * its address follows its base. Read a page at a time from the companion's
 * urls, fifty seconds a job.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Addresses;

use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Import\ImportMessageLevel;
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

    private const string ONE = 'A category or tag of the old site has an address something else in Teme already has, which Teme can’t move it from, so the import stopped before writing anything. The report names it: rename or delete what Teme has there, then carry on the import.';

    private const string MORE = '%s categories and tags of the old site have addresses something else in Teme already has, which Teme can’t move them from, so the import stopped before writing anything. The report lists them: rename or delete what Teme has at each, then carry on the import.';

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

        $found += count(array_filter($messages, fn (ImportMessage $message): bool => $message->level === ImportMessageLevel::Error));

        if ($position !== null) {
            return StepResult::continueAt("{$found}|{$position}", $messages);
        }

        if ($found === 0) {
            return StepResult::done($messages);
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
     * didn't make, the same category or tag at its own address aside: a
     * warning for a post or page, an error for a category or tag. One query
     * for the page.
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
                $taxonomy = $kind === 'term' ? WordPressAddresses::temeTaxonomy((string) ($item['taxonomy'] ?? ''))?->key : null;
                $wanted[] = ['path' => $path, 'shown' => Str::limit($own['path'], 300), 'what' => (string) $what, 'id' => (int) ($item['id'] ?? 0), 'taxonomy' => $taxonomy];
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

        $taxonomies = DB::table('terms')->whereIn('id', $held->where('target_type', 'term')->pluck('target_id')->all())->pluck('taxonomy', 'id');

        foreach ($held as $row) {
            $holders['#'.$row->path] = [
                'what' => Paths::describe((string) $row->target_type, (string) $row->target_id),
                'taxonomy' => $row->target_type === 'term' ? (string) ($taxonomies[$row->target_id] ?? '') : null,
            ];
        }

        $messages = [];

        foreach ($wanted as $entry) {
            $holder = $holders['#'.$entry['path']] ?? null;

            // A term of the same taxonomy at a term's address is that category or tag, which the terms phase files posts under and reports.
            if ($holder === null || ($entry['taxonomy'] !== null && $holder['taxonomy'] === $entry['taxonomy'])) {
                continue;
            }

            $context = ['address' => mb_substr($entry['shown'], 0, 500), 'kind' => $kind, 'id' => $entry['id']];

            // A post, page or person takes the next free address; a term's address follows its base, so another holder stops the run.
            $messages[] = $kind === 'term'
                ? ImportMessage::error('clash.address', "{$entry['shown']} is {$holder['what']} in Teme, and the old site has {$entry['what']} there.", $context)
                : new ImportMessage('clash.address', "{$entry['shown']} is {$holder['what']} in Teme, so {$entry['what']} from the old site gets another address.", $context);
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
