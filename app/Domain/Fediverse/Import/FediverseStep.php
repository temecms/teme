<?php

/**
 * After the people, the posts and the comments' settings
 * (docs/plans/fediverse-import.md): the WordPress ActivityPub plugin's
 * accounts with their keys, its settings and switch, then its followers a
 * page a job, then the old address of each post it federated, a batch a
 * job. A companion too old for the route is reported once, and the step
 * skips. Never federates: the run is in import mode.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Import;

use App\Domain\Fediverse\Import\Operations\ImportFediverse;
use App\Domain\Fediverse\Import\Operations\Input\ImportFediverseInput;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\Addresses\WordPressAddresses;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Source\SourceRefused;
use Illuminate\Support\Facades\DB;

final readonly class FediverseStep implements StepPhase
{
    /** How many imported posts one job gives an address. */
    private const int OBJECTS_BATCH = 500;

    public function key(): string
    {
        return 'fediverse';
    }

    public function order(): int
    {
        return 1010;
    }

    public function label(): string
    {
        return __('Fediverse followers');
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        // An export file has none of this (docs/architecture/import.md).
        if ($context->readsFile()) {
            return StepResult::done();
        }

        if (! FediverseExport::answered($context->sourceId)) {
            return StepResult::done([new ImportMessage('fediverse.companion-too-old', __('The companion on this site is too old to move fediverse followers. To bring them across, update the companion on WordPress, then catch up.'))]);
        }

        [$part, $after] = array_pad(explode(':', $cursor ?? '', 2), 2, '');

        return match ($part) {
            'followers' => $this->followers($context, $reader, (int) $after),
            'sweep' => $this->sweep($context),
            'objects' => $this->objects($context, $reader, $after),
            default => $this->actors($context, $reader),
        };
    }

    /**
     * @throws SourceRefused
     */
    private function actors(ImportContext $context, SourceReader $reader): StepResult
    {
        $export = FediverseExport::whole($reader->whole(FediverseExport::ROUTE));

        if (! $export['active'] && $export['actors'] === []) {
            return StepResult::done();
        }

        return StepResult::continueAt('followers:0', $this->write($context, ImportFediverseInput::ACTORS, $export));
    }

    /**
     * @throws SourceRefused
     */
    private function followers(ImportContext $context, SourceReader $reader, int $after): StepResult
    {
        $page = $reader->page(FediverseExport::ROUTE, ['kind' => 'followers'], $after, FediverseExport::FOLLOWERS_PAGE);
        $messages = $this->write($context, ImportFediverseInput::FOLLOWERS, ['items' => $page->items]);

        return StepResult::continueAt($page->nextAfterId === null ? 'sweep' : 'followers:'.$page->nextAfterId, $messages);
    }

    private function sweep(ImportContext $context): StepResult
    {
        return StepResult::continueAt('objects:', $this->write($context, ImportFediverseInput::SWEEP, []));
    }

    /**
     * One batch of the posts this source's imports made, by their map rows'
     * IDs: each published one of a federated type gets its WordPress address.
     *
     * @throws SourceRefused
     */
    private function objects(ImportContext $context, SourceReader $reader, string $after): StepResult
    {
        $site = DB::table('import_map')->where('source_id', $context->sourceId)->where('object_type', ImportFediverse::SITE)->value('facts');
        $site = is_string($site) ? json_decode($site, true) : null;

        if (! is_array($site) || ! is_string($site['home'] ?? null)) {
            return StepResult::done();
        }

        $types = array_values(array_filter(array_map(fn (mixed $type): ?string => is_string($type) ? WordPressAddresses::temeType($type) : null, (array) ($site['post_types'] ?? []))));
        $rows = DB::table('import_map')->where('source_id', $context->sourceId)->where('object_type', 'post')->where('teme_type', 'content')
            ->when($after !== '', fn ($query) => $query->where('id', '>', $after))
            ->orderBy('id')->limit(self::OBJECTS_BATCH)->get(['id', 'object_id', 'teme_id']);

        if ($rows->isEmpty()) {
            return StepResult::done();
        }

        $published = $types === [] ? [] : DB::table('contents')->whereIn('id', $rows->pluck('teme_id')->all())->whereIn('type', $types)
            ->where('status', 'published')->whereNull('deleted_at')->pluck('id')->flip()->all();
        $last = (int) ($site['last_permalink_id'] ?? 0);
        $wanted = [];

        foreach ($rows as $row) {
            if (isset($published[$row->teme_id]) && ctype_digit((string) $row->object_id)) {
                $wanted[(int) $row->object_id] = (string) $row->teme_id;
            }
        }

        $permalinks = $this->permalinks($reader, array_values(array_filter(array_keys($wanted), fn (int $id): bool => $id <= $last)));
        $links = [];

        foreach ($wanted as $postId => $contentId) {
            $uri = $postId <= $last ? ($permalinks[$postId] ?? null) : rtrim($site['home'], '/').'/?p='.$postId;

            if ($uri !== null) {
                $links[] = ['uri' => $uri, 'id' => $contentId];
            }
        }

        $messages = $this->write($context, ImportFediverseInput::OBJECTS, ['links' => $links]);

        return $rows->count() < self::OBJECTS_BATCH ? StepResult::done($messages) : StepResult::continueAt('objects:'.$rows->last()->id, $messages);
    }

    /**
     * The permalinks WordPress gives these posts, by post ID, a request per 200.
     *
     * @param  list<int>  $ids
     *
     * @return array<int, string>
     *
     * @throws SourceRefused
     */
    private function permalinks(SourceReader $reader, array $ids): array
    {
        $permalinks = [];

        foreach (array_chunk($ids, FediverseExport::PERMALINKS_ASKED) as $chunk) {
            $answer = $reader->whole(FediverseExport::ROUTE, ['kind' => 'permalinks', 'ids' => implode(',', $chunk)]);
            $read = $answer['permalinks'] ?? null;

            if (! is_array($read)) {
                throw SourceRefused::badAnswer();
            }

            foreach ($read as $id => $permalink) {
                if (is_string($permalink) && FediverseExport::isHttp($permalink) && in_array((int) $id, $chunk, true)) {
                    $permalinks[(int) $id] = $permalink;
                }
            }
        }

        return $permalinks;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @return list<ImportMessage>
     */
    private function write(ImportContext $context, string $part, array $data): array
    {
        return app(ImportFediverse::class)->execute(new ImportFediverseInput($context->sourceId, $context->runId, $part, $data, $context->actorId()));
    }
}
