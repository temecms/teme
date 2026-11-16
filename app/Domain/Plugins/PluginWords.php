<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

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
     * names its hosts, and import.wordpress what it reads.
     *
     * @param  list<string>  $hosts
     * @param  array{options?: list<string>, postTypes?: array<string, list<string>>, userMeta?: list<string>, termMeta?: list<string>}  $imports
     */
    public static function capability(string $capability, array $hosts = [], array $imports = []): string
    {
        return match ($capability) {
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
     * The outbound door's sentence for a plugin that also takes hosts from
     * its settings (docs/plans/plugin-sockets-1.md, A2): its own hosts, then
     * the settings' labels, quoted and joined with "and".
     *
     * @param  list<string>  $hosts
     * @param  list<string>  $settings  the labels of the settings it takes hosts from
     */
    public static function httpWithSettings(array $hosts, array $settings): string
    {
        $quoted = array_map(fn (string $label): string => '“'.$label.'”', $settings);
        $last = array_pop($quoted);
        $named = $quoted === [] ? (string) $last : implode(', ', $quoted).' '.__('and').' '.$last;

        return __('Connect to services outside this site: :hosts, and the hosts you enter in :settings', ['hosts' => implode(', ', $hosts), 'settings' => $named]);
    }

    /**
     * The line under it that lists the hosts those settings name now.
     *
     * @param  list<string>  $hosts
     */
    public static function settingHostsNow(array $hosts): string
    {
        return $hosts === [] ? __('They name no host yet.') : __('They name these hosts now: :hosts', ['hosts' => implode(', ', $hosts)]);
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
     * What a hook means, as a phrase after "It listens for".
     */
    public static function hook(string $hook): string
    {
        return isset(self::HOOKS[$hook]) ? __(self::HOOKS[$hook]) : $hook;
    }
}
