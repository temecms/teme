<?php

/**
 * After everything is written and the home page is chosen (I-3446): the
 * old site's Polylang maps joined by union into groups, posts and pages
 * apart from terms, and each group linked as one translation group, 500
 * groups a job. A group that can't be linked is skipped and said, never
 * half-linked; a language's home page moves to its home as it is linked.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Languages;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\ClearRunMessages;
use App\Domain\Import\Operations\Input\ClearRunMessagesInput;
use App\Domain\Import\Operations\Input\LinkImportedTranslationsInput;
use App\Domain\Import\Operations\LinkImportedTranslations;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Translations\ImportedLanguages;
use App\Domain\Import\Translations\PolylangGroups;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class TranslationsStep implements StepPhase
{
    /** The kinds of map rows linked, in order: posts and pages, then terms. */
    private const array KINDS = ['content' => 'post', 'term' => 'term'];

    /** How long a run's groups are kept between its jobs: as long as the languages are. */
    private const int KEEP_SECONDS = 21600;

    public function __construct(private ImportedLanguages $imported) {}

    public function key(): string
    {
        return 'translations';
    }

    public function order(): int
    {
        return 1050;
    }

    public function label(): string
    {
        return 'Translations';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        if ($cursor === null) {
            app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($context->runId, 'translations.', $context->actorId()));
        }

        if (! $this->imported->for($context->sourceId)->active) {
            return StepResult::done();
        }

        [$kind, $batch] = self::at($cursor);
        $groups = self::kept($context, $kind);
        $slice = array_slice($groups, $batch * LinkImportedTranslations::BATCH, LinkImportedTranslations::BATCH);
        $messages = [];

        if ($slice !== []) {
            $written = app(LinkImportedTranslations::class)->execute(new LinkImportedTranslationsInput($context->sourceId, $kind, $slice, $context->actorId()));

            foreach ($written['skipped'] as $skipped) {
                $ids = implode(', ', $skipped['ids']);
                $words = $kind === 'content'
                    ? __('The posts or pages with WordPress IDs :ids are translations of each other on the old site, but weren’t linked here: :reason', ['ids' => $ids, 'reason' => $skipped['reason']])
                    : __('The terms with WordPress IDs :ids are translations of each other on the old site, but weren’t linked here: :reason', ['ids' => $ids, 'reason' => $skipped['reason']]);
                $messages[] = new ImportMessage('translations.skipped', $words, ['kind' => $kind, 'ids' => array_slice($skipped['ids'], 0, 20)]);
            }
        }

        if (($batch + 1) * LinkImportedTranslations::BATCH < count($groups)) {
            return StepResult::continueAt($kind.':'.($batch + 1), $messages);
        }

        return $kind === 'content' ? StepResult::continueAt('term:0', $messages) : StepResult::done($messages);
    }

    /**
     * The kind and the batch a cursor names: posts and pages' first batch
     * for none.
     *
     * @return array{0: string, 1: int}
     */
    private static function at(?string $cursor): array
    {
        if ($cursor !== null && preg_match('/^(content|term):(\d+)$/', $cursor, $found) === 1) {
            return [$found[1], (int) $found[2]];
        }

        return ['content', 0];
    }

    /**
     * The run's groups of one kind: read once, the first job of the kind,
     * and kept for the run's later jobs, so each batch doesn't read every
     * map again; read afresh whenever they are no longer kept (I-261: the
     * cache is disposable).
     *
     * @return list<list<string>>
     */
    private static function kept(ImportContext $context, string $kind): array
    {
        $key = "import.translation-groups.{$context->runId}.{$kind}";
        $kept = Cache::get($key);

        if (is_array($kept) && array_is_list($kept)) {
            /** @var list<list<string>> $kept */
            return $kept;
        }

        $groups = self::groups($context->sourceId, $kind);
        Cache::put($key, $groups, self::KEEP_SECONDS);

        return $groups;
    }

    /**
     * The source's groups of one kind, read from its map rows in one
     * statement: a post by its WordPress ID, a term by its term ID.
     *
     * @return list<list<string>>
     */
    private static function groups(string $sourceId, string $kind): array
    {
        $own = $kind === 'content' ? 'object_id' : "facts ->> 'term_id'";
        $rows = DB::table('import_map')
            ->where('source_id', $sourceId)
            ->where('object_type', self::KINDS[$kind])
            ->whereRaw("jsonb_typeof(facts -> 'translations') = 'object'")
            ->selectRaw("{$own} AS own, facts -> 'translations' AS translations")
            ->orderBy('id')
            ->cursor();

        $maps = (function () use ($rows): iterable {
            foreach ($rows as $row) {
                $translations = json_decode((string) $row->translations, true);

                if (is_array($translations) && $row->own !== null) {
                    yield ['id' => (string) $row->own, 'translations' => array_filter($translations, fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))];
                }
            }
        })();

        return PolylangGroups::groups($maps);
    }
}
