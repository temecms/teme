<?php

declare(strict_types=1);

namespace App\Domain\Sitemaps;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\ListingTypes;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Cache\PageTags;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Site\ListingViews;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;

/**
 * A child for each public taxonomy with an archive base and a listed term
 * (I-296): its terms in ID order, each at its archive's address. A taxonomy
 * its hide box hides has none, as its archives say noindex.
 */
final readonly class TermsSitemap implements SitemapProvider
{
    public const string PREFIX = 'terms-';

    /**
     * A taxonomy's listed terms (the first binding), as their archives are:
     * at a current address, holding live items of a type that uses them
     * (one binding a type, $types of them), as ListingViews::forTerm() counts them;
     * the default language's alone while the site has several (I-3360). A
     * taxonomy that isn't translated has every language's items under one
     * term, so its terms are asked of the default language's items instead
     * of term_counts, which counts them all (ListingViews::countsInDefault()).
     */
    private static function listed(int $types, string $taxonomy): string
    {
        $in = implode(', ', array_fill(0, $types, '?'));
        $default = app(SiteLanguages::class)->defaultOnlySql('term.language');
        $holds = ListingViews::countsInDefault($taxonomy)
            ? <<<SQL
                SELECT 1 FROM content_terms filed JOIN contents item ON item.id = filed.content_id
                WHERE filed.term_id = term.id AND filed.scope = 'published' AND item.type IN ({$in}) AND item.language IS NULL
                    AND item.status = 'published' AND item.deleted_at IS NULL AND item.published_at <= now() AND item.visibility <> 'private'
                SQL
            : <<<SQL
                SELECT 1 FROM term_counts counted
                WHERE counted.term_id = term.id AND counted.type IN ({$in}) AND counted.published > 0
                SQL;

        return <<<SQL
            FROM terms term
            JOIN paths address ON address.target_type = 'term' AND address.target_id = term.id::text AND address.is_current
            WHERE term.taxonomy = ? AND EXISTS (
                {$holds}
            ){$default}
            SQL;
    }

    public function __construct(private TaxonomyRegistry $taxonomies) {}

    public function names(): array
    {
        $addresses = AddressSettings::current();
        $names = [];

        foreach ($this->taxonomies->all() as $taxonomy) {
            $types = ListingTypes::usingTaxonomy($taxonomy->key);

            if ($types === [] || ! $taxonomy->public || ! $addresses->hasBase($taxonomy->key) || TitlesAndListing::hidesTaxonomy($taxonomy->key)) {
                continue;
            }

            if (DB::selectOne('SELECT EXISTS (SELECT 1 '.self::listed(count($types), $taxonomy->key).') AS found', [$taxonomy->key, ...$types])?->found === true) {
                $names[] = self::PREFIX.$taxonomy->key;
            }
        }

        return $names;
    }

    public function starts(string $name): array
    {
        $types = ListingTypes::usingTaxonomy(self::taxonomy($name));

        if ($types === []) {
            return [];
        }

        $sql = 'SELECT id FROM (SELECT term.id, row_number() OVER (ORDER BY term.id) AS n '.self::listed(count($types), self::taxonomy($name)).') listed WHERE n > 1 AND n % ? = 1 ORDER BY id';

        return array_map(fn (object $row): string => (string) $row->id, DB::select($sql, [self::taxonomy($name), ...$types, ListedItems::PAGE]));
    }

    public function page(string $name, ?string $from): SitemapPage
    {
        $types = ListingTypes::usingTaxonomy(self::taxonomy($name));

        if ($types === []) {
            return new SitemapPage([]);
        }

        $rows = DB::select('SELECT term.id, address.path '.self::listed(count($types), self::taxonomy($name)).' AND term.id >= ? ORDER BY term.id LIMIT ?', [self::taxonomy($name), ...$types, $from ?? '', ListedItems::PAGE]);
        $root = rtrim(url('/'), '/');

        return new SitemapPage(
            array_map(fn (object $row): SitemapAddress => new SitemapAddress($root.PublicRequestPath::encode(Addresses::toUrl((string) $row->path))), $rows),
            array_map(fn (object $row): string => PageTags::term((string) $row->id), $rows),
        );
    }

    public function tags(string $name): array
    {
        return array_map(PageTags::listOf(...), ListingTypes::usingTaxonomy(self::taxonomy($name)));
    }

    private static function taxonomy(string $name): string
    {
        return substr($name, strlen(self::PREFIX));
    }
}
