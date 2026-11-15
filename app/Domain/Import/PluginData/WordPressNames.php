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

    /** Never sent, whatever a plugin declares; the companion refuses them too. */
    public const array NEVER_SENT = ['user_pass', 'session_tokens', '_application_passwords', 'wp_user_roles'];

    /** The keys `imports` may hold. */
    public const array KEYS = ['importer', 'options', 'postTypes', 'userMeta', 'termMeta'];
}
