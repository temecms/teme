<?php

declare(strict_types=1);

namespace App\Domain\Api\Catalogue;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Api\Envelope;
use App\Domain\Api\Live;
use App\Domain\Site\SiteLanguage;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The site as the API names it (D28): its title, tagline, address, language,
 * zone and what its home is, from six named settings and nothing else, so no
 * secret, no administration email and no version can reach an answer.
 */
final readonly class SiteShape
{
    public function __construct(private SettingsReader $settings, private SiteLanguage $language) {}

    /**
     * The site's `data` member.
     *
     * @return array{name: string, tagline: string, url: string, language: string, timezone: string, home: array{shows: string, page_id: string|null, posts_page_id: string|null}}
     */
    public function data(): array
    {
        $front = AddressSettings::current();
        $live = $this->liveIds([$front->frontPageId, $front->postsPageId]);

        return [
            'name' => $this->settings->string('general', 'site_title'),
            'tagline' => $this->settings->string('general', 'tagline'),
            'url' => rtrim(url('/'), '/').'/',
            'language' => $this->language->tag(),
            'timezone' => $this->settings->string('general', 'timezone'),
            'home' => [
                'shows' => $front->frontShows,
                'page_id' => in_array($front->frontPageId, $live, true) ? $front->frontPageId : null,
                'posts_page_id' => in_array($front->postsPageId, $live, true) ? $front->postsPageId : null,
            ],
        ];
    }

    /**
     * The site's `links` member.
     *
     * @return array<string, string>
     */
    public function links(): array
    {
        return [
            'self' => Envelope::link('api.v1.site', []),
            'items' => self::itemsLink(),
            'types' => Envelope::link('api.v1.types', []),
            'taxonomies' => Envelope::link('api.v1.taxonomies', []),
            'terms' => self::under('terms'),
        ];
    }

    /**
     * The address of the items list with this query, made from the site's
     * own address, since the list's route is another part's.
     *
     * @param  array<string, string>  $query
     */
    public static function itemsLink(array $query = []): string
    {
        return self::under('items', $query);
    }

    /**
     * The address of a list under the API's own, with this query, sorted by
     * name and encoded by RFC 3986 as Envelope::link() does.
     *
     * @param  array<string, string>  $query
     */
    public static function under(string $segment, array $query = []): string
    {
        $address = Envelope::link('api.v1.site', []).'/'.$segment;

        if ($query === []) {
            return $address;
        }

        ksort($query, SORT_STRING);

        return $address.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Which of these item IDs a reader can see, in one statement.
     *
     * @param  list<string>  $ids
     *
     * @return list<string>
     */
    private function liveIds(array $ids): array
    {
        $ids = array_values(array_filter($ids, fn (string $id): bool => $id !== ''));

        if ($ids === []) {
            return [];
        }

        return array_map(fn (mixed $id): string => (string) $id, Live::items()->whereIn('contents.id', $ids)->pluck('contents.id')->all());
    }
}
