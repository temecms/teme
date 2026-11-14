<?php

declare(strict_types=1);

namespace App\Domain\Types\Queries;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyDefinition;
use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Types\TypeAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use JsonException;
use Teme\Contracts\Settings\SettingsReader;

/**
 * What Site › Taxonomies shows (I-3319): every taxonomy, code's and the
 * site's own, refused ones with the registry's reason, who uses each, its
 * public address as Settings › Addresses has it, and its terms. It reads only.
 *
 * @phpstan-type TaxonomyRow array{key: string, singular: string, plural: string, segment: string, icon: string, source: string, reason: ?string, hierarchical: bool, usedBy: list<string>, address: ?string, terms: int, termsUrl: ?string}
 * @phpstan-type TaxonomyValues array{key: string, singular: string, plural: string, segment: string, icon: string, hierarchical: bool, public: bool, base: ?string, types: list<string>}
 * @phpstan-type TaxonomyPage array{mode: string, source: ?string, reason: ?string, taxonomy: TaxonomyValues, made: ?string, terms: int, under: int, address: ?string, example: ?string, termsUrl: ?string, options: array{types: list<array{key: string, plural: string, locked: bool}>}}
 */
final readonly class TaxonomiesScreen
{
    /**
     * The list: code's taxonomies in registration order, then the table's
     * rows oldest first, each with its terms and the address Settings keeps.
     *
     * @return array{locked: bool, taxonomies: list<TaxonomyRow>}
     */
    public function list(): array
    {
        $registry = app(TaxonomyRegistry::class);
        $addresses = AddressSettings::current();
        $counts = $this->counts();
        $listed = [];

        foreach ($registry->fromCode() as $taxonomy) {
            $listed[] = $this->row(
                $taxonomy->key, $taxonomy->singular, $taxonomy->plural, $taxonomy->segment, $taxonomy->icon,
                'code', null, $taxonomy->hierarchical, $this->pluralsUsing($taxonomy->key), $this->liveAddress($taxonomy->key, $taxonomy->public, $addresses),
                $counts[$taxonomy->key]['terms'] ?? 0,
            );
        }

        $refused = $registry->refused();

        foreach ($this->rows() as $row) {
            $definition = $this->lenient($row);
            $reason = $refused[$definition->key] ?? null;

            $listed[] = $this->row(
                $definition->key, $definition->singular, $definition->plural, $definition->segment, $definition->icon,
                $reason === null ? 'stored' : 'refused', $reason, $definition->hierarchical,
                $this->usedBy($definition),
                $reason === null ? $this->liveAddress($definition->key, $definition->public, $addresses) : $this->settledAddress($definition),
                $counts[$definition->key]['terms'] ?? 0,
            );
        }

        return ['locked' => TypeAccess::locked(), 'taxonomies' => $listed];
    }

    /**
     * One taxonomy's page: a stored row ahead of a taxonomy code declares,
     * or null when neither exists.
     *
     * @return TaxonomyPage|null
     */
    public function taxonomy(string $key): ?array
    {
        $row = DB::table(StoredTaxonomies::TABLE)->where('key', $key)->first(['key', 'definition', 'created_at']);

        if ($row !== null) {
            return $this->storedPage($row);
        }

        $registry = app(TaxonomyRegistry::class);

        if (! $registry->has($key) || $registry->get($key)->stored) {
            return null;
        }

        return $this->codePage($registry->get($key));
    }

    /**
     * The page for making a taxonomy, with the field's own empty values.
     *
     * @return TaxonomyPage
     */
    public function blank(): array
    {
        return [
            'mode' => 'new',
            'source' => null,
            'reason' => null,
            'taxonomy' => [
                'key' => '',
                'singular' => '',
                'plural' => '',
                'segment' => '',
                'icon' => 'tag',
                'hierarchical' => false,
                'public' => true,
                'base' => null,
                'types' => [],
            ],
            'made' => null,
            'terms' => 0,
            'under' => 0,
            'address' => null,
            'example' => null,
            'termsUrl' => null,
            'options' => ['types' => $this->options('', [], [])],
        ];
    }

    /**
     * The plural a toast names: the row's, or the registered taxonomy's, or
     * the key when the row has none; null when there is no such taxonomy.
     */
    public static function plural(string $key): ?string
    {
        $plural = DB::table(StoredTaxonomies::TABLE)
            ->where('key', $key)
            ->selectRaw("COALESCE(definition ->> 'plural', key) AS plural")
            ->value('plural');

        if (is_string($plural) && $plural !== '') {
            return $plural;
        }

        $registry = app(TaxonomyRegistry::class);

        return $registry->has($key) ? $registry->get($key)->plural : null;
    }

    /**
     * @return list<object>
     */
    private function rows(): array
    {
        return DB::table(StoredTaxonomies::TABLE)
            ->orderBy('created_at')
            ->orderBy('key')
            ->get(['key', 'definition', 'created_at'])
            ->all();
    }

    /**
     * Each taxonomy's terms, and how many of them sit under another.
     *
     * @return array<string, array{terms: int, under: int}>
     */
    private function counts(): array
    {
        $counts = [];

        foreach (DB::table('terms')->groupBy('taxonomy')->selectRaw('taxonomy, count(*) AS terms, count(parent_id) AS under')->get() as $row) {
            $counts[(string) $row->taxonomy] = ['terms' => (int) $row->terms, 'under' => (int) $row->under];
        }

        return $counts;
    }

    /**
     * @param  list<string>  $usedBy
     *
     * @return TaxonomyRow
     */
    private function row(string $key, string $singular, string $plural, string $segment, string $icon, string $source, ?string $reason, bool $hierarchical, array $usedBy, ?string $address, int $terms): array
    {
        return [
            'key' => $key,
            'singular' => $singular,
            'plural' => $plural,
            'segment' => $segment,
            'icon' => $icon,
            'source' => $source,
            'reason' => $reason,
            'hierarchical' => $hierarchical,
            'usedBy' => $usedBy,
            'address' => $address,
            'terms' => $terms,
            'termsUrl' => $source === 'refused' ? null : route('terms.index', ['taxonomy' => $segment], false),
        ];
    }

    /**
     * @return TaxonomyPage
     */
    private function storedPage(object $row): array
    {
        $definition = $this->lenient($row);
        $reason = app(TaxonomyRegistry::class)->refused()[$definition->key] ?? null;
        $counts = $this->counts()[$definition->key] ?? ['terms' => 0, 'under' => 0];
        $types = $this->typesUsing($definition);

        return [
            'mode' => TypeAccess::locked() ? 'view' : 'edit',
            'source' => $reason === null ? 'stored' : 'refused',
            'reason' => $reason,
            'taxonomy' => $this->values($definition, $types),
            'made' => $this->made((string) $row->created_at),
            'terms' => $counts['terms'],
            'under' => $counts['under'],
            'address' => $reason === null
                ? $this->liveAddress($definition->key, $definition->public, AddressSettings::current())
                : $this->settledAddress($definition),
            'example' => $this->example($definition->key),
            'termsUrl' => $reason === null ? route('terms.index', ['taxonomy' => $definition->segment], false) : null,
            'options' => ['types' => $this->options($definition->key, $types, $this->taxonomyTermsInItems($definition->key))],
        ];
    }

    /**
     * @return TaxonomyPage
     */
    private function codePage(Taxonomy $taxonomy): array
    {
        $counts = $this->counts()[$taxonomy->key] ?? ['terms' => 0, 'under' => 0];
        $types = array_map(
            fn (ContentType $type): string => $type->key,
            array_values(array_filter(app(TaxonomyRegistry::class)->typesUsing($taxonomy->key), fn (ContentType $type): bool => $type->listed)),
        );

        return [
            'mode' => 'view',
            'source' => 'code',
            'reason' => null,
            'taxonomy' => [
                'key' => $taxonomy->key,
                'singular' => $taxonomy->singular,
                'plural' => $taxonomy->plural,
                'segment' => $taxonomy->segment,
                'icon' => $taxonomy->icon,
                'hierarchical' => $taxonomy->hierarchical,
                'public' => $taxonomy->public,
                'base' => $taxonomy->base,
                'types' => $types,
            ],
            'made' => null,
            'terms' => $counts['terms'],
            'under' => $counts['under'],
            'address' => $this->liveAddress($taxonomy->key, $taxonomy->public, AddressSettings::current()),
            'example' => $this->example($taxonomy->key),
            'termsUrl' => route('terms.index', ['taxonomy' => $taxonomy->segment], false),
            'options' => ['types' => $this->options($taxonomy->key, $types, [])],
        ];
    }

    /**
     * @param  list<string>  $types
     *
     * @return TaxonomyValues
     */
    private function values(TaxonomyDefinition $definition, array $types): array
    {
        return [
            'key' => $definition->key,
            'singular' => $definition->singular,
            'plural' => $definition->plural,
            'segment' => $definition->segment,
            'icon' => $definition->icon,
            'hierarchical' => $definition->hierarchical,
            'public' => $definition->public,
            'base' => $definition->settled()->base,
            'types' => $types,
        ];
    }

    /**
     * The keys of the listed types that use the taxonomy, in registry order:
     * those whose own list names it, and, for a row the registry refused,
     * the code types its row names.
     *
     * @return list<string>
     */
    private function typesUsing(TaxonomyDefinition $definition): array
    {
        $keys = [];

        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            if (! $type->listed) {
                continue;
            }

            if (in_array($definition->key, $type->taxonomies, true) || in_array($type->key, $definition->codeTypes, true)) {
                $keys[] = $type->key;
            }
        }

        return $keys;
    }

    /**
     * The plurals of the listed types that use a stored taxonomy, registry order.
     *
     * @return list<string>
     */
    private function usedBy(TaxonomyDefinition $definition): array
    {
        $types = app(ContentTypeRegistry::class);

        return array_map(fn (string $key): string => $types->get($key)->plural, $this->typesUsing($definition));
    }

    /**
     * @return list<string>
     */
    private function pluralsUsing(string $taxonomy): array
    {
        return array_values(array_map(
            fn (ContentType $type): string => $type->plural,
            array_filter(app(TaxonomyRegistry::class)->typesUsing($taxonomy), fn (ContentType $type): bool => $type->listed),
        ));
    }

    /**
     * Every listed type, registry order, with whether it is ticked and its
     * items hold the taxonomy's terms, so it can't be taken off.
     *
     * @param  list<string>  $ticked
     * @param  list<string>  $holding  the type keys whose items, trashed ones included, carry a term
     *
     * @return list<array{key: string, plural: string, locked: bool}>
     */
    private function options(string $taxonomy, array $ticked, array $holding): array
    {
        $options = [];

        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            if ($type->listed) {
                $options[] = ['key' => $type->key, 'plural' => $type->plural, 'locked' => in_array($type->key, $ticked, true) && in_array($type->key, $holding, true)];
            }
        }

        return $options;
    }

    /**
     * The types whose items, trashed ones included, carry one of the
     * taxonomy's terms.
     *
     * @return list<string>
     */
    private function taxonomyTermsInItems(string $taxonomy): array
    {
        return DB::table('content_terms')
            ->join('contents', 'contents.id', '=', 'content_terms.content_id')
            ->join('terms', 'terms.id', '=', 'content_terms.term_id')
            ->where('terms.taxonomy', $taxonomy)
            ->groupBy('contents.type')
            ->pluck('contents.type')
            ->map(fn (mixed $type): string => (string) $type)
            ->all();
    }

    /**
     * The first term by name, as the example address's slug, or null.
     */
    private function example(string $taxonomy): ?string
    {
        $slug = DB::table('terms')->where('taxonomy', $taxonomy)->orderBy('name')->orderBy('id')->value('slug');

        return $slug === null ? null : (string) $slug;
    }

    private function liveAddress(string $key, bool $public, AddressSettings $addresses): ?string
    {
        if (! $public || ! $addresses->hasBase($key)) {
            return null;
        }

        return $this->addressFrom($addresses->base($key));
    }

    private function settledAddress(TaxonomyDefinition $definition): ?string
    {
        $settled = $definition->settled();

        return $settled->base === null ? null : $this->addressFrom($settled->base);
    }

    private function addressFrom(string $base): string
    {
        return $base === '' ? '/{slug}/' : '/'.$base.'/{slug}/';
    }

    private function lenient(object $row): TaxonomyDefinition
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

        return TaxonomyDefinition::lenient(['key' => (string) $row->key, ...$decoded]);
    }

    private function made(string $createdAt): string
    {
        $zone = app(SettingsReader::class)->string('general', 'timezone');

        return CarbonImmutable::parse($createdAt, (string) config('app.timezone'))->timezone($zone)->format('j F Y');
    }
}
