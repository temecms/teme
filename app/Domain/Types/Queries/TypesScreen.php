<?php

/**
 * What Site › Types shows (I-3302): every type, code's and the site's own,
 * refused ones with the registry's reason, their public addresses as
 * Settings › Addresses has them, and their items. It reads only.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Queries;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Contents\Models\Content;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Queries\FieldsScreen;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Exceptions\InvalidTypeDefinition;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Types\TypeAccess;
use App\Domain\Types\TypeDefinition;
use App\Domain\Types\TypePermissions;
use App\Domain\Users\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use JsonException;
use Teme\Contracts\Settings\SettingsReader;

final readonly class TypesScreen
{
    /**
     * The features the screen draws, in that order. A field that provides
     * another feature follows these, named by its key.
     *
     * @var array<string, array{label: string, hint: string, field: string}>
     */
    public const array FEATURES = [
        'seo' => [
            'label' => 'Search and social',
            'hint' => 'SEO title, meta description and the card shown when an item is shared.',
            'field' => 'Search and social',
        ],
        'excerpt' => [
            'label' => 'Excerpt',
            'hint' => 'A short summary, shown in lists and feeds where themes choose.',
            'field' => 'Excerpt',
        ],
        'featured_image' => [
            'label' => 'Featured image',
            'hint' => 'The image that stands for an item in lists, feeds and social cards.',
            'field' => 'Featured image',
        ],
        'template' => [
            'label' => 'Template',
            'hint' => 'A choice among the layouts the active theme offers.',
            'field' => 'Template',
        ],
        'sticky' => [
            'label' => 'Pinning',
            'hint' => 'An item can be pinned to the top of the latest posts.',
            'field' => 'Pin to the top of the latest posts',
        ],
    ];

    /**
     * The six actions the permissions table names, in ContentType::ACTIONS'
     * order.
     *
     * @var array<string, string>
     */
    public const array ACTIONS = [
        'create' => 'Write new items',
        'edit.own' => 'Edit their own',
        'edit.any' => 'Edit anyone’s',
        'publish' => 'Publish and schedule',
        'delete.own' => 'Delete their own',
        'delete.any' => 'Delete anyone’s',
    ];

    /**
     * The list: code's types in registration order, then the table's rows
     * oldest first, each with its items and the address Settings keeps.
     *
     * @return array{locked: bool, types: list<array{key: string, singular: string, plural: string, segment: string, icon: string, source: string, public: bool, pattern: ?string, items: int, trashed: int, reason: ?string, listUrl: ?string}>}
     */
    public function list(): array
    {
        $types = app(ContentTypeRegistry::class);
        $addresses = AddressSettings::current();
        $rows = $this->rows();
        $counts = $this->counts([
            ...array_map(fn (ContentType $type): string => $type->key, $types->fromCode()),
            ...array_map(fn (object $row): string => (string) $row->key, $rows),
        ]);
        $refused = $types->refused();
        $listed = [];

        foreach ($types->fromCode() as $type) {
            $listed[] = $this->codeRow($type, $counts[$type->key] ?? ['items' => 0, 'trashed' => 0], $addresses);
        }

        foreach ($rows as $row) {
            $listed[] = $this->storedRow($row, $counts[(string) $row->key] ?? ['items' => 0, 'trashed' => 0], $refused, $addresses);
        }

        return ['locked' => TypeAccess::locked(), 'types' => $listed];
    }

    /**
     * One type's page, a stored row ahead of a type code declares, or null
     * when neither exists.
     *
     * @return array{mode: string, source: ?string, reason: ?string, type: array{key: string, singular: string, plural: string, segment: string, icon: string, single: bool, public: bool, archive: bool, searchable: bool, taxonomies: list<string>, supports: list<string>, comments: ?string, pattern: ?string, permissions: string}, made: ?string, items: int, trashed: int, example: ?string, listUrl: ?string, options: array{taxonomies: list<array{key: string, plural: string}>, features: list<array{key: string, label: string, hint: ?string, field: string}>}, permissions: array{roles: list<array{key: string, label: string}>, actions: list<array{key: string, label: string, ability: string}>, holds: array<string, list<string>>}, fields: array{own: list<string>, fromTeme: list<string>, url: string|null}}|null
     */
    public function type(string $key): ?array
    {
        $row = DB::table(StoredTypes::TABLE)->where('key', $key)->first(['key', 'definition', 'created_at']);

        if ($row !== null) {
            return $this->storedPage($row);
        }

        $types = app(ContentTypeRegistry::class);

        if (! $types->has($key) || $types->get($key)->stored) {
            return null;
        }

        return $this->codePage($types->get($key));
    }

    /**
     * The page for making a type, with the field's own empty values.
     *
     * @return array{mode: string, source: null, reason: null, type: array{key: string, singular: string, plural: string, segment: string, icon: string, single: bool, public: bool, archive: bool, searchable: bool, taxonomies: list<string>, supports: list<string>, comments: null, pattern: null, permissions: string}, made: null, items: int, trashed: int, example: null, listUrl: null, options: array{taxonomies: list<array{key: string, plural: string}>, features: list<array{key: string, label: string, hint: ?string, field: string}>}, permissions: array{roles: list<array{key: string, label: string}>, actions: list<array{key: string, label: string, ability: string}>, holds: array<string, list<string>>}, fields: array{own: list<string>, fromTeme: list<string>, url: string|null}}
     */
    public function blank(): array
    {
        return [
            'mode' => 'new',
            'source' => null,
            'reason' => null,
            'type' => [
                'key' => '',
                'singular' => '',
                'plural' => '',
                'segment' => '',
                'icon' => 'file',
                'single' => false,
                'public' => true,
                'archive' => false,
                'searchable' => true,
                'taxonomies' => [],
                'supports' => [],
                'comments' => null,
                'pattern' => null,
                'permissions' => TypeDefinition::SHARED,
            ],
            'made' => null,
            'items' => 0,
            'trashed' => 0,
            'example' => null,
            'listUrl' => null,
            'options' => $this->options(),
            'permissions' => $this->permissions(ContentType::ACTIONS),
            'fields' => ['own' => [], 'fromTeme' => [], 'url' => null],
        ];
    }

    /**
     * The plural a delete toast names: the row's, or the registered type's,
     * or the key when the row has none.
     */
    public static function plural(string $key): string
    {
        $plural = DB::table(StoredTypes::TABLE)
            ->where('key', $key)
            ->selectRaw("definition ->> 'plural' AS plural")
            ->value('plural');

        if (is_string($plural) && $plural !== '') {
            return $plural;
        }

        $types = app(ContentTypeRegistry::class);

        if ($types->has($key)) {
            return $types->get($key)->plural;
        }

        return $key;
    }

    /**
     * @return list<object>
     */
    private function rows(): array
    {
        return DB::table(StoredTypes::TABLE)
            ->orderBy('created_at')
            ->orderBy('key')
            ->get(['key', 'definition', 'created_at'])
            ->all();
    }

    /**
     * @param  list<string>  $keys
     *
     * @return array<string, array{items: int, trashed: int}>
     */
    private function counts(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $counts = [];

        foreach (DB::table('contents')->whereIn('type', $keys)->groupBy('type')->selectRaw('type, count(*) AS items, count(deleted_at) AS trashed')->get() as $row) {
            $counts[(string) $row->type] = ['items' => (int) $row->items, 'trashed' => (int) $row->trashed];
        }

        return $counts;
    }

    /**
     * @param  array{items: int, trashed: int}  $count
     *
     * @return array{key: string, singular: string, plural: string, segment: string, icon: string, source: string, public: bool, pattern: ?string, items: int, trashed: int, reason: ?string, listUrl: ?string}
     */
    private function codeRow(ContentType $type, array $count, AddressSettings $addresses): array
    {
        return [
            'key' => $type->key,
            'singular' => $type->singular,
            'plural' => $type->plural,
            'segment' => $type->segment,
            'icon' => $type->icon,
            'source' => 'code',
            'public' => $type->public,
            'pattern' => $this->livePattern($type->key, $type->public, $addresses),
            'items' => $count['items'],
            'trashed' => $count['trashed'],
            'reason' => null,
            'listUrl' => $type->listed ? route('content.index', ['type' => $type->segment], false) : null,
        ];
    }

    /**
     * @param  array{items: int, trashed: int}  $count
     * @param  array<string, string>  $refused
     *
     * @return array{key: string, singular: string, plural: string, segment: string, icon: string, source: string, public: bool, pattern: ?string, items: int, trashed: int, reason: ?string, listUrl: ?string}
     */
    private function storedRow(object $row, array $count, array $refused, AddressSettings $addresses): array
    {
        $definition = $this->lenient($row);
        $reason = $refused[$definition->key] ?? null;

        return [
            'key' => $definition->key,
            'singular' => $definition->singular,
            'plural' => $definition->plural,
            'segment' => $definition->segment,
            'icon' => $definition->icon,
            'source' => $reason === null ? 'stored' : 'refused',
            'public' => $definition->public,
            'pattern' => $reason === null
                ? $this->livePattern($definition->key, $definition->public, $addresses)
                : $definition->settled()->pattern,
            'items' => $count['items'],
            'trashed' => $count['trashed'],
            'reason' => $reason,
            'listUrl' => $this->registeredListUrl($definition->key, $definition->segment),
        ];
    }

    /**
     * @return array{mode: string, source: string, reason: ?string, type: array{key: string, singular: string, plural: string, segment: string, icon: string, single: bool, public: bool, archive: bool, searchable: bool, taxonomies: list<string>, supports: list<string>, comments: ?string, pattern: ?string, permissions: string}, made: string, items: int, trashed: int, example: ?string, listUrl: ?string, options: array{taxonomies: list<array{key: string, plural: string}>, features: list<array{key: string, label: string, hint: ?string, field: string}>}, permissions: array{roles: list<array{key: string, label: string}>, actions: list<array{key: string, label: string, ability: string}>, holds: array<string, list<string>>}, fields: array{own: list<string>, fromTeme: list<string>, url: string|null}}
     */
    private function storedPage(object $row): array
    {
        $raw = $this->raw($row);
        $lenient = TypeDefinition::lenient($raw);
        $reason = null;
        $source = 'stored';

        try {
            TypeDefinition::fromArray($raw);
        } catch (InvalidTypeDefinition $invalid) {
            $source = 'refused';
            $reason = $invalid->getMessage();
        }

        $refused = app(ContentTypeRegistry::class)->refused()[$lenient->key] ?? null;

        if ($refused !== null) {
            $source = 'refused';
            $reason = $refused;
        }

        $pattern = $source === 'refused'
            ? $lenient->settled()->pattern
            : $this->livePattern($lenient->key, $lenient->public, AddressSettings::current());
        $count = $this->counts([$lenient->key])[$lenient->key] ?? ['items' => 0, 'trashed' => 0];

        return [
            'mode' => TypeAccess::locked() ? 'locked' : 'edit',
            'source' => $source,
            'reason' => $reason,
            'type' => $this->values($lenient, $pattern),
            'made' => $this->made((string) $row->created_at),
            'items' => $count['items'],
            'trashed' => $count['trashed'],
            'example' => $lenient->public ? $this->example($lenient->key) : null,
            'listUrl' => $this->registeredListUrl($lenient->key, $lenient->segment),
            'options' => $this->options(),
            'permissions' => $this->permissions($lenient->permissions === TypeDefinition::OWN
                ? TypePermissions::names($lenient->key)
                : ContentType::ACTIONS),
            'fields' => $this->fields($lenient->key),
        ];
    }

    /**
     * @return array{mode: string, source: string, reason: null, type: array{key: string, singular: string, plural: string, segment: string, icon: string, single: bool, public: bool, archive: bool, searchable: bool, taxonomies: list<string>, supports: list<string>, comments: ?string, pattern: ?string, permissions: string}, made: null, items: int, trashed: int, example: ?string, listUrl: ?string, options: array{taxonomies: list<array{key: string, plural: string}>, features: list<array{key: string, label: string, hint: ?string, field: string}>}, permissions: array{roles: list<array{key: string, label: string}>, actions: list<array{key: string, label: string, ability: string}>, holds: array<string, list<string>>}, fields: array{own: list<string>, fromTeme: list<string>, url: string|null}}
     */
    private function codePage(ContentType $type): array
    {
        $count = $this->counts([$type->key])[$type->key] ?? ['items' => 0, 'trashed' => 0];
        $abilities = [];

        foreach (array_keys(ContentType::ACTIONS) as $action) {
            $abilities[$action] = $type->ability($action);
        }

        return [
            'mode' => 'view',
            'source' => 'code',
            'reason' => null,
            'type' => [
                'key' => $type->key,
                'singular' => $type->singular,
                'plural' => $type->plural,
                'segment' => $type->segment,
                'icon' => $type->icon,
                'single' => $type->single,
                'public' => $type->public,
                'archive' => $type->archive,
                'searchable' => $type->searchable,
                'taxonomies' => $type->taxonomies,
                'supports' => $type->supports,
                'comments' => $type->comments?->value,
                'pattern' => $this->livePattern($type->key, $type->public, AddressSettings::current()),
                'permissions' => $type->abilities === [] ? TypeDefinition::SHARED : TypeDefinition::OWN,
            ],
            'made' => null,
            'items' => $count['items'],
            'trashed' => $count['trashed'],
            'example' => $type->public ? $this->example($type->key) : null,
            'listUrl' => $type->listed ? route('content.index', ['type' => $type->segment], false) : null,
            'options' => $this->options(),
            'permissions' => $this->permissions($abilities),
            'fields' => $this->fields($type->key),
        ];
    }

    /**
     * @return array{key: string, singular: string, plural: string, segment: string, icon: string, single: bool, public: bool, archive: bool, searchable: bool, taxonomies: list<string>, supports: list<string>, comments: ?string, pattern: ?string, permissions: string}
     */
    private function values(TypeDefinition $definition, ?string $pattern): array
    {
        return [
            'key' => $definition->key,
            'singular' => $definition->singular,
            'plural' => $definition->plural,
            'segment' => $definition->segment,
            'icon' => $definition->icon,
            'single' => $definition->single,
            'public' => $definition->public,
            'archive' => $definition->archive,
            'searchable' => $definition->searchable,
            'taxonomies' => $definition->taxonomies,
            'supports' => $definition->supports,
            'comments' => $definition->comments,
            'pattern' => $pattern,
            'permissions' => $definition->permissions,
        ];
    }

    /**
     * What the table shows: every role, the six actions, and which each role
     * holds. Anyone’s is shown as covering their own too (I-3312).
     *
     * @param  array<string, string>  $abilities  action => ability name
     *
     * @return array{roles: list<array{key: string, label: string}>, actions: list<array{key: string, label: string, ability: string}>, holds: array<string, list<string>>}
     */
    private function permissions(array $abilities): array
    {
        $held = TypePermissions::held($abilities);
        $order = array_keys(ContentType::ACTIONS);
        $roles = [];

        foreach (array_keys($held) as $role) {
            $roles[] = ['key' => $role, 'label' => Roles::LABELS[$role] ?? $role];
        }

        $actions = [];

        foreach ($order as $action) {
            $actions[] = [
                'key' => $action,
                'label' => self::ACTIONS[$action],
                'ability' => $abilities[$action],
            ];
        }

        $holds = [];

        foreach ($held as $role => $has) {
            $set = array_flip($has);

            if (isset($set['edit.any'])) {
                $set['edit.own'] = true;
            }

            if (isset($set['delete.any'])) {
                $set['delete.own'] = true;
            }

            $holds[$role] = array_values(array_filter(
                $order,
                fn (string $action): bool => isset($set[$action]),
            ));
        }

        return ['roles' => $roles, 'actions' => $actions, 'holds' => $holds];
    }

    private function lenient(object $row): TypeDefinition
    {
        return TypeDefinition::lenient($this->raw($row));
    }

    /**
     * @return array<mixed>
     */
    private function raw(object $row): array
    {
        try {
            $decoded = json_decode((string) $row->definition, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = [];
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            $decoded = [];
        }

        unset($decoded['key']);

        return ['key' => (string) $row->key, ...$decoded];
    }

    private function livePattern(string $key, bool $public, AddressSettings $addresses): ?string
    {
        if (! $public || ! $addresses->hasPattern($key)) {
            return null;
        }

        return $addresses->pattern($key)->toString();
    }

    private function registeredListUrl(string $key, string $segment): ?string
    {
        $types = app(ContentTypeRegistry::class);

        if (! $types->has($key) || ! $types->get($key)->listed) {
            return null;
        }

        return route('content.index', ['type' => $segment], false);
    }

    /**
     * The type's fields, summed up for its page: its own fields' labels in
     * order, the names of the features that give it fields, and its Fields
     * page; none of them for a type the registry doesn't have.
     *
     * @return array{own: list<string>, fromTeme: list<string>, url: string|null}
     */
    private function fields(string $key): array
    {
        if (! app(ContentTypeRegistry::class)->has($key)) {
            return ['own' => [], 'fromTeme' => [], 'url' => null];
        }

        $screen = app(FieldsScreen::class);

        return [
            'own' => $screen->labels($key),
            'fromTeme' => $screen->features($key),
            'url' => route('types.fields.index', ['key' => $key], false),
        ];
    }

    private function made(string $createdAt): string
    {
        $zone = app(SettingsReader::class)->string('general', 'timezone');

        return CarbonImmutable::parse($createdAt, (string) config('app.timezone'))->timezone($zone)->format('j F Y');
    }

    /**
     * The newest published item's live address, or null when it has none.
     */
    private function example(string $key): ?string
    {
        $id = DB::table('contents')
            ->where('type', $key)
            ->whereNull('deleted_at')
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->value('id');

        if ($id === null) {
            return null;
        }

        $content = Content::query()->find($id);

        return $content === null ? null : Addresses::relativeUrl($content);
    }

    /**
     * @return array{taxonomies: list<array{key: string, plural: string}>, features: list<array{key: string, label: string, hint: ?string, field: string}>}
     */
    private function options(): array
    {
        $provided = app(FieldRegistry::class)->features();
        $features = [];

        foreach (self::FEATURES as $key => $feature) {
            if (in_array($key, $provided, true)) {
                $features[] = ['key' => $key, 'label' => $feature['label'], 'hint' => $feature['hint'], 'field' => $feature['field']];
            }
        }

        foreach ($provided as $key) {
            if (! array_key_exists($key, self::FEATURES)) {
                $features[] = ['key' => $key, 'label' => $key, 'hint' => null, 'field' => $key];
            }
        }

        return [
            'taxonomies' => array_map(
                fn (Taxonomy $taxonomy): array => ['key' => $taxonomy->key, 'plural' => $taxonomy->plural],
                app(TaxonomyRegistry::class)->all(),
            ),
            'features' => $features,
        ];
    }
}
