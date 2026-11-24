<?php

/**
 * A picture pass's second media phase (#716): after the files posts wait
 * for, the export file's other attachments, so the library ends with every
 * file the old site had. Each attachment is brought as MediaPhase brings
 * it, in the run's lanes. A live site's pass reads every attachment in
 * MediaPhase, since the companion can't pick them, and nothing here.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Media;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportResult;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Phases\PreparedObject;
use App\Domain\Import\Phases\PreparesObjects;
use App\Domain\Import\Phases\RunsInMediaPass;
use App\Domain\Import\Phases\SourceRef;

final readonly class MediaRestPhase implements ListPhase, PreparesObjects, RunsInMediaPass
{
    public function __construct(private MediaPhase $media) {}

    public function key(): string
    {
        return 'media-rest';
    }

    public function order(): int
    {
        return 420;
    }

    public function label(): string
    {
        return 'Other media';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return false;
    }

    public function lists(ImportContext $context): array
    {
        return $context->kind === ImportRunKind::MediaPass && $context->readsFile() ? $this->media->listsOf($context, 'without') : [];
    }

    public function identify(ImportList $list, array $item, int|string $key): SourceRef
    {
        return $this->media->identify($list, $item, $key);
    }

    public function prepare(SourceRef $ref, array $item, ImportContext $context): PreparedObject
    {
        return $this->media->prepare($ref, $item, $context);
    }

    public function import(SourceRef $ref, array $item, ImportContext $context, ?PreparedObject $prepared): ImportResult
    {
        return $this->media->import($ref, $item, $context, $prepared);
    }
}
