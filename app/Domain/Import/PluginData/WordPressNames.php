<?php

declare(strict_types=1);

namespace App\Domain\Import\PluginData;

/**
 * The names a plugin may declare under its manifest's `imports`
 * (docs/architecture/plugins.md): the shape of a WordPress option or meta
 * key, how many in all, and the keys Teme never brings across whatever a
 * plugin declares, because they are passwords, sessions or core's own roles.
 */
final readonly class WordPressNames
{
    /** A WordPress option name or meta key: what WordPress's own columns hold, at most 191 characters. */
    public const string PATTERN = '/^[A-Za-z0-9_\-:.]{1,191}$/';

    /** A WordPress post type's name, as register_post_type() takes it. */
    public const string POST_TYPE = '/^[a-z0-9_\-]{1,20}$/';

    /** The most names one plugin declares in all. */
    public const int MOST = 50;

    /** Never sent, whatever a plugin declares, in any case; the companion refuses them too. */
    public const array NEVER_SENT = ['user_pass', 'session_tokens', '_application_passwords', 'mailserver_pass'];

    /** Never sent after any table prefix, such as wp_user_roles and wp_2_capabilities: core's roles and each person's. */
    public const array NEVER_SENT_AFTER_PREFIX = ['user_roles', 'capabilities'];

    /** The keys `imports` may hold. */
    public const array KEYS = ['importer', 'options', 'postTypes', 'userMeta', 'termMeta'];

    /**
     * Whether Teme never brings this name across, whatever its case: a
     * never-sent name, or one that ends in a never-sent name after a prefix.
     */
    public static function neverSent(string $name): bool
    {
        $name = strtolower($name);

        if (in_array($name, self::NEVER_SENT, true)) {
            return true;
        }

        foreach (self::NEVER_SENT_AFTER_PREFIX as $ending) {
            if (str_ends_with($name, $ending)) {
                return true;
            }
        }

        return false;
    }
}
