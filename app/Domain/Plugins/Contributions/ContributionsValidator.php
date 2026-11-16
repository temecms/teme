<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

use App\Domain\Plugins\Seams\ContributionsCheck;
use App\Domain\Settings\SettingsRegistry;
use InvalidArgumentException;

/**
 * Checks what a plugin's manifest says it adds to the admin, `contributes`,
 * before anything of it is registered: its settings as Teme would register
 * them, and its Tools links (I-3353). The schema states the same lists and
 * patterns (ContributionsSchemaTest).
 */
final class ContributionsValidator implements ContributionsCheck
{
    /** The setting types a plugin may declare. */
    public const array TYPES = ['text', 'textarea', 'number', 'boolean', 'select', 'multiselect', 'url', 'urls', 'email', 'secret', 'color', 'person'];

    /** Where a plugin may add a link: Tools alone in the first release. */
    public const array SURFACES = ['tools'];

    /** The icons a plugin's link may use. */
    public const array ICONS = ['plug', 'link', 'external-link', 'settings', 'send', 'bell', 'refresh-cw', 'chart-line'];

    /** A declared setting's key. */
    public const string KEY = '/^[a-z][a-z0-9_]{1,40}$/D';

    /** A link's id. */
    public const string LINK_ID = '/^[a-z][a-z0-9-]{1,39}$/D';

    /** Where a link leads: the plugin's settings, or an https address. */
    public const string TO = '#^(settings|https://.+)$#D';

    /** The kinds a list column may take. */
    public const array COLUMN_KINDS = ['text', 'number', 'badge'];

    /** The widths a list column may take, in pixels. */
    public const array COLUMN_WIDTHS = ['narrow' => 100, 'medium' => 128, 'wide' => 168];

    /** A list column's id. */
    public const string COLUMN_ID = '/^[a-z][a-z0-9-]{1,39}$/D';

    /** A content type key a column may name. */
    public const string TYPE_KEY = '/^[a-z][a-z0-9_]{0,31}$/D';

    /** At most this many columns per plugin. */
    public const int MOST_COLUMNS = 3;

    /** The code the settings are checked under; the real group takes the plugin's. */
    private const string CHECKED_CODE = 'settings';

