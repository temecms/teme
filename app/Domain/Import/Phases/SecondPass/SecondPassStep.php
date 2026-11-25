<?php

/**
 * The second pass (I-333): every imported post and page with a reusable
 * block, a link or a canonical left for it gets them, now that everything
 * the run brings exists, in a revision of the import's; what stays open is
 * said and tried again by every later run. Fifty seconds a job.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\SecondPass;

use App\Domain\Cards\BlockIds;
use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Import\Convert\ConversionReport;
use App\Domain\Import\Convert\Links\SiteLinks;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\Input\RecordSecondPassInput;
use App\Domain\Import\Operations\RecordSecondPass;
use App\Domain\Import\Phases\Content\ContentSite;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\Media\ImportedMedia;
use App\Domain\Import\Phases\Media\PictureSwap;
use App\Domain\Import\Phases\RunsInMediaPass;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SecondPassStep implements RunsInMediaPass, StepPhase
{
    /** Seconds a job works before it hands on to the next. */
    public const int BUDGET = 50;

    /** Rows read at once. */
    public const int BATCH = 100;

    /** Posts a picture pass swaps in one job, one save each, before it hands on (#716). */
    public const int WAITING_BATCH = 25;

    /** Addresses named in one message. */
    public const int MOST_SAID = 10;

    /** The map's object type for a WordPress post or page (I-332). */
    private const string OBJECT = 'post';

    /** A reusable block's row's kind, as the content phase records it (I-333). */
    private const string REUSABLE = 'reusable';

    public function __construct(private ContentSite $sites, private FieldRegistry $fields) {}

    public function key(): string
    {
        return 'second-pass';
    }

    public function order(): int
    {
        return 600;
    }

    public function label(): string
    {
        return 'Reusable blocks and links';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        [$links, $find] = $this->tools($context);
        $started = microtime(true);
        $position = $cursor ?? '';

        do {
            $rows = self::rows($context->sourceId, $position);
            $changed = self::changedBlocks($context, $rows);

            foreach ($rows as $row) {
                $this->pass($row, $context, $links, $find, $changed);
                $position = (string) $row->object_id;
            }
        } while (count($rows) === self::BATCH && microtime(true) - $started < self::BUDGET);

        return count($rows) < self::BATCH ? StepResult::done() : StepResult::continueAt($position);
    }

    /**
     * The posts whose outside pictures wait for these files, after $after,
     * passed as the second pass passes any: each swaps in the pictures now
     * in the library, one save each, and is left as it is when changed here
     * (#716). Returns where the next call carries on, or null when done.
     *
     * @param  list<string>  $keys  waiting keys, by ImageTrace::waitingKeys()
     *
     * @since 1.5.0
     */
    public function passWaiting(ImportContext $context, array $keys, string $after = ''): ?string
    {
        if ($keys === []) {
            return null;
        }

        $literal = '{'.implode(',', array_map(fn (string $key): string => '"'.addcslashes($key, '"\\').'"', $keys)).'}';
        $rows = DB::table('import_map')
            ->where('source_id', $context->sourceId)->where('object_type', self::OBJECT)->where('object_id', '>', $after)
            ->where('teme_type', 'content')->whereNotNull('teme_id')
            ->whereRaw("(facts -> 'waiting_media') <> '[]'::jsonb AND (facts -> 'waiting_media') ??| ?::text[]", [$literal])
            ->orderBy('object_id')->limit(self::WAITING_BATCH)
            ->get(['object_id', 'teme_id', 'run_id', 'facts'])->all();
        [$links, $find] = $this->tools($context);
        $changed = self::changedBlocks($context, $rows);

        foreach ($rows as $row) {
            $this->pass($row, $context, $links, $find, $changed);
        }

        return count($rows) < self::WAITING_BATCH ? null : (string) end($rows)->object_id;
    }

    /**
     * The links and the reusable blocks a pass reads, the blocks each read once.
     *
     * @return array{0: SiteLinks, 1: Closure(string): (array{content: list<array<string, mixed>>, references: list<array<string, mixed>>, pendingLinks: list<string>}|null)}
     */
    private function tools(ImportContext $context): array
    {
        $links = new SiteLinks($this->sites->for($context->sourceId), new ImportedMedia($context->sourceId), new PassTargets($context->sourceId), new ConversionReport);
        $blocks = [];
        $find = function (string $id) use ($context, &$blocks): ?array {
            if (! array_key_exists($id, $blocks)) {
                $blocks[$id] = self::block($context->sourceId, $id);
            }

            return $blocks[$id];
        };

        return [$links, $find];
    }

    /**
     * The next rows after $after, in object order, whose item holds a
     * reference, a pending link or a pending canonical. The map's unique
     * index serves the order; the facts are read only for its rows.
     *
     * @return list<object>
     */
    private static function rows(string $sourceId, string $after): array
    {
        return DB::table('import_map')
            ->where('source_id', $sourceId)->where('object_type', self::OBJECT)->where('object_id', '>', $after)
            ->where('teme_type', 'content')->whereNotNull('teme_id')
            ->where(fn ($work) => $work
                ->whereRaw("jsonb_typeof(facts->'references') = 'array' AND facts->'references' <> '[]'::jsonb")
                ->orWhereRaw("jsonb_typeof(facts->'pending_links') = 'array' AND facts->'pending_links' <> '[]'::jsonb")
                ->orWhereRaw("jsonb_typeof(facts->'pending_canonical') = 'string'")
                // Outside pictures waiting for their files (#716).
                ->orWhereRaw("jsonb_typeof(facts->'waiting_media') = 'array' AND facts->'waiting_media' <> '[]'::jsonb"))
            ->orderBy('object_id')->limit(self::BATCH)
            ->get(['object_id', 'teme_id', 'run_id', 'facts'])->all();
    }

    /**
     * The reusable blocks these rows use, at any depth, that this run
     * imported again, keyed `#{id}` (an ID of digits would be an integer key).
     *
     * @param  list<object>  $rows
     *
     * @return array<string, true>
     */
    private static function changedBlocks(ImportContext $context, array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            array_push($ids, ...self::uses(self::facts($row)));
        }

        if ($ids === []) {
            return [];
        }

        $changed = [];

        foreach (DB::table('import_map')->where('source_id', $context->sourceId)->where('object_type', self::OBJECT)
            ->whereIn('object_id', array_values(array_unique($ids)))->where('run_id', $context->runId)->pluck('object_id') as $id) {
            $changed['#'.$id] = true;
        }

        return $changed;
    }

    /**
     * One item: skipped when deleted here, changed here, or settled; else
     * its document rebuilt from the converted one and recorded.
     *
     * @param  array<string, true>  $changed
     */
    private function pass(object $row, ImportContext $context, SiteLinks $links, Closure $find, array $changed): void
    {
        $content = Content::withTrashed()->find((string) $row->teme_id);

        if ($content === null) {
            return;
        }

        $facts = self::facts($row);
        $written = is_string($facts['written'] ?? null) ? $facts['written'] : '';
        $objectId = (string) $row->object_id;
        $references = self::references($facts);
        $passed = is_array($facts['passed'] ?? null) ? $facts['passed'] : null;
        $working = (string) $content->working_revision_id;
        $done = $passed !== null && ($passed['to'] ?? null) === $working;
        // Its own blocks and every block put in last time, nested ones too, so a change deep inside reaches it.
        $blockChanged = array_filter(self::uses($facts), fn (string $id): bool => isset($changed['#'.$id])) !== [];

        // Its outside pictures, while the articles came first, and those whose files are here now (#716).
        $waiting = $context->bringsMediaLater() ? self::strings($facts['waiting_media'] ?? null) : [];
        $media = new ImportedMedia($context->sourceId);
        $arrived = $waiting !== [] && PictureSwap::left($waiting, $media) !== $waiting;

        // Settled: nothing to do, so not even the fingerprint is read.
        if ($done && ($passed['open'] ?? true) === false && ! $blockChanged && ! $arrived) {
            return;
        }

        // A person's save here is never undone (I-332); said only where this run wrote the row, or a picture pass found pictures for it.
        if ($written === '' || ImportedContents::fingerprint($content) !== $written) {
            if ($written !== '' && $arrived && $context->kind === ImportRunKind::MediaPass) {
                $this->record($context, $objectId, $written, null, null, null, [new ImportMessage('second-pass.pictures-changed-here', 'It was changed here after the import, so its pictures still show from the old site: replace them in the editor before that site closes.')]);
            } elseif ($written !== '' && (string) $row->run_id === $context->runId) {
                $this->record($context, $objectId, $written, null, null, null, [new ImportMessage('second-pass.changed-here', 'It was changed here after the import, so its reusable blocks and links to the old site were left as they were.')]);
            }

            return;
        }

        // From the converted words, so a changed block replaces the old one rather than joining it.
        $from = $done && is_string($passed['from'] ?? null) && $passed['from'] !== '' ? $passed['from'] : $working;
        $base = ContentRevision::query()->find($from) ?? ContentRevision::query()->findOrFail($working);

        // Inlining refuses past a document's nodes as the save would, so both become one refusal.
        try {
            $inlined = SecondPassDocument::inline(array_values((array) ($base->document['content'] ?? [])), $references, $find, (string) $content->id);

            [$hrefs, $unresolved] = self::links($links, [...self::strings($facts['pending_links'] ?? null), ...$inlined['pendingLinks']]);
            [$fields, $canonicalOpen] = $this->canonical($links, (array) $base->fields, $facts['pending_canonical'] ?? null, (string) $content->type);

            $messages = [...self::blockNotes($inlined['missing'], $inlined['loops']), ...self::linkNotes($unresolved)];

            if ($canonicalOpen) {
                $messages[] = self::canonicalNote((string) $facts['pending_canonical']);
            }

            $open = $inlined['missing'] !== [] || $unresolved !== [] || $canonicalOpen;
            // A block the last pass put in, the same words again, keeps its IDs.
            $last = (is_string($passed['to'] ?? null) ? ContentRevision::query()->find($passed['to']) : null) ?? ContentRevision::query()->find($working);
            $kept = BlockIds::kept(SecondPassDocument::relink($inlined['content'], $hrefs), array_values((array) ($last?->document['content'] ?? [])));
            $document = ['type' => 'doc', 'content' => $kept];

            // The outside pictures whose files are here become the library's, from the words as converted each time (#716).
            if ($context->bringsMediaLater()) {
                $site = $this->sites->for($context->sourceId);
                $document['content'] = PictureSwap::document($document['content'], $site, $media);
                $fields = PictureSwap::featured($fields, is_int($facts['waiting_featured'] ?? null) ? $facts['waiting_featured'] : null, $site, $media);
            }

            $this->record($context, $objectId, $written, $document, $fields, ['from' => (string) $base->id, 'open' => $open, 'blocks' => $inlined['inlined']], $messages, $context->bringsMediaLater() ? PictureSwap::left($waiting, $media) : null);
        } catch (ValidationException $refused) {
            $reason = Str::limit((string) collect($refused->errors())->flatten()->first(), 300);
            $this->record($context, $objectId, $written, null, null, ['from' => (string) $base->id, 'open' => false, 'blocks' => self::uses($facts)], [new ImportMessage(
                'second-pass.refused',
                "Its reusable blocks and links couldn’t be put in, because the result isn’t something Teme can save ({$reason}), so it’s kept as the import first wrote it.",
            )]);
        }
    }

    /**
     * Hands one item's finished words, or only what the pass has to say, to
     * RecordSecondPass for the run.
     *
     * @param  array<string, mixed>|null  $document
     * @param  array<string, mixed>|null  $fields
     * @param  array<string, mixed>|null  $passed
     * @param  list<ImportMessage>  $messages
     * @param  list<string>|null  $waiting
     */
    private function record(ImportContext $context, string $objectId, string $written, ?array $document, ?array $fields, ?array $passed, array $messages, ?array $waiting = null): void
    {
        app(RecordSecondPass::class)->execute(new RecordSecondPassInput($context->runId, $objectId, $written, $document, $fields, $passed, $messages, $context->actorId(), $waiting));
    }

    /**
     * A reusable block's converted content, its own references and the links
     * it left; null unless the map holds it as one.
     *
     * @return array{content: list<array<string, mixed>>, references: list<array<string, mixed>>, pendingLinks: list<string>}|null
     */
    private static function block(string $sourceId, string $id): ?array
    {
        $row = DB::table('import_map')->where('source_id', $sourceId)->where('object_type', self::OBJECT)->where('object_id', $id)->first(['facts']);

        if ($row === null) {
            return null;
        }

        $facts = self::facts($row);

        if (($facts['kind'] ?? null) !== self::REUSABLE || ! is_array($facts['document'] ?? null) || ! array_is_list($facts['document'])) {
            return null;
        }

        return ['content' => $facts['document'], 'references' => self::references($facts), 'pendingLinks' => self::strings($facts['pending_links'] ?? null)];
    }

    /**
     * Each distinct path through the converter's link rules: those that
     * became links by ID, keyed by the path, and those that stayed paths.
     *
     * @param  list<string>  $paths
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private static function links(SiteLinks $links, array $paths): array
    {
        $found = [];
        $unresolved = [];

        foreach (array_values(array_unique($paths)) as $path) {
            $href = $links->href($path);

            if ($href !== null && str_starts_with($href, 'teme:')) {
                $found[$path] = $href;
            } else {
                $unresolved[] = $path;
            }
        }

        return [$found, $unresolved];
    }

    /**
     * The fields with the canonical set to the item the old address leads
     * to, and whether it stays open (rule 9: never the old site's address).
     *
     * @param  array<string, mixed>  $fields
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function canonical(SiteLinks $links, array $fields, mixed $pending, string $type): array
    {
        $has = in_array(BuiltInFields::CANONICAL, array_map(fn (Field $field): string => $field->key, $this->fields->forType($type)), true);

        if (! is_string($pending) || $pending === '' || ! $has) {
            return [$fields, false];
        }

        $id = $links->content($pending);

        if ($id === null) {
            return [$fields, true];
        }

        $fields[BuiltInFields::CANONICAL] = ['item' => $id];

        return [$fields, false];
    }

    /**
     * The reusable blocks a row's item uses: its own references and every
     * block the pass put in last time, nested ones included, each once.
     *
     * @param  array<string, mixed>  $facts
     *
     * @return list<string>
     */
    private static function uses(array $facts): array
    {
        $ids = array_map(fn (array $reference): string => (string) $reference['id'], self::references($facts));
        array_push($ids, ...self::strings(is_array($facts['passed'] ?? null) ? ($facts['passed']['blocks'] ?? null) : null));

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, mixed>
     */
    private static function facts(object $row): array
    {
        $facts = json_decode((string) $row->facts, true);

        return is_array($facts) ? $facts : [];
    }

    /**
     * A row's references that name a block and a place.
     *
     * @param  array<string, mixed>  $facts
     *
     * @return list<array{type: string, id: string, at: list<int>}>
     */
    private static function references(array $facts): array
    {
        $found = [];

        foreach (is_array($facts['references'] ?? null) ? $facts['references'] : [] as $reference) {
            if (is_array($reference) && (is_string($reference['id'] ?? null) || is_int($reference['id'] ?? null)) && is_array($reference['at'] ?? null) && $reference['at'] !== []) {
                $found[] = ['type' => (string) ($reference['type'] ?? self::OBJECT), 'id' => (string) $reference['id'], 'at' => array_map(intval(...), array_values($reference['at']))];
            }
        }

        return $found;
    }

    /**
     * The strings of a list.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * What is said of reusable blocks that were not found or were left out.
     *
     * @param  list<string>  $missing
     * @param  list<string>  $loops
     *
     * @return list<ImportMessage>
     */
    private static function blockNotes(array $missing, array $loops): array
    {
        $notes = [];

        if ($missing !== []) {
            $notes[] = new ImportMessage(
                'second-pass.block-missing',
                count($missing) === 1
                    ? 'A reusable block it uses wasn’t brought from WordPress, so that part of it is missing here. Running the import again brings it once the block imports.'
                    : number_format(count($missing)).' reusable blocks it uses weren’t brought from WordPress, so those parts of it are missing here. Running the import again brings them once the blocks import.',
                ['blocks' => array_slice($missing, 0, self::MOST_SAID)],
            );
        }

        if ($loops !== []) {
            $notes[] = new ImportMessage(
                'second-pass.block-loop',
                'A reusable block it uses holds itself, or holds blocks more than ten deep, so the repeated or deeper ones were left out.',
                ['blocks' => array_slice($loops, 0, self::MOST_SAID)],
            );
        }

        return $notes;
    }

    /**
     * What is said of links that stayed paths.
     *
     * @param  list<string>  $unresolved
     *
     * @return list<ImportMessage>
     */
    private static function linkNotes(array $unresolved): array
    {
        if ($unresolved === []) {
            return [];
        }

        $named = array_map(fn (string $path): string => Str::limit($path, 60), array_slice($unresolved, 0, self::MOST_SAID));
        $paths = implode(', ', $named).(count($unresolved) > self::MOST_SAID ? ' and '.number_format(count($unresolved) - self::MOST_SAID).' more' : '');

        return [new ImportMessage(
            'second-pass.links-unresolved',
            count($unresolved) === 1
                ? "A link leads to {$paths}, which nothing the import brought answers, so it stays an address on this site. The next import tries it again."
                : number_format(count($unresolved))." links lead to addresses nothing the import brought answers: {$paths}. They stay addresses on this site, and the next import tries them again.",
            ['count' => count($unresolved)],
        )];
    }

    private static function canonicalNote(string $path): ImportMessage
    {
        $path = Str::limit($path, 300);

        return new ImportMessage(
            'second-pass.canonical-unresolved',
            "Its canonical address on WordPress, {$path}, leads to nothing the import brought, so search engines are sent to the item itself. The next import tries it again.",
            ['path' => $path],
        );
    }
}
