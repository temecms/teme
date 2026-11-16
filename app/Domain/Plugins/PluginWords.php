<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\Ai\Plugins\DeclaredAiTask;
use Teme\Contracts\Plugins\Capability;

/**
 * The words the Plugins page and its consent dialog use for what a plugin
 * asks to do and what it listens for (I-3353): a person never reads a
 * capability name or a hook's class name.
 */
final readonly class PluginWords
{
    /** What each hook means, by its short name, in the order the reference lists them. */
    private const array HOOKS = [
        'ContentSaved' => 'an item saved',
        'ContentPublished' => 'an item published',
        'ContentUnpublished' => 'an item unpublished',
        'ContentScheduled' => 'an item scheduled',
        'ContentTrashed' => 'an item moved to the trash',
        'ContentRestored' => 'an item taken out of the trash',
        'ContentVisibilityChanged' => 'an item made private or public',
        'ContentDeleted' => 'an item deleted for good',
        'MediaStored' => 'a file uploaded or replaced',
        'CommentStatusChanged' => 'a comment’s status changed',
        'SettingsChanged' => 'a setting changed',
    ];

    /**
     * The sentence for one capability a plugin asks for; the outbound door
     * names its hosts, import.wordpress what it reads, and ai its tasks.
     *
     * @param  list<string>  $hosts
     * @param  array{options?: list<string>, postTypes?: array<string, list<string>>, userMeta?: list<string>, termMeta?: list<string>}  $imports
     * @param  list<string>  $aiTasks  the declared AI tasks' labels
     */
    public static function capability(string $capability, array $hosts = [], array $imports = [], array $aiTasks = []): string
    {
        return match ($capability) {
            Capability::Ai->value => __('Use your site’s AI connection (Settings › AI) for: :tasks. What it sends goes to the AI service chosen there, and it spends your site’s AI budget.', ['tasks' => implode(', ', $aiTasks)]),
            Capability::ImportWordPress->value => self::imports($imports),
            Capability::ContentRead->value => __('Read your posts, pages and other content, including drafts'),
            Capability::MediaRead->value => __('Read the media library'),
            Capability::ContentInsert->value => __('Add content inside your articles, such as ads'),
            Capability::ContentWrite->value => __('Create and change posts, pages and other content, for the person using it'),
            Capability::MediaWrite->value => __('Add files to the media library and change their details, for the person using it'),
            Capability::Jobs->value => __('Run work in the background and on a schedule'),
            Capability::Http->value => __('Connect to services outside this site: :hosts', ['hosts' => implode(', ', $hosts)]),
            Capability::RoutesPublic->value => __('Answer addresses on the public site without anyone signing in'),
            Capability::FediverseRead->value => __('See who follows the site on the fediverse, and hear what they send it'),
            Capability::FediverseSend->value => __('Send activity to the site’s fediverse followers'),
            default => $capability,
        };
    }

    /**
     * What a plugin reads from the WordPress site an import reads, each
     * declared name listed (docs/proposals/plugin-import-socket.md).
     *
     * @param  array{options?: list<string>, postTypes?: array<string, list<string>>, userMeta?: list<string>, termMeta?: list<string>}  $imports
     */
    private static function imports(array $imports): string
    {
        $parts = [];

        if (($imports['options'] ?? []) !== []) {
            $parts[] = __('the options :names', ['names' => implode(', ', $imports['options'])]);
        }

        foreach ($imports['postTypes'] ?? [] as $type => $meta) {
            $parts[] = $meta === []
                ? __('the post type :type', ['type' => $type])
                : __('the post type :type with its meta :names', ['type' => $type, 'names' => implode(', ', $meta)]);
        }

        if (($imports['userMeta'] ?? []) !== []) {
            $parts[] = __('people’s meta :names', ['names' => implode(', ', $imports['userMeta'])]);
        }

        if (($imports['termMeta'] ?? []) !== []) {
            $parts[] = __('terms’ meta :names', ['names' => implode(', ', $imports['termMeta'])]);
        }

        return __('Read from the WordPress site you import: :what', ['what' => implode('; ', $parts)]);
    }

    /**
     * One declared AI task as the consent lists it: its label and what it sends.
     */
    public static function aiTask(DeclaredAiTask $task): string
    {
        return __(':label: sends :what', ['label' => $task->label, 'what' => self::aiSends($task->sends)]);
    }

    /**
     * What an AI task sends, in words: its own brief, an item's unpublished
     * text, or published text only.
     */
    public static function aiSends(string $sends): string
    {
        return match ($sends) {
            'brief' => __('only what the plugin writes itself'),
            'draft' => __('an item’s unpublished text'),
            'published' => __('published text only'),
            default => $sends,
        };
    }

    /**
     * What a hook means, as a phrase after "It listens for".
     */
    public static function hook(string $hook): string
    {
        return isset(self::HOOKS[$hook]) ? __(self::HOOKS[$hook]) : $hook;
    }
}
