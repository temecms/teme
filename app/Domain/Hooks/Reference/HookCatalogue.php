<?php

declare(strict_types=1);

namespace App\Domain\Hooks\Reference;

use Teme\Contracts\Comments\CommentStatusChanged;
use Teme\Contracts\Content\ContentDeleted;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentRestored;
use Teme\Contracts\Content\ContentSaved;
use Teme\Contracts\Content\ContentScheduled;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Content\ContentUnpublished;
use Teme\Contracts\Content\ContentVisibilityChanged;
use Teme\Contracts\Fediverse\ActivityReceived;
use Teme\Contracts\Fediverse\ActivitySent;
use Teme\Contracts\Fediverse\ActorDocumentBuilt;
use Teme\Contracts\Fediverse\CollectActivityHandlers;
use Teme\Contracts\Fediverse\CollectObjectBuilders;
use Teme\Contracts\Fediverse\ObjectBuilt;
use Teme\Contracts\Hooks\Hook;
use Teme\Contracts\Media\MediaStored;
use Teme\Contracts\Privacy\CollectPersonalDataHolders;
use Teme\Contracts\Settings\SettingsChanged;

/**
 * Every hook the contracts package publishes, in the order the reference
 * lists them (I-3353). A plugin's manifest names hooks by their short
 * names, which are unique across the package.
 */
final class HookCatalogue
{
    /** @var list<class-string<Hook>> */
    public const array HOOKS = [
        ContentSaved::class,
        ContentPublished::class,
        ContentUnpublished::class,
        ContentScheduled::class,
        ContentTrashed::class,
        ContentRestored::class,
        ContentVisibilityChanged::class,
        ContentDeleted::class,
        MediaStored::class,
        CommentStatusChanged::class,
        SettingsChanged::class,
        CollectPersonalDataHolders::class,
        ActivityReceived::class,
        ActivitySent::class,
        CollectActivityHandlers::class,
        CollectObjectBuilders::class,
        ObjectBuilt::class,
        ActorDocumentBuilt::class,
    ];
}
