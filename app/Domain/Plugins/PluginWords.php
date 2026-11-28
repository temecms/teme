<?php

/**
 * The words the Plugins page and its consent dialog use for what a plugin
 * asks to do and what it listens for (I-3353): a person never reads a
 * capability name or a hook's class name.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\Ai\Plugins\DeclaredAiTask;
use Closure;
use Teme\Contracts\Plugins\Capability;

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
        'MediaUpdated' => 'a file’s details changed',
        'MediaTrashed' => 'a file moved to the trash',
        'MediaRestored' => 'a file taken out of the trash',
        'MediaDeleted' => 'a file deleted for good',
        'TermCreated' => 'a category or tag created',
        'TermUpdated' => 'a category or tag changed',
        'TermDeleted' => 'a category or tag deleted',
        'TermAssigned' => 'an item’s categories or tags changed',
        'PagesPurged' => 'the public pages a change purged',
        'CommentStatusChanged' => 'a comment’s status changed',
        'SettingsChanged' => 'a setting changed',
        'ArticleLinks' => 'an article’s links drawn',
    ];

    /**
     * The sentence for one capability a plugin asks for; the outbound door
     * names its hosts, import.wordpress what it reads, and ai its tasks.
     *
     * @param  list<string>  $hosts
     * @param  array{options?: list<string>, postTypes?: array<string, list<string>>, userMeta?: list<string>, termMeta?: list<string>}  $imports
     * @param  list<string>  $aiTasks  the declared AI tasks' labels
     * @param  list<string>  $notices  the labels of the kinds of notice it declares
     * @param  list<string>  $securityKinds  the labels of the kinds of security log line it declares
     */
    public static function capability(string $capability, array $hosts = [], array $imports = [], array $aiTasks = [], array $notices = [], array $securityKinds = []): string
    {
        return self::core(fn (): string => match ($capability) {
            Capability::Ai->value => __('Use your site’s AI connection (Settings › AI) for: :tasks. What it sends goes to the AI service chosen there, and it spends your site’s AI budget.', ['tasks' => implode(', ', $aiTasks)]),
            Capability::ImportWordPress->value => self::imports($imports),
            Capability::ImportRead->value => __('Read another system’s export file, so its content can be imported into your site'),
            Capability::ContentRead->value => __('Read your posts, pages and other content, including drafts'),
            Capability::MediaRead->value => __('Read the media library'),
            Capability::CommentsCheck->value => __('Read comments’ text, names, email addresses, addresses, IP addresses and browsers before they are saved, to judge whether they are spam'),
            Capability::SearchRead->value => __('Receive visitors’ search words and the text of your published items, to answer searches from its own engine'),
            Capability::ContentInsert->value => __('Add content inside your articles, such as ads'),
            Capability::PagesInsert->value => __('Add content to every page of your site: in the head, at the top and bottom of the page, between items on lists, in the theme’s regions, and fixed to the screen’s edge'),
            Capability::ContentLinks->value => __('Change how links in your articles behave: nofollow, new tab'),
            Capability::ContentWrite->value => __('Create and change posts, pages and other content, for the person using it'),
            Capability::MediaWrite->value => __('Add files to the media library and change their details, for the person using it'),
            Capability::Jobs->value => __('Run work in the background and on a schedule'),
            Capability::Http->value => __('Connect to services outside this site: :hosts', ['hosts' => implode(', ', $hosts)]),
            Capability::RoutesPublic->value => __('Answer addresses on the public site without anyone signing in'),
            Capability::FediverseRead->value => __('See who follows the site on the fediverse, and hear what they send it'),
            Capability::FediverseSend->value => __('Send activity to the site’s fediverse followers'),
            Capability::FeedsLinks->value => __('Add links to your site’s feeds, such as the hub feed readers subscribe through'),
            Capability::PeopleRead->value => __('See the names of the people who write on this site'),
            Capability::NoticesSend->value => __('Send notices to people on this site, by email or in their inbox: :kinds', ['kinds' => implode('; ', $notices)]),
            Capability::PagesHead->value => __('Add tags that search engines and social sites read, and structured data, to the head of your site’s public pages'),
            Capability::Sitemaps->value => __('Add its own sitemaps to your site’s sitemap index'),
            Capability::DirectoryRead->value => __('Read the plugins and themes listed in Teme’s directory, as Teme verified them'),
            Capability::ContentSubmissions->value => __('Keep what visitors send from its public forms, as items waiting for someone to review them'),
            Capability::Redirects->value => __('Add redirects from old addresses of your site, and remove the ones it added'),
            Capability::SecurityLog->value => __('Write lines into your site’s security log under its own name: :kinds', ['kinds' => implode('; ', $securityKinds)]),
            default => $capability,
        });
    }

    /**
     * The outbound door's sentence for a plugin that also takes hosts from
     * its settings (docs/plans/plugin-sockets-1.md, A2): its own hosts, then
     * the settings' labels, quoted and joined with "and".
     *
     * @param  list<string>  $hosts
     * @param  list<string>  $settings  the labels of the settings it takes hosts from
     *
     * @since 1.0.0
     */
    public static function httpWithSettings(array $hosts, array $settings): string
    {
        $quoted = array_map(fn (string $label): string => '“'.$label.'”', $settings);
        $last = array_pop($quoted);
        $named = $quoted === [] ? (string) $last : implode(', ', $quoted).' '.self::core(fn (): string => __('and')).' '.$last;

        return self::core(fn (): string => __('Connect to services outside this site: :hosts, and the hosts you enter in :settings', ['hosts' => implode(', ', $hosts), 'settings' => $named]));
    }

    /**
     * The line under it that lists the hosts those settings name now.
     *
     * @param  list<string>  $hosts
     *
     * @since 1.0.0
     */
    public static function settingHostsNow(array $hosts): string
    {
        return self::core(fn (): string => $hosts === [] ? __('They name no host yet.') : __('They name these hosts now: :hosts', ['hosts' => implode(', ', $hosts)]));
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
     * The consent's line for a plugin this one requires, which must stay
     * enabled while this one is (docs/plans/plugin-sockets-3.md).
     *
     * @since 1.0.0
     */
    public static function needs(string $plugin): string
    {
        return __('Needs :plugin, which must stay enabled.', ['plugin' => $plugin]);
    }

    /**
     * The consent's line for one editor panel: the content types it shows
     * on and its label.
     *
     * @param  list<string>  $types  the types' names, as the admin shows them
     *
     * @since 1.0.0
     */
    public static function editorPanel(string $label, array $types): string
    {
        return __('Add a panel to the editor for :types: “:label”, which can start its work on the item you’re editing and suggest changes you choose to use.', ['types' => implode(', ', $types), 'label' => $label]);
    }

    /**
     * The consent's line for a plugin's editor checks: their labels and the
     * content types they run on.
     *
     * @param  list<string>  $labels
     * @param  list<string>  $types  the types' names, as the admin shows them
     *
     * @since 1.1.0
     */
    public static function editorChecks(array $labels, array $types): string
    {
        return __('Check the :types you edit and show what it finds beside the editor’s own checks: :labels.', ['types' => implode(', ', $types), 'labels' => implode(', ', $labels)]);
    }

    /**
     * The consent's line for a plugin's tab in Search and social.
     *
     * @param  list<string>  $types  the types' names, as the admin shows them
     *
     * @since 1.1.0
     */
    public static function searchSection(string $label, array $types): string
    {
        return __('Add a tab, “:label”, to Search and social for :types, whose values are saved with each item.', ['label' => $label, 'types' => implode(', ', $types)]);
    }

    /**
     * The consent's line naming the sitemaps a plugin adds.
     *
     * @param  list<string>  $labels
     *
     * @since 1.1.0
     */
    public static function sitemaps(array $labels): string
    {
        return __('Its sitemaps: :labels.', ['labels' => implode(', ', $labels)]);
    }

    /**
     * The sentence naming the hosts a plugin's inserted content may load
     * from (`contributes.csp`).
     *
     * @param  list<string>  $hosts
     *
     * @since 1.0.0
     */
    public static function insertionHosts(array $hosts): string
    {
        return __('Lets the content it inserts load from: :hosts', ['hosts' => implode(', ', $hosts)]);
    }

    /**
     * One declared AI task as the consent lists it: its label and what it sends.
     *
     * @since 1.0.0
     */
    public static function aiTask(DeclaredAiTask $task): string
    {
        return __(':label: sends :what', ['label' => $task->label, 'what' => self::aiSends($task->sends)]);
    }

    /**
     * What an AI task sends, in words: its own brief, an item's unpublished
     * text, or published text only.
     *
     * @since 1.0.0
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
     * A public address a plugin answers, from its stored line (`METHOD path`, with
     * a condition after it when it has one), in words people read; the address
     * stays in the sentence. A line it can't read is returned as it is.
     */
    public static function route(string $line): string
    {
        if (preg_match('{^([A-Z]+) (\S+)(?: \((.*)\))?$}', $line, $found) !== 1) {
            return $line;
        }

        $words = match ($found[1]) {
            'GET' => __('Shows a page at :path', ['path' => $found[2]]),
            'POST' => __('Answers a form sent to :path', ['path' => $found[2]]),
            default => __('Answers :method requests at :path', ['method' => $found[1], 'path' => $found[2]]),
        };

        return ($found[3] ?? '') === '' ? $words : $words.' ('.$found[3].')';
    }

    /**
     * What a hook means, as a phrase after "It listens for".
     */
    public static function hook(string $hook): string
    {
        return isset(self::HOOKS[$hook]) ? self::core(fn (): string => __(self::HOOKS[$hook])) : $hook;
    }

    /**
     * Core's own words only: a plugin's language file never re-words what a
     * person agrees to (docs/plans/plugin-sockets-1.md, A-W14).
     *
     * @param  Closure(): string  $words
     */
    private static function core(Closure $words): string
    {
        return app(PluginSentences::class)->coreOnly($words);
    }
}
