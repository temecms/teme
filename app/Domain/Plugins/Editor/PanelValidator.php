<?php

/**
 * Checks a plugin's declared editor panels (docs/proposals/plugin-sockets-3.md,
 * B1): their shape and bounds, the capabilities they need, the content
 * types they name, and, as the plugin starts, the jobs their actions queue
 * and the panels its class hands over. Every refusal is one plain sentence.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Editor;

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Types\ContentTypeRegistry;
use Teme\Contracts\Editor\EditorPanel;
use Teme\Contracts\Editor\HasEditorPanels;
use Teme\Contracts\Plugins\Capability;
use Throwable;

final class PanelValidator
{
    /** The most panels one plugin may declare. */
    public const int MOST = 2;

    /** The most actions one panel may offer. */
    public const int MOST_ACTIONS = 3;

    /** The most fields one action's form may hold. */
    public const int MOST_FIELDS = 4;

    /** A panel's or an action's id. */
    public const string ID = '/^[a-z][a-z0-9-]{1,23}$/D';

    /** A field's key. */
    public const string FIELD_KEY = '/^[a-z][a-z0-9_]{0,31}$/D';

    /** The icons a panel or area may use beyond a Tools link's. */
    public const array MORE_ICONS = ['sparkles', 'pen-line', 'list-checks', 'mail', 'inbox', 'layout', 'list', 'user', 'globe', 'code', 'grid'];

    /** The kinds of field an action's form may hold, and the longest text each takes. */
    public const array FIELD_TYPES = ['text' => 500, 'textarea' => 2_000, 'select' => null, 'boolean' => null, 'number' => null, 'items' => null];

    /** The capabilities a panel needs: it reads the item, and its actions queue jobs. */
    public const array NEEDS = [Capability::ContentRead, Capability::Jobs];

    /**
     * The icons a panel may use.
     *
     * @return list<string>
     */
    public static function icons(): array
    {
        return [...ContributionsValidator::ICONS, ...self::MORE_ICONS];
    }

    /**
     * Why the declared panels can't be used, or nothing when they can.
     * The capabilities and the content types are checked only when the
     * capabilities are given, as when a manifest is read; never as a plugin
     * starts, so a type removed later doesn't stop it.
     *
     * @param  array<array-key, mixed>  $contributes
     * @param  list<string>|null  $capabilities
     *
     * @return list<string>
     */
    public function errors(mixed $entries, array $contributes, ?array $capabilities): array
    {
        if (! is_array($entries) || ! array_is_list($entries)) {
            return [__('Its editor panels must be a list.')];
        }

        $errors = count($entries) > self::MOST ? [__('A plugin can add at most 2 editor panels.')] : [];

        if ($capabilities !== null && $entries !== []) {
            foreach (self::NEEDS as $needed) {
                if (! in_array($needed->value, $capabilities, true)) {
                    $errors[] = __('It adds an editor panel, but the plugin doesn’t ask for :capability.', ['capability' => $needed->value]);
                }
            }
        }

        $permissions = array_values(array_filter(array_column(array_filter(is_array($contributes['permissions'] ?? null) ? $contributes['permissions'] : [], is_array(...)), 'id'), is_string(...)));
        $ids = [];

        foreach ($entries as $entry) {
            $entry = is_array($entry) ? $entry : [];
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';

            if (preg_match(self::ID, $id) !== 1) {
                $errors[] = __('An editor panel’s id must be 2 to 24 lower-case letters, digits and hyphens, starting with a letter.');
            } elseif (in_array($id, $ids, true)) {
                $errors[] = __('Two editor panels can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;
            $errors = [...$errors, ...$this->panelErrors($entry, $permissions, $capabilities !== null)];
        }

        return $errors;
    }

    /**
     * What a starting plugin's panels need beyond their manifest: each
     * action's job declared, and the plugin's class handing over the same
     * panels as it declares.
     *
     * @param  array<array-key, mixed>  $contributes
     * @param  list<mixed>  $jobs  the manifest's jobs
     *
     * @return list<string>
     */
    public function startErrors(array $contributes, array $jobs, object $plugin): array
    {
        $entries = $contributes['editorPanels'] ?? [];

        if (! is_array($entries) || $entries === []) {
            return $plugin instanceof HasEditorPanels ? [__('It hands over editor panels but declares none.')] : [];
        }

        $declaredJobs = [];

        foreach ($jobs as $job) {
            if (is_array($job) && is_string($job['key'] ?? null)) {
                $declaredJobs[$job['key']] = true;
            }
        }

        $errors = [];
        $declared = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null)) {
                continue;
            }

            $declared[] = $entry['id'];

            foreach (is_array($entry['actions'] ?? null) ? $entry['actions'] : [] as $action) {
                if (is_array($action) && is_string($action['id'] ?? null) && is_string($action['job'] ?? null) && ! isset($declaredJobs[$action['job']])) {
                    $errors[] = __('Its editor panel’s action “:action” queues the job “:job”, which it doesn’t declare.', ['action' => $action['id'], 'job' => $action['job']]);
                }
            }
        }

        if (! $plugin instanceof HasEditorPanels) {
            return [...$errors, __('It declares editor panels but doesn’t implement :interface.', ['interface' => HasEditorPanels::class])];
        }

        try {
            $given = $plugin->editorPanels();
        } catch (Throwable) {
            return [...$errors, __('Its editor panels couldn’t be read.')];
        }

        foreach ($declared as $id) {
            if (! (($given[$id] ?? null) instanceof EditorPanel)) {
                $errors[] = __('It declares the editor panel “:id” but has no EditorPanel for it.', ['id' => $id]);
            }
        }

        foreach (array_keys($given) as $id) {
            if (! in_array($id, $declared, true)) {
                $errors[] = __('It has an editor panel “:id”, which isn’t declared.', ['id' => (string) $id]);
            }
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $panel
     * @param  list<string>  $permissions  the plugin's declared permission ids
     *
     * @return list<string>
     */
    private function panelErrors(array $panel, array $permissions, bool $checkTypes): array
    {
        $errors = [];

        foreach (array_keys($panel) as $key) {
            if (! in_array($key, ['id', 'label', 'description', 'icon', 'types', 'permission', 'actions'], true)) {
                $errors[] = __('An editor panel can’t have :key.', ['key' => (string) $key]);
            }
        }

        $label = is_string($panel['label'] ?? null) ? trim($panel['label']) : '';

        if ($label === '' || mb_strlen($label) > 40) {
            $errors[] = __('An editor panel’s label must be 1 to 40 characters.');
        }

        if (array_key_exists('description', $panel) && (! is_string($panel['description']) || mb_strlen($panel['description']) > 160)) {
            $errors[] = __('An editor panel’s description must be text of at most 160 characters.');
        }

        if (array_key_exists('icon', $panel) && ! in_array($panel['icon'], self::icons(), true)) {
            $errors[] = __(':icon isn’t an icon a plugin can use.', ['icon' => is_scalar($panel['icon']) ? (string) $panel['icon'] : '']);
        }

        $types = $panel['types'] ?? null;

        if (! is_array($types) || $types === [] || ! array_is_list($types)) {
            $errors[] = __('An editor panel must name at least one content type.');
        } else {
            foreach ($types as $type) {
                if (! is_string($type) || preg_match(ContributionsValidator::TYPE_KEY, $type) !== 1) {
                    $errors[] = __('An editor panel’s type must be a lower-case content type key.');
                } elseif ($checkTypes && ! app(ContentTypeRegistry::class)->has($type)) {
                    $errors[] = __('Its editor panel names :type, which isn’t a content type here.', ['type' => $type]);
                }
            }
        }

        if (array_key_exists('permission', $panel) && (! is_string($panel['permission']) || ! in_array($panel['permission'], $permissions, true))) {
            $errors[] = __('An editor panel’s permission must be one the plugin declares.');
        }

        $actions = $panel['actions'] ?? [];

        if (! is_array($actions) || ! array_is_list($actions)) {
            return [...$errors, __('An editor panel’s actions must be a list.')];
        }

        if (count($actions) > self::MOST_ACTIONS) {
            $errors[] = __('An editor panel can offer at most 3 actions.');
        }

        $ids = [];

        foreach ($actions as $action) {
            $action = is_array($action) ? $action : [];
            $id = is_string($action['id'] ?? null) ? $action['id'] : '';

            if (preg_match(self::ID, $id) !== 1) {
                $errors[] = __('An editor panel action’s id must be 2 to 24 lower-case letters, digits and hyphens, starting with a letter.');
            } elseif (in_array($id, $ids, true)) {
                $errors[] = __('Two actions of one editor panel can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;
            $errors = [...$errors, ...$this->actionErrors($action)];
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $action
     *
     * @return list<string>
     */
    private function actionErrors(array $action): array
    {
        $errors = [];

        foreach (array_keys($action) as $key) {
            if (! in_array($key, ['id', 'label', 'job', 'confirm', 'fields'], true)) {
                $errors[] = __('An editor panel action can’t have :key.', ['key' => (string) $key]);
            }
        }

        $label = is_string($action['label'] ?? null) ? trim($action['label']) : '';

        if ($label === '' || mb_strlen($label) > 40) {
            $errors[] = __('An editor panel action’s label must be 1 to 40 characters.');
        }

        if (! is_string($action['job'] ?? null) || $action['job'] === '') {
            $errors[] = __('An editor panel action must name the job it queues.');
        }

        if (array_key_exists('confirm', $action) && (! is_string($action['confirm']) || trim($action['confirm']) === '' || mb_strlen($action['confirm']) > 200)) {
            $errors[] = __('An editor panel action’s confirmation must be 1 to 200 characters.');
        }

        $fields = $action['fields'] ?? [];

        if (! is_array($fields) || ! array_is_list($fields)) {
            return [...$errors, __('An editor panel action’s fields must be a list.')];
        }

        if (count($fields) > self::MOST_FIELDS) {
            $errors[] = __('An editor panel action can ask for at most 4 fields.');
        }

        $keys = [];

        foreach ($fields as $field) {
            $field = is_array($field) ? $field : [];
            $key = is_string($field['key'] ?? null) ? $field['key'] : '';

            if (preg_match(self::FIELD_KEY, $key) !== 1) {
                $errors[] = __('A panel field’s key must be lower-case letters, digits and underscores, starting with a letter.');
            } elseif (in_array($key, $keys, true)) {
                $errors[] = __('Two fields of one action can’t share the key :key.', ['key' => $key]);
            }

            $keys[] = $key;
            $errors = [...$errors, ...$this->fieldErrors($field)];
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $field
     *
     * @return list<string>
     */
    private function fieldErrors(array $field): array
    {
        $errors = [];
        $type = is_string($field['type'] ?? null) ? $field['type'] : '';

        foreach (array_keys($field) as $key) {
            if (! in_array($key, ['key', 'type', 'label', 'required', 'default', 'maxLength', 'options', 'min', 'max'], true)) {
                $errors[] = __('A panel field can’t have :key.', ['key' => (string) $key]);
            }
        }

        if (! array_key_exists($type, self::FIELD_TYPES)) {
            return [...$errors, __('A panel field’s type must be text, textarea, select, boolean, number or items.')];
        }

        $label = is_string($field['label'] ?? null) ? trim($field['label']) : '';

        if ($label === '' || mb_strlen($label) > 40) {
            $errors[] = __('A panel field’s label must be 1 to 40 characters.');
        }

        if (array_key_exists('required', $field) && ! is_bool($field['required'])) {
            $errors[] = __('A panel field’s required must be true or false.');
        }

        $most = self::FIELD_TYPES[$type];

        if (array_key_exists('maxLength', $field) && ($most === null || ! is_int($field['maxLength']) || $field['maxLength'] < 1 || $field['maxLength'] > $most)) {
            $errors[] = $most === null
                ? __('Only a text or textarea field takes a maxLength.')
                : __('A :type field’s maxLength must be from 1 to :most.', ['type' => $type, 'most' => $most]);
        }

        if ($type === 'select') {
            $options = $field['options'] ?? null;

            if (! is_array($options) || $options === [] || array_is_list($options) || count(array_filter($options, fn (mixed $label): bool => is_string($label) && trim($label) !== '')) !== count($options)) {
                $errors[] = __('A select field needs its options, each a value and a label.');
            }
        } elseif (array_key_exists('options', $field)) {
            $errors[] = __('Only a select field takes options.');
        }

        foreach (['min', 'max'] as $bound) {
            if (array_key_exists($bound, $field) && ($type !== 'number' || ! is_int($field[$bound]) && ! is_float($field[$bound]))) {
                $errors[] = __('Only a number field takes a :bound, and it must be a number.', ['bound' => $bound]);
            }
        }

        if (array_key_exists('default', $field) && ! $this->fits($field, $field['default'])) {
            $errors[] = __('A panel field’s default must be a value the field accepts.');
        }

        return $errors;
    }

    /**
     * Whether a value fits the field as declared: its kind, length, option
     * or bounds. Used for a default and for what a person sends.
     *
     * @param  array<array-key, mixed>  $field
     */
    public function fits(array $field, mixed $value): bool
    {
        $type = is_string($field['type'] ?? null) ? $field['type'] : '';

        return match ($type) {
            'text', 'textarea' => is_string($value) && mb_strlen($value) <= (is_int($field['maxLength'] ?? null) ? $field['maxLength'] : (int) self::FIELD_TYPES[$type]),
            'select' => (is_string($value) || is_int($value)) && is_array($field['options'] ?? null) && array_key_exists($value, $field['options']),
            'boolean' => is_bool($value),
            // One post or page, by its content ID; whether this person may open it is checked when the action runs.
            'items' => is_string($value) && $value !== '' && mb_strlen($value) <= 26,
            'number' => (is_int($value) || is_float($value))
                && (! is_numeric($field['min'] ?? null) || $value >= $field['min'])
                && (! is_numeric($field['max'] ?? null) || $value <= $field['max']),
            default => false,
        };
    }
}
