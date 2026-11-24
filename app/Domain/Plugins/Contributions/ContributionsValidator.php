<?php

/**
 * Checks what a plugin's manifest says it adds to the admin, `contributes`,
 * before anything of it is registered: its settings as Teme would register
 * them, and its Tools links (I-3353). The schema states the same lists and
 * patterns (ContributionsSchemaTest).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

use App\Domain\Ai\Plugins\PluginAiTasks;
use App\Domain\Plugins\Area\AreaValidator;
use App\Domain\Plugins\Editor\PanelValidator;
use App\Domain\Plugins\Hooks\DeclaredHooks;
use App\Domain\Plugins\Notices\DeclaredNotices;
use App\Domain\Plugins\Seams\ContributionsCheck;
use App\Domain\Plugins\Seo\SeoValidator;
use App\Domain\Plugins\Types\PluginTypesFile;
use App\Domain\Settings\SettingsRegistry;
use InvalidArgumentException;
use Teme\Contracts\Plugins\Capability;

final class ContributionsValidator implements ContributionsCheck
{
    /** The setting types a plugin may declare. */
    public const array TYPES = ['text', 'textarea', 'number', 'boolean', 'select', 'multiselect', 'url', 'urls', 'email', 'secret', 'color', 'person', 'term', 'type', 'code', 'repeater'];

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

    /**
     * The kinds a list column may take.
     *
     * @since 1.0.0
     */
    public const array COLUMN_KINDS = ['text', 'number', 'badge'];

    /**
     * The widths a list column may take, in pixels.
     *
     * @since 1.0.0
     */
    public const array COLUMN_WIDTHS = ['narrow' => 100, 'medium' => 128, 'wide' => 168];

    /**
     * A list column's id.
     *
     * @since 1.0.0
     */
    public const string COLUMN_ID = '/^[a-z][a-z0-9-]{1,39}$/D';

    /**
     * A content type key a column may name.
     *
     * @since 1.0.0
     */
    public const string TYPE_KEY = '/^[a-z][a-z0-9_]{0,31}$/D';

    /**
     * At most this many columns per plugin.
     *
     * @since 1.0.0
     */
    public const int MOST_COLUMNS = 3;

    /**
     * The relations a feed link may take: hub alone in this round (docs/plans/plugin-sockets-1.md, A3).
     *
     * @since 1.0.0
     */
    public const array FEED_LINK_RELS = ['hub'];

    /**
     * A feed link's href: an https address, or {key} naming one of the plugin's own settings.
     *
     * @since 1.0.0
     */
    public const string FEED_LINK_HREF = '#^(https://.+|\{[a-z][a-z0-9_]{1,40}\})$#D';

    /**
     * At most this many feed links per plugin.
     *
     * @since 1.0.0
     */
    public const int MOST_FEED_LINKS = 2;

    /**
     * At most this many actions per plugin.
     *
     * @since 1.0.0
     */
    public const int MOST_ACTIONS = 3;

    /**
     * At most this many lists per plugin.
     *
     * @since 1.0.0
     */
    public const int MOST_LISTS = 2;

    /**
     * At most this many columns in one list.
     *
     * @since 1.0.0
     */
    public const int MOST_LIST_COLUMNS = 6;

    /**
     * The most rows a list may ask for.
     *
     * @since 1.0.0
     */
    public const int MOST_LIST_ROWS = 50;

    /**
     * The rows a list shows when it doesn't say.
     *
     * @since 1.0.0
     */
    public const int DEFAULT_LIST_ROWS = 20;

    /**
     * A card's id (docs/guides/developers/plugins/cards.md).
     *
     * @since next
     */
    public const string CARD_ID = '/^[a-z][a-z0-9-]{1,40}$/D';

    /**
     * The types a card's field may take: a setting's, without secret, code
     * and repeater, and with choice.
     *
     * @since next
     */
    public const array CARD_FIELD_TYPES = ['text', 'textarea', 'number', 'boolean', 'select', 'multiselect', 'url', 'urls', 'email', 'color', 'person', 'term', 'type', 'choice'];

    /**
     * At most this many cards per plugin.
     *
     * @since next
     */
    public const int MOST_CARDS = 20;

    /**
     * At most this many fields per card.
     *
     * @since next
     */
    public const int MOST_CARD_FIELDS = 20;

    /** The code the settings are checked under; the real group takes the plugin's. */
    private const string CHECKED_CODE = 'settings';

    /**
     * Returns why the contributions can't be used, or nothing when they can.
     *
     * @param  array<array-key, mixed>  $contributes
     * @param  list<string>|null  $capabilities  what the manifest asks for; null where the manifest's validator already held `csp` to them, as when a plugin starts
     *
     * @return list<string>
     */
    public function errors(array $contributes, string $version, ?array $capabilities = null): array
    {
        $errors = [];
        $inserts = $capabilities === null || in_array(Capability::ContentInsert->value, $capabilities, true) || in_array(Capability::PagesInsert->value, $capabilities, true);

        foreach (array_keys($contributes) as $surface) {
            // The hosts inserted content loads from, read only for a plugin that inserts (I-3375).
            if ($surface === 'csp' && ! $inserts) {
                $errors[] = __('A plugin’s csp is read only with content.insert or pages.insert.');
            } elseif ($surface === 'fixedLabel') {
                $label = $contributes['fixedLabel'];

                if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 24) {
                    $errors[] = __('fixedLabel must be text of 1 to 24 characters.');
                }
            } elseif ($surface === 'notices') {
                // A kind may go to the holders of a permission the plugin declares (docs/plans/plugin-sockets-3.md, D-T5).
                $permissions = array_values(array_filter(array_column(array_filter(is_array($contributes['permissions'] ?? null) ? $contributes['permissions'] : [], is_array(...)), 'id'), is_string(...)));
                array_push($errors, ...DeclaredNotices::errors($contributes['notices'], $permissions));
            } elseif ($surface === 'hooks') {
                array_push($errors, ...DeclaredHooks::errors($contributes['hooks']));
            } elseif ($surface === 'types') {
                // Its content types, from a teme-types file inside it (docs/architecture/plugins.md, *Content types from a plugin*).
                $problem = PluginTypesFile::pathProblem($contributes['types']);

                if ($problem !== null) {
                    $errors[] = $problem;
                }
            } elseif ($surface === 'cards') {
                // Cards for a theme's regions, drawn on every kind of page (docs/guides/developers/plugins/cards.md).
                if ($capabilities !== null && ! in_array(Capability::PagesInsert->value, $capabilities, true)) {
                    $errors[] = __('A plugin’s cards need pages.insert.');
                }

                array_push($errors, ...$this->cardErrors($contributes['cards'], $version));
            } elseif (! in_array($surface, ['csp', 'settings', 'admin', 'columns', 'feedLinks', 'actions', 'lists', 'aiTasks', 'permissions', 'area', 'settingsSections', 'editorPanels', 'editorChecks', 'searchSection', 'sitemaps', 'structuredData'], true)) {
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

        $feedLinks = $contributes['feedLinks'] ?? [];
        $errors = [...$errors, ...$this->feedLinkErrors(is_array($feedLinks) ? $feedLinks : [null], is_array($settings) ? $settings : [])];

        $actions = $contributes['actions'] ?? [];
        $errors = [...$errors, ...$this->actionErrors(is_array($actions) ? $actions : [null])];

        $lists = $contributes['lists'] ?? [];
        $errors = [...$errors, ...$this->listErrors(is_array($lists) ? $lists : [null])];

        // A plugin's permissions, its settings' sections and conditions, and its area (docs/plans/plugin-sockets-2.md, A1, A2, A4).
        $area = new AreaValidator;

        if (array_key_exists('permissions', $contributes)) {
            $errors = [...$errors, ...$area->permissionErrors($contributes['permissions'])];
        }

        $sections = [];

        if (array_key_exists('settingsSections', $contributes)) {
            $errors = [...$errors, ...$area->settingsSectionsErrors($contributes['settingsSections'])];
            $sections = array_values(array_filter(array_column(array_filter(is_array($contributes['settingsSections']) ? $contributes['settingsSections'] : [], is_array(...)), 'id'), is_string(...)));
        }

        $earlier = [];

        foreach (is_array($settings) ? $settings : [] as $setting) {
            if (is_array($setting)) {
                $errors = [...$errors, ...$area->settingPlacementErrors($setting, $sections, $earlier)];
                $earlier[is_string($setting['key'] ?? null) ? $setting['key'] : ''] = $setting;
            }
        }

        if (array_key_exists('area', $contributes)) {
            $errors = [...$errors, ...$area->areaErrors($contributes['area'], $contributes)];
        }

        if (array_key_exists('aiTasks', $contributes)) {
            $errors = [...$errors, ...PluginAiTasks::errors($contributes['aiTasks'])];
        }

        if (array_key_exists('editorPanels', $contributes)) {
            $errors = [...$errors, ...app(PanelValidator::class)->errors($contributes['editorPanels'], $contributes, $capabilities)];
        }

        // What it declares for search engines (docs/guides/developers/plugins/seo.md).
        $errors = [...$errors, ...app(SeoValidator::class)->errors($contributes, $capabilities)];

        return $errors;
    }

    /**
     * Cards: at most 20, each with an id, a label, a description and up to
     * 20 fields declared as settings are, which Teme can build.
     *
     * @return list<string>
     */
    private function cardErrors(mixed $cards, string $version): array
    {
        if (! is_array($cards) || ! array_is_list($cards)) {
            return [__('cards must list the plugin’s cards.')];
        }

        $errors = count($cards) > self::MOST_CARDS ? [__('A plugin can offer at most 20 cards.')] : [];
        $ids = [];

        foreach ($cards as $card) {
            $card = is_array($card) ? $card : [];
            $id = is_string($card['id'] ?? null) ? $card['id'] : null;

            foreach (array_keys($card) as $key) {
                if (! in_array($key, ['id', 'label', 'description', 'icon', 'keywords', 'fields'], true)) {
                    $errors[] = __('A card can’t have :key.', ['key' => (string) $key]);
                }
            }

            if (preg_match(self::CARD_ID, $id ?? '') !== 1) {
                $errors[] = __('A card’s id must be 2 to 41 lower-case letters, digits and hyphens, starting with a letter.');
            }

            if (! is_string($card['label'] ?? null) || trim($card['label']) === '' || mb_strlen($card['label']) > 40) {
                $errors[] = __('A card needs a label of 1 to 40 characters.');
            }

            if (! is_string($card['description'] ?? null) || trim($card['description']) === '' || mb_strlen($card['description']) > 160) {
                $errors[] = __('A card needs a description of 1 to 160 characters.');
            }

            if (array_key_exists('icon', $card) && (! is_string($card['icon']) || preg_match(self::CARD_ID, $card['icon']) !== 1)) {
                $errors[] = __('A card’s icon is the name of an icon, in lower-case letters, digits and hyphens.');
            }

            if (array_key_exists('keywords', $card) && (! is_array($card['keywords']) || ! array_is_list($card['keywords']) || count($card['keywords']) > 10 || array_any($card['keywords'], fn (mixed $word): bool => ! is_string($word) || trim($word) === '' || mb_strlen($word) > 40))) {
                $errors[] = __('A card’s keywords are at most 10 words of 1 to 40 characters.');
            }

            array_push($errors, ...$this->cardFieldErrors($card['fields'] ?? [], $version));

            if ($id !== null && in_array($id, $ids, true)) {
                $errors[] = __('Two cards can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function cardFieldErrors(mixed $fields, string $version): array
    {
        if (! is_array($fields) || ! array_is_list($fields) || count($fields) > self::MOST_CARD_FIELDS) {
            return [__('A card’s fields are a list of at most 20.')];
        }

        $errors = [];
        $keys = [];

        foreach ($fields as $field) {
            $field = is_array($field) ? $field : [];
            $key = is_string($field['key'] ?? null) ? $field['key'] : null;

            if (! in_array($field['type'] ?? null, self::CARD_FIELD_TYPES, true)) {
                $errors[] = __('A card’s field can’t be of the type :type.', ['type' => is_scalar($field['type'] ?? null) ? (string) $field['type'] : '']);

                continue;
            }

            if (! is_string($field['label'] ?? null) || trim($field['label']) === '') {
                $errors[] = __('A card’s field needs a label.');
            }

            if (array_key_exists('section', $field) || array_key_exists('visibleWhen', $field)) {
                $errors[] = __('A card’s field has no section or condition.');
            }

            try {
                DeclaredSettings::cardField([...$field, 'description' => is_string($field['description'] ?? null) ? $field['description'] : ''], $version);
            } catch (InvalidArgumentException $refused) {
                $errors[] = __('A card’s field can’t be used: :error', ['error' => $refused->getMessage()]);
            }

            if ($key !== null && in_array($key, $keys, true)) {
                $errors[] = __('Two of a card’s fields can’t share the key :key.', ['key' => $key]);
            }

            $keys[] = $key;
        }

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

            if (($setting['type'] ?? null) === 'term' && (! is_string($setting['taxonomy'] ?? null) || preg_match(self::TYPE_KEY, $setting['taxonomy']) !== 1)) {
                $errors[] = __('A term setting names the taxonomy its terms come from.');
            }

            if (array_key_exists('multiple', $setting) && ! is_bool($setting['multiple'])) {
                $errors[] = __('A setting’s multiple is true or false.');
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

    /**
     * Feed links: at most two, each `hub` and an https address of at most
     * 2,048 characters or `{key}` naming one of its own url or urls
     * settings (docs/plans/plugin-sockets-1.md, A3).
     *
     * @param  array<array-key, mixed>  $links
     * @param  array<array-key, mixed>  $settings
     *
     * @return list<string>
     */
    private function feedLinkErrors(array $links, array $settings): array
    {
        $errors = count($links) > self::MOST_FEED_LINKS ? [__('A plugin can add at most 2 feed links.')] : [];
        $urlSettings = [];

        foreach ($settings as $setting) {
            if (is_array($setting) && is_string($setting['key'] ?? null) && in_array($setting['type'] ?? null, ['url', 'urls'], true)) {
                $urlSettings[] = $setting['key'];
            }
        }

        foreach ($links as $link) {
            $rel = is_array($link) ? ($link['rel'] ?? null) : null;
            $href = is_array($link) && is_string($link['href'] ?? null) ? $link['href'] : '';

            if (! in_array($rel, self::FEED_LINK_RELS, true)) {
                $errors[] = __('A feed link’s rel must be hub.');
            }

            $named = preg_match('/^\{(.+)\}$/D', $href, $match) === 1 ? $match[1] : null;
            $fine = preg_match(self::FEED_LINK_HREF, $href) === 1 && strlen($href) <= 2048 && ($named === null || in_array($named, $urlSettings, true));

            if (! $fine) {
                $errors[] = __('A feed link’s href must be an https:// address or one of its url or urls settings, as {key}.');
            }
        }

        return $errors;
    }

    /**
     * Actions: buttons on the plugin's settings page that queue one of its
     * declared jobs (docs/proposals/plugin-sockets-1.md). Contributions::add
     * checks that the job is declared.
     *
     * @param  array<array-key, mixed>  $actions
     *
     * @return list<string>
     */
    private function actionErrors(array $actions): array
    {
        $errors = [];

        if (count($actions) > self::MOST_ACTIONS) {
            $errors[] = __('A plugin can add at most 3 actions.');
        }

        $ids = [];

        foreach ($actions as $action) {
            $action = is_array($action) ? $action : [];
            $id = is_string($action['id'] ?? null) ? $action['id'] : null;
            $label = $action['label'] ?? null;

            if (preg_match(self::LINK_ID, $id ?? '') !== 1) {
                $errors[] = __('An action’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter.');
            }

            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 40) {
                $errors[] = __('An action needs a label of 1 to 40 characters.');
            }

            if (array_key_exists('description', $action) && (! is_string($action['description']) || mb_strlen($action['description']) > 160)) {
                $errors[] = __('An action’s description must be text of at most 160 characters.');
            }

            if (! is_string($action['job'] ?? null) || $action['job'] === '') {
                $errors[] = __('An action must name the job it queues.');
            }

            if ($id !== null && in_array($id, $ids, true)) {
                $errors[] = __('Two actions can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;
        }

        return $errors;
    }

    /**
     * Lists: small tables on the plugin's settings page, with cells of the
     * column kinds (docs/proposals/plugin-sockets-1.md).
     *
     * @param  array<array-key, mixed>  $lists
     *
     * @return list<string>
     */
    private function listErrors(array $lists): array
    {
        $errors = [];

        if (count($lists) > self::MOST_LISTS) {
            $errors[] = __('A plugin can add at most 2 lists.');
        }

        $ids = [];

        foreach ($lists as $list) {
            $list = is_array($list) ? $list : [];
            $id = is_string($list['id'] ?? null) ? $list['id'] : null;
            $columns = $list['columns'] ?? null;
            $rows = $list['rows'] ?? self::DEFAULT_LIST_ROWS;

            if (preg_match(self::LINK_ID, $id ?? '') !== 1) {
                $errors[] = __('A list’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter.');
            }

            if (! is_string($list['label'] ?? null) || trim($list['label']) === '') {
                $errors[] = __('A list needs a label.');
            }

            if (array_key_exists('description', $list) && ! is_string($list['description'])) {
                $errors[] = __('A list’s description must be text.');
            }

            if (! is_int($rows) || $rows < 1 || $rows > self::MOST_LIST_ROWS) {
                $errors[] = __('A list shows 1 to 50 rows.');
            }

            if (! is_array($columns) || $columns === [] || ! array_is_list($columns) || count($columns) > self::MOST_LIST_COLUMNS) {
                $errors[] = __('A list needs 1 to 6 columns.');
                $columns = [];
            }

            $columnIds = [];

            foreach ($columns as $column) {
                $column = is_array($column) ? $column : [];
                $columnId = is_string($column['id'] ?? null) ? $column['id'] : null;

                if (preg_match(self::COLUMN_ID, $columnId ?? '') !== 1) {
                    $errors[] = __('A column’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter.');
                }

                $label = is_string($column['label'] ?? null) ? trim($column['label']) : '';

                if ($label === '' || mb_strlen($label) > 40) {
                    $errors[] = __('A column’s label must be 1 to 40 characters.');
                }

                if (! in_array($column['kind'] ?? null, self::COLUMN_KINDS, true)) {
                    $errors[] = __('A column’s kind must be text, number or badge.');
                }

                if ($columnId !== null && in_array($columnId, $columnIds, true)) {
                    $errors[] = __('Two columns can’t share the id :id.', ['id' => $columnId]);
                }

                $columnIds[] = $columnId;
            }

            if ($id !== null && in_array($id, $ids, true)) {
                $errors[] = __('Two lists can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;
        }

        return $errors;
    }
}
