<?php

/**
 * After the comments (docs/plans/fediverse-import.md): each comment that
 * came from the fediverse is linked, by the address its remote server gave
 * it, to the comment the import made, so a later Update or Delete of it
 * finds that comment. A page of the plugin's reply sources a job; a
 * companion too old for the route is skipped here and reported by the
 * fediverse step.
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
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Source\SourceReader;

final readonly class FediverseRepliesStep implements StepPhase
{
    public function __construct(private ImportMap $map) {}

    public function key(): string
    {
        return 'fediverse-replies';
    }

    public function order(): int
    {
        return 710;
    }

    public function label(): string
    {
        return __('Fediverse replies');
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        if (! FediverseExport::answered($context->sourceId)) {
            return StepResult::done();
        }

        $page = $reader->page(FediverseExport::ROUTE, ['kind' => 'reply_sources'], (int) ($cursor ?? 0), FediverseExport::REPLIES_PAGE);
        $sources = [];

        foreach ($page->items as $item) {
            if (is_int($item['id'] ?? null) && is_string($item['source_id'] ?? null) && str_starts_with(strtolower($item['source_id']), 'https://') && FediverseExport::isHttp($item['source_id'])) {
                $sources[$item['id']] = $item['source_id'];
            }
        }

        $comments = $sources === [] ? [] : $this->map->temeIds($context->sourceId, 'comment', array_keys($sources));
        $links = [];

        foreach ($sources as $commentId => $uri) {
            if (isset($comments[$commentId])) {
                $links[] = ['uri' => $uri, 'id' => $comments[$commentId]];
            }
        }

        $messages = $links === [] ? [] : app(ImportFediverse::class)->execute(new ImportFediverseInput($context->sourceId, $context->runId, ImportFediverseInput::REPLIES, ['links' => $links], $context->actorId()));

        return $page->nextAfterId === null ? StepResult::done($messages) : StepResult::continueAt((string) $page->nextAfterId, $messages);
    }
}
