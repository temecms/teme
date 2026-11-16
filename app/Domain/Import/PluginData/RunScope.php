<?php

declare(strict_types=1);

namespace App\Domain\Import\PluginData;

use App\Domain\Contents\Models\Content;
use App\Domain\Hooks\Payloads;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Import\ImportScope;

/**
 * What a plugin's importer may ask of the run it is part of: what core's
 * import made of WordPress's posts and people, read from the import map
 * (I-332), and whether the run is a catch-up. It writes nothing.
 */
final readonly class RunScope implements ImportScope
{
    public function __construct(private ImportContext $context, private ImportMap $map) {}

    public function itemFor(int $sourcePostId): ?ItemRef
    {
        $id = $this->map->temeId($this->context->sourceId, SourceRef::of('post', $sourcePostId));
        $content = $id === null ? null : Content::query()->find($id);

        return $content === null ? null : Payloads::item($content);
    }

    public function personFor(int $sourceUserId): ?string
    {
        return $this->map->temeId($this->context->sourceId, SourceRef::of('user', $sourceUserId));
    }

    public function isCatchUp(): bool
    {
        return $this->context->kind === ImportRunKind::CatchUp;
    }
}
