<?php

/**
 * The kinds of notice plugins declare, `contributes.notices`
 * (docs/plans/plugin-sockets-3.md): their shape as the manifest gives them,
 * and the enabled plugins' kinds registered beside core's, each under the
 * plugin's stem, offered only while the plugin is enabled.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Notices;

use App\Domain\Notices\NoticeRegistry;
use App\Domain\Notices\NoticeRules;
use App\Domain\Plugins\Area\PluginPermissions;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\PluginKeys;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use InvalidArgumentException;
use Teme\Contracts\Notices\NoticeKind;
use Teme\Contracts\Plugins\Capability;
use Throwable;

final readonly class DeclaredNotices
{
    /** A kind's id within its plugin. */
    public const string ID = '/^[a-z][a-z0-9_]{1,31}$/D';

    /** At most this many kinds a plugin. */
    public const int MOST = 5;

    /** Only the person the plugin's current work acts for. */
    public const string STARTER = 'starter';

    /** The audiences a plugin's kind may name. */
    public const array AUDIENCES = [self::STARTER, 'plugins.manage'];

    public function __construct(
        private PluginRecords $records,
        private PluginRegistry $plugins,
    ) {}

    /**
     * The plugin's declared kinds, by id, each with its label, audience,
     * channels and defaults; none when the list's shape is wrong.
     *
     * @return array<string, array{label: string, audience: string, channels: list<string>, defaults: list<string>}>
     */
    public static function of(Manifest $manifest): array
    {
        $notices = $manifest->contributes()['notices'] ?? null;

        if (! is_array($notices) || ! array_is_list($notices)) {
            return [];
        }

        $declared = [];

        foreach ($notices as $notice) {
            if (! is_array($notice) || ! is_string($notice['id'] ?? null)) {
                continue;
            }

            $declared[$notice['id']] = [
                'label' => is_string($notice['label'] ?? null) ? $notice['label'] : '',
                'audience' => is_string($notice['audience'] ?? null) ? $notice['audience'] : '',
                'channels' => self::strings($notice['channels'] ?? null),
                'defaults' => self::strings($notice['defaults'] ?? null),
            ];
        }

        return $declared;
    }

    /**
     * The key a plugin's kind is registered under: its stem, a dot, its id.
     */
    public static function key(string $code, string $id): string
    {
        return PluginKeys::stem($code).'.'.$id;
    }

    /**
     * Why `contributes.notices` can't be used, or [] when it can.
     *
     * @param  list<string>  $permissions  the ids of the permissions the plugin declares, which an audience may name
     *
     * @return list<string>
     */
    public static function errors(mixed $notices, array $permissions = []): array
    {
        if (! is_array($notices) || ! array_is_list($notices)) {
            return [__('contributes.notices must be a list of kinds of notice.')];
        }

        $errors = count($notices) > self::MOST ? [__('A plugin can declare at most 5 kinds of notice.')] : [];
        $ids = [];

        foreach ($notices as $notice) {
            if (! is_array($notice) || array_diff(array_keys($notice), ['id', 'label', 'audience', 'channels', 'defaults']) !== []) {
                $errors[] = __('A kind of notice has an id, a label, an audience, channels and defaults, and nothing else; a plugin’s notices are never required.');

                continue;
            }

            $id = is_string($notice['id'] ?? null) ? $notice['id'] : '';
            $label = is_string($notice['label'] ?? null) ? trim($notice['label']) : '';
            $channels = $notice['channels'] ?? null;
            $defaults = $notice['defaults'] ?? [];

            if (preg_match(self::ID, $id) !== 1) {
                $errors[] = __('A kind of notice’s id must be 2 to 32 lower-case letters, digits and underscores, starting with a letter.');
            } elseif (isset($ids[$id])) {
                $errors[] = __('Two kinds of notice can’t share the id :id.', ['id' => $id]);
            }

            $ids[$id] = true;

            if ($label === '' || mb_strlen($label) > 120) {
                $errors[] = __('The kind of notice :id needs a label of 1 to 120 characters.', ['id' => $id]);
            }

            if (! in_array($notice['audience'] ?? null, [...self::AUDIENCES, ...$permissions], true)) {
                $errors[] = __('The kind of notice :id must go to starter, plugins.manage or a permission the plugin declares.', ['id' => $id]);
            }

            if (! is_array($channels) || ! array_is_list($channels) || $channels === [] || count(array_unique($channels, SORT_REGULAR)) !== count($channels)
                || array_diff($channels, [NoticeKind::MAIL, NoticeKind::INBOX]) !== []) {
                $errors[] = __('The kind of notice :id must be sent by mail, inbox or both.', ['id' => $id]);
            } elseif (! is_array($defaults) || ! array_is_list($defaults) || array_diff($defaults, $channels) !== []) {
                $errors[] = __('The kind of notice :id has a default that isn’t one of its channels.', ['id' => $id]);
            }
        }

        return $errors;
    }

    /**
     * The ability a kind's audience names: none for the starter, core's own
     * plugins.manage, or the full name of a permission the plugin declares.
     */
    public static function audience(string $code, string $audience): ?string
    {
        return match (true) {
            $audience === self::STARTER => null,
            in_array($audience, self::AUDIENCES, true) => $audience,
            default => PluginPermissions::name($code, $audience),
        };
    }

    /**
     * Registers the kinds of every enabled plugin granted notices.send,
     * each offered only while the plugin stays enabled. A kind the registry
     * refuses is reported and left out; it never stops core's.
     */
    public function register(NoticeRegistry $registry): void
    {
        // As the loader does: with no plugin installed, no row is read and nothing is kept.
        if (! $this->plugins->anyInstalled()) {
            return;
        }

        try {
            $records = $this->records->enabled();
        } catch (Throwable $failure) {
            report($failure);

            return;
        }

        foreach ($records as $record) {
            $manifest = $this->plugins->find($record->name)?->manifest;

            if ($manifest === null || ! in_array(Capability::NoticesSend->value, $record->consent['capabilities'] ?? [], true)) {
                continue;
            }

            $name = $record->name;

            foreach (self::of($manifest) as $id => $kind) {
                $key = self::key($manifest->code(), $id);

                try {
                    $registry->register(new NoticeKind(
                        $key,
                        $kind['label'],
                        audience: self::audience($manifest->code(), $kind['audience']),
                        channels: $kind['channels'],
                        defaults: $kind['defaults'],
                    ));
                } catch (InvalidArgumentException $refused) {
                    report($refused);

                    continue;
                }

                $registry->rules($key, new NoticeRules(
                    offered: fn (): bool => in_array($name, array_map(fn ($enabled): string => $enabled->name, $this->records->enabled()), true),
                ));
            }
        }
    }

    /**
     * The enabled plugin that declares this kind, by its Composer name,
     * label and code, or null for a kind no plugin declares.
     *
     * @return array{name: string, label: string, code: string}|null
     */
    public function owner(string $key): ?array
    {
        if (! str_starts_with($key, 'plugin_')) {
            return null;
        }

        foreach ($this->records->enabled() as $record) {
            $manifest = $this->plugins->find($record->name)?->manifest;

            if ($manifest !== null && str_starts_with($key, PluginKeys::stem($manifest->code()).'.') && array_key_exists(substr($key, strlen(PluginKeys::stem($manifest->code())) + 1), self::of($manifest))) {
                return ['name' => $record->name, 'label' => $manifest->label(), 'code' => $manifest->code()];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
