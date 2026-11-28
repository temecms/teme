<?php

/**
 * Every hook the contracts package publishes, in the order the reference
 * lists them (I-3353). A plugin's manifest names hooks by their short
 * names, which are unique across the package.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Hooks\Reference;

use App\Domain\Plugins\Hooks\DeclaredHooks;
use App\Domain\Plugins\Hooks\PluginHookCatalogue;
use App\Domain\Plugins\ManifestValidator;
use Teme\Contracts\Cache\PagesPurged;
use Teme\Contracts\Comments\CommentStatusChanged;
use Teme\Contracts\Content\ArticleLinks;
use Teme\Contracts\Content\BeforeSave;
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
use Teme\Contracts\Feeds\FeedsChanged;
use Teme\Contracts\Hooks\Hook;
use Teme\Contracts\Media\MediaDeleted;
use Teme\Contracts\Media\MediaRestored;
use Teme\Contracts\Media\MediaStored;
use Teme\Contracts\Media\MediaTrashed;
use Teme\Contracts\Media\MediaUpdated;
use Teme\Contracts\Privacy\CollectPersonalDataHolders;
use Teme\Contracts\Settings\SettingsChanged;
use Teme\Contracts\Taxonomy\TermAssigned;
use Teme\Contracts\Taxonomy\TermCreated;
use Teme\Contracts\Taxonomy\TermDeleted;
use Teme\Contracts\People\PersonInvited;
use Teme\Contracts\People\PersonJoined;
use Teme\Contracts\Taxonomy\TermUpdated;

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
        MediaUpdated::class,
        MediaTrashed::class,
        MediaRestored::class,
        MediaDeleted::class,
        TermCreated::class,
        TermUpdated::class,
        TermDeleted::class,
        TermAssigned::class,
        PagesPurged::class,
        CommentStatusChanged::class,
        SettingsChanged::class,
        CollectPersonalDataHolders::class,
        ActivityReceived::class,
        ActivitySent::class,
        CollectActivityHandlers::class,
        CollectObjectBuilders::class,
        ObjectBuilt::class,
        ActorDocumentBuilt::class,
        FeedsChanged::class,
        ArticleLinks::class,
        BeforeSave::class,
        PersonInvited::class,
        PersonJoined::class,
    ];

    /**
     * Returns the hooks each enabled plugin declares for other plugins, by
     * plugin, each with its full name, verb, description and the capability
     * a listener needs (docs/plans/plugin-sockets-3.md). A hook whose
     * class isn't loaded shows no verb and no capability.
     *
     * @return array<string, list<array{name: string, verb: string, description: string, capability: string|null}>>
     *
     * @since 1.0.0
     */
    public static function plugins(): array
    {
        $listed = [];

        foreach (app(PluginHookCatalogue::class)->all() as $hook) {
            $loaded = class_exists($hook['class'], false);

            $listed[$hook['plugin']][] = [
                'name' => $hook['fullName'],
                'verb' => $loaded ? DeclaredHooks::verb($hook['class']) : '',
                'description' => $hook['description'],
                'capability' => $loaded ? ManifestValidator::capabilityOf($hook['class']) : null,
            ];
        }

        return $listed;
    }
}