    /**
     * Returns why the contributions can't be used, or nothing when they can.
     *
     * @param  array<array-key, mixed>  $contributes
     *
     * @return list<string>
     */
    public function errors(array $contributes, string $version): array
    {
        $errors = [];

        foreach (array_keys($contributes) as $surface) {
            // Reserved in the manifest's shape, read by nothing yet (I-3375).
            if ($surface === 'csp') {
                $errors[] = __('A plugin\'s csp is read only with the site.output permission, which Teme doesn\'t offer yet.');
            } elseif ($surface !== 'settings' && $surface !== 'admin' && $surface !== 'columns') {
                $errors[] = __('Teme doesn’t offer the :surface surface to plugins yet.', ['surface' => $surface]);
            }
        }

        $settings = $contributes['settings'] ?? [];
        $settingErrors = $this->settingErrors(is_array($settings) ? $settings : []);
        $errors = [...$errors, ...$settingErrors];

        if ($settings !== [] && $settingErrors === []) {
            try {
                (new SettingsRegistry)->group(DeclaredSettings::group(self::CHECKED_CODE, self::CHECKED_CODE, self::CHECKED_CODE, $version, is_array($settings) ? array_values($settings) : [null]));
            } catch (InvalidArgumentException $refused) {
                $errors[] = __('Its settings can’t be used: :error', ['error' => $refused->getMessage()]);
            }
        }

        $links = $contributes['admin'] ?? [];

        $ids = [];

        foreach (is_array($links) ? $links : [null] as $link) {
            $errors = [...$errors, ...$this->linkErrors(is_array($link) ? $link : [])];
            $id = is_array($link) && is_string($link['id'] ?? null) ? $link['id'] : null;

            if ($id !== null && in_array($id, $ids, true)) {
                $errors[] = __('Two links can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;
        }

        $columns = $contributes['columns'] ?? [];
        $errors = [...$errors, ...$this->columnErrors(is_array($columns) ? $columns : [null])];

        return $errors;
    }

    /**
     * The schema's demands Teme's settings registry words its own way: a
     * label and a description, each text.
     *
     * @param  array<array-key, mixed>  $settings
     *
     * @return list<string>
     */
    private function settingErrors(array $settings): array
    {
        $errors = [];

        foreach ($settings as $setting) {
            if (! is_array($setting)) {
                continue;
            }

            if (! is_string($setting['label'] ?? null)) {
                $errors[] = __('A setting needs a label.');
            }

            if (! is_string($setting['description'] ?? null)) {
                $errors[] = __('A setting needs a description.');
            }
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $link
     *
     * @return list<string>
     */
    private function linkErrors(array $link): array
    {
        $errors = [];
        $surface = is_string($link['surface'] ?? null) ? $link['surface'] : '';
        $icon = $link['icon'] ?? null;
        $to = is_string($link['to'] ?? null) ? $link['to'] : '';

        if (! in_array($surface, self::SURFACES, true)) {
            $errors[] = __('Teme doesn’t offer the :surface surface to plugins yet.', ['surface' => $surface]);
        }

        if (preg_match(self::LINK_ID, is_string($link['id'] ?? null) ? $link['id'] : '') !== 1) {
            $errors[] = __('A link’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter.');
        }

        if (! is_string($link['label'] ?? null) || trim($link['label']) === '') {
            $errors[] = __('A link needs a label.');
        }

        if (array_key_exists('description', $link) && ! is_string($link['description'])) {
            $errors[] = __('A link’s description must be text.');
        }

        if ($icon !== null && ! in_array($icon, self::ICONS, true)) {
            $errors[] = __(':icon isn’t an icon a plugin can use.', ['icon' => is_scalar($icon) ? (string) $icon : '']);
        }

        if (preg_match(self::TO, $to) !== 1) {
            $errors[] = __('A link must go to settings or to an https:// address.');
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $columns
     *
     * @return list<string>
     */
    private function columnErrors(array $columns): array
    {
        $errors = [];

        if (count($columns) > self::MOST_COLUMNS) {
            $errors[] = __('A plugin can add at most 3 columns.');
        }

        $ids = [];

        foreach ($columns as $column) {
            $errors = [...$errors, ...$this->oneColumnErrors(is_array($column) ? $column : [])];
            $id = is_array($column) && is_string($column['id'] ?? null) ? $column['id'] : null;

            if ($id !== null && in_array($id, $ids, true)) {
                $errors[] = __('Two columns can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $column
     *
     * @return list<string>
     */
    private function oneColumnErrors(array $column): array
    {
        $errors = [];

        if (array_key_exists('sortable', $column)) {
            $errors[] = __('Teme doesn’t sort a list by a plugin’s column yet.');
        }

        foreach (array_keys($column) as $key) {
            if (! in_array($key, ['id', 'label', 'kind', 'width', 'types', 'sortable'], true)) {
                $errors[] = __('A column can’t have :key.', ['key' => (string) $key]);
            }
        }

        if (preg_match(self::COLUMN_ID, is_string($column['id'] ?? null) ? $column['id'] : '') !== 1) {
            $errors[] = __('A column’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter.');
        }

        $label = is_string($column['label'] ?? null) ? trim($column['label']) : '';

        if ($label === '' || mb_strlen($label) > 40) {
            $errors[] = __('A column’s label must be 1 to 40 characters.');
        }

        if (! in_array($column['kind'] ?? null, self::COLUMN_KINDS, true)) {
            $errors[] = __('A column’s kind must be text, number or badge.');
        }

        if (array_key_exists('width', $column) && (! is_string($column['width']) || ! array_key_exists($column['width'], self::COLUMN_WIDTHS))) {
            $errors[] = __('A column’s width must be narrow, medium or wide.');
        }

        $types = $column['types'] ?? null;

        if ($types !== null) {
            if (! is_array($types) || ! array_is_list($types)) {
                $errors[] = __('A column’s type must be a lower-case content type key.');
            } else {
                foreach ($types as $type) {
                    if (! is_string($type) || preg_match(self::TYPE_KEY, $type) !== 1) {
                        $errors[] = __('A column’s type must be a lower-case content type key.');
                        break;
                    }
                }
            }
        }

        return $errors;
    }
}
