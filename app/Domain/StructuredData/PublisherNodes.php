<?php

declare(strict_types=1);

namespace App\Domain\StructuredData;

use App\Domain\Addresses\Addresses;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\SiteLanguage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\StructuredData\NodeIds;

/**
 * Who publishes the site, as structured data (I-708, I-725): the
 * organization, local business or person the site represents, its logo
 * and the organization that owns it, and the reference every page's
 * WebSite and every article give as their publisher. Nothing for nobody in
 * particular, or for a person whose account is gone (I-878). Resolved once
 * a page, for its view model, as PageHead resolves the head's values.
 */
final class PublisherNodes
{
    private const array NONE = ['reference' => null, 'nodes' => [], 'siteAlternateName' => null];

    /** The view model whose publisher is kept, compared by identity. */
    private ?object $model = null;

    /** @var array{reference: array{'@id': string}|null, nodes: list<JsonLdNode>, siteAlternateName: string|null}|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly SettingsReader $settings,
        private readonly ImageObjects $images,
        private readonly SiteLanguage $language,
    ) {}

    /**
     * The publisher for the page drawing this view model: the reference to
     * give as `publisher`, the nodes to add to the graph, and the site's
     * other name for its WebSite; null, none and null for nobody.
     *
     * @return array{reference: array{'@id': string}|null, nodes: list<JsonLdNode>, siteAlternateName: string|null}
     */
    public function for(?object $model): array
    {
        if ($model !== null && $model === $this->model && $this->resolved !== null) {
            return $this->resolved;
        }

        $this->resolved = $this->resolve();
        $this->model = $model;

        return $this->resolved;
    }

    /**
     * @return array{reference: array{'@id': string}|null, nodes: list<JsonLdNode>, siteAlternateName: string|null}
     */
    private function resolve(): array
    {
        $values = $this->settings->group(SearchAndSocial::KEY);
        $kind = self::text($values[WhoPublishes::KIND] ?? null);

        return match (true) {
            $kind === PublisherKinds::PERSON => $this->person($values) ?? self::NONE,
            PublisherKinds::isOrganization($kind) => $this->organization($kind, $values),
            default => self::NONE,
        };
    }

    /**
     * An organization or a local business at {home}#organization, its kind
     * its @type, with its logo and its owner as nodes of their own.
     *
     * @param  array<string, mixed>  $values
     *
     * @return array{reference: array{'@id': string}, nodes: list<JsonLdNode>, siteAlternateName: string|null}
     */
    private function organization(string $kind, array $values): array
    {
        $home = GraphAddresses::home();
        $id = $home.NodeIds::ORGANIZATION;
        $siteTitle = $this->settings->string('general', 'site_title');
        $own = self::text($values[WhoPublishes::NAME] ?? null);
        $name = $own !== '' ? $own : $siteTitle;
        $other = self::text($values[WhoPublishes::OTHER_NAME] ?? null);
        $logo = $this->logo($values[WhoPublishes::LOGO] ?? null);
        $owner = self::owner($values);

        $properties = [
            '@id' => $id,
            '@type' => $kind,
            'name' => $name,
            'alternateName' => $other,
            'url' => $home,
            'logo' => $logo === null ? null : ['@id' => $logo->id()],
            'sameAs' => self::addresses($values[WhoPublishes::PROFILES] ?? null),
            'parentOrganization' => $owner === null ? null : ['@id' => $owner->id()],
        ];

        $nodes = [new JsonLdNode(PublisherKinds::isBusiness($kind) ? [...$properties, ...self::business($values)] : $properties)];

        foreach ([$logo, $owner] as $node) {
            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return [
            'reference' => ['@id' => $id],
            'nodes' => $nodes,
            // Also the site's other name while the organization goes by the site's own (I-708).
            'siteAlternateName' => $name === $siteTitle && $other !== '' ? $other : null,
        ];
    }

    /**
     * The logo at {home}#logo (I-875), or null with none or once its image
     * is gone; the page records the image's tag either way.
     */
    private function logo(mixed $mediaId): ?JsonLdNode
    {
        $image = is_string($mediaId) && $mediaId !== '' ? $this->images->for($mediaId) : null;

        return $image === null ? null : new JsonLdNode(['@id' => GraphAddresses::home().NodeIds::LOGO, ...$image['object'], 'inLanguage' => $this->language->tag()]);
    }

    /**
     * The organization that owns it, at {its website}#organization, the
     *
     * @id its own site would give itself (I-725): the website without its
     * query or fragment, ending in a slash. Null without a name and a
     * website.
     *
     * @param  array<string, mixed>  $values
     */
    private static function owner(array $values): ?JsonLdNode
    {
        $name = self::text($values[WhoPublishes::OWNER_NAME] ?? null);
        $website = (string) preg_replace('/[?#].*$/s', '', self::text($values[WhoPublishes::OWNER_URL] ?? null));

        if ($name === '' || preg_match(WhoPublishes::WEBSITE, $website) !== 1) {
            return null;
        }

        $home = str_ends_with($website, '/') ? $website : $website.'/';

        return new JsonLdNode([
            '@id' => $home.NodeIds::ORGANIZATION,
            '@type' => 'Organization',
            'name' => $name,
            'url' => $home,
            'sameAs' => self::addresses($values[WhoPublishes::OWNER_PROFILES] ?? null),
        ]);
    }

    /**
     * A local business's own properties (I-708): its address, its phone,
     * its place, its price range and its week.
     *
     * @param  array<string, mixed>  $values
     *
     * @return array<string, mixed>
     */
    private static function business(array $values): array
    {
        $address = array_filter([
            'streetAddress' => self::text($values[WhoPublishes::STREET] ?? null),
            'addressLocality' => self::text($values[WhoPublishes::LOCALITY] ?? null),
            'addressRegion' => self::text($values[WhoPublishes::REGION] ?? null),
            'postalCode' => self::text($values[WhoPublishes::POSTCODE] ?? null),
            'addressCountry' => mb_strtoupper(self::text($values[WhoPublishes::COUNTRY] ?? null)),
        ], fn (string $part): bool => $part !== '');
        $latitude = self::text($values[WhoPublishes::LATITUDE] ?? null);
        $longitude = self::text($values[WhoPublishes::LONGITUDE] ?? null);

        return [
            // No part of the address writes none, never a lone @type, so the gate leaves the business out (I-703).
            'address' => $address === [] ? null : ['@type' => 'PostalAddress', ...$address],
            'telephone' => self::text($values[WhoPublishes::PHONE] ?? null),
            'geo' => $latitude === '' || $longitude === '' ? null : ['@type' => 'GeoCoordinates', 'latitude' => $latitude, 'longitude' => $longitude],
            'priceRange' => self::text($values[WhoPublishes::PRICE_RANGE] ?? null),
            'openingHoursSpecification' => OpeningHours::specification($values[WhoPublishes::HOURS] ?? []),
        ];
    }

    /**
     * The person the site represents, at {their author address}#person
     * (I-696), their name and bio from their profile, read in one
     * statement; null when nobody is chosen, their account is gone or they
     * have no address (I-878). The page records their tag first, so their
     * rename and their deletion purge it.
     *
     * @param  array<string, mixed>  $values
     *
     * @return array{reference: array{'@id': string}, nodes: list<JsonLdNode>, siteAlternateName: null}|null
     */
    private function person(array $values): ?array
    {
        $id = self::text($values[WhoPublishes::PERSON] ?? null);

        if (preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
            return null;
        }

        app(RenderRecord::class)->tag(PageTags::person($id));
        $row = DB::table('users')
            ->select(['name', 'bio'])
            ->selectSub(fn (Builder $paths) => $paths->from('paths')->select('path')->where('target_type', 'user')->whereRaw('target_id = users.id::text')->where('is_current', true)->limit(1), 'path')
            ->where('id', (int) $id)
            ->first();

        if ($row === null || ! is_string($row->path)) {
            return null;
        }

        $address = GraphAddresses::absolute(Addresses::toUrl($row->path));
        $node = new JsonLdNode([
            '@id' => $address.NodeIds::PERSON,
            '@type' => 'Person',
            'name' => (string) $row->name,
            'url' => $address,
            'description' => is_string($row->bio) ? $row->bio : null,
            'sameAs' => self::addresses($values[WhoPublishes::PROFILES] ?? null),
        ]);

        return ['reference' => ['@id' => $node->id()], 'nodes' => [$node], 'siteAlternateName' => null];
    }

    /**
     * A urls setting's addresses as stored.
     *
     * @return list<string>
     */
    private static function addresses(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, fn (mixed $address): bool => is_string($address) && $address !== '')) : [];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
