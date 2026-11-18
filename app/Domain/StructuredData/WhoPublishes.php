<?php

/**
 * Search and social's section "Who publishes the site" (I-708, I-725):
 * nobody in particular, an organization, a local business or a person, and
 * what search engines are told of it. Each field shows only for the kinds
 * it describes (I-874), and keeps its value while hidden. It checks its
 * own values on save (I-708).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\StructuredData;

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use App\Domain\Settings\SettingsCheck;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;

final class WhoPublishes implements SearchAndSocialSection, SettingsCheck
{
    public const string KIND = 'publisher_type';

    public const string NAME = 'publisher_name';

    public const string OTHER_NAME = 'publisher_alternate_name';

    public const string LOGO = 'publisher_logo';

    public const string PROFILES = 'publisher_profiles';

    public const string PERSON = 'publisher_person';

    public const string STREET = 'business_street_address';

    public const string LOCALITY = 'business_locality';

    public const string REGION = 'business_region';

    public const string POSTCODE = 'business_postal_code';

    public const string COUNTRY = 'business_country';

    public const string PHONE = 'business_phone';

    public const string LATITUDE = 'business_latitude';

    public const string LONGITUDE = 'business_longitude';

    public const string PRICE_RANGE = 'business_price_range';

    public const string HOURS = 'business_hours';

    public const string OWNER_NAME = 'publisher_owner_name';

    public const string OWNER_URL = 'publisher_owner_url';

    public const string OWNER_PROFILES = 'publisher_owner_profiles';

    /** A logo's least width and height, in pixels (I-875). */
    public const int LOGO_SMALLEST = 112;

    /** A website on another site: http or https, a host, then anything without spaces. */
    public const string WEBSITE = '~^https?://[^\s/?#@]+(?:[/?#]\S*)?$~iu';

    public function order(): int
    {
        return 300;
    }

    public function key(): string
    {
        return 'who-publishes';
    }

    public function label(): string
    {
        return 'Who publishes the site';
    }

    public function settings(): array
    {
        $since = '0.1.0';
        $organizations = [self::KIND => [...array_keys(PublisherKinds::ORGANIZATIONS), ...array_keys(PublisherKinds::BUSINESSES)]];
        $publishers = [self::KIND => [...array_keys(PublisherKinds::ORGANIZATIONS), ...array_keys(PublisherKinds::BUSINESSES), PublisherKinds::PERSON]];
        $businesses = [self::KIND => array_keys(PublisherKinds::BUSINESSES)];

        return [
            Setting::select(self::KIND, 'The site represents', 'Search engines are told it publishes the site and every article on it. Choose the most specific kind that fits. Nobody in particular names no publisher.', $since, PublisherKinds::options()),
            Setting::text(self::NAME, 'Name', 'As the organization calls itself. Empty uses the site’s title.', $since)->maxLength(200)->visibleWhen($organizations),
            Setting::text(self::OTHER_NAME, 'Other name', 'A shorter name or initials it also goes by, if any. Search engines may show it in place of the name.', $since)->maxLength(200)->visibleWhen($organizations),
            Setting::media(self::LOGO, 'Logo', 'At least 112 pixels wide and high, and clear on a white background; a square suits most. Search engines may show it beside the site.', $since)->visibleWhen($organizations),
            Setting::urls(self::PROFILES, 'Profile links', 'Its pages on other sites, one address a line: social profiles, a Wikipedia article, a Wikidata entry. They help search engines tell who publishes the site.', $since)->max(20)->visibleWhen($publishers),
            Setting::person(self::PERSON, 'Person', 'From the people with an account here. Search engines are told their name and bio, from their profile, and that their author page is about them.', $since)->visibleWhen([self::KIND => PublisherKinds::PERSON]),
            Setting::text(self::STREET, 'Street address', 'The building’s number and street. Search engines leave out a business without one.', $since)->maxLength(200)->visibleWhen($businesses),
            Setting::text(self::LOCALITY, 'Town or city', 'Where the business is.', $since)->maxLength(100)->visibleWhen($businesses),
            Setting::text(self::REGION, 'County, state or region', 'If addresses there use one.', $since)->maxLength(100)->visibleWhen($businesses),
            Setting::text(self::POSTCODE, 'Postcode or ZIP code', 'As the post uses it.', $since)->maxLength(20)->visibleWhen($businesses),
            Setting::text(self::COUNTRY, 'Country', 'Its two-letter code, such as GB, US or NG.', $since)->maxLength(2)->visibleWhen($businesses),
            Setting::text(self::PHONE, 'Phone', 'With the country code, such as +44 20 7946 0000.', $since)->maxLength(40)->visibleWhen($businesses),
            Setting::text(self::LATITUDE, 'Latitude', 'Where it is on a map, in degrees, such as 51.50740. Most map services show it when you choose the place.', $since)->maxLength(20)->visibleWhen($businesses),
            Setting::text(self::LONGITUDE, 'Longitude', 'In degrees, such as -0.12780. Give both, or neither.', $since)->maxLength(20)->visibleWhen($businesses),
            Setting::text(self::PRICE_RANGE, 'Price range', 'How expensive it is, as currency signs or a range, such as £££ or £10–20.', $since)->maxLength(50)->visibleWhen($businesses),
            Setting::hours(self::HOURS, 'Opening hours', 'A week’s hours, in 24-hour time. A day left empty says nothing about it; a closing time earlier than the opening time runs past midnight.', $since)->visibleWhen($businesses),
            Setting::text(self::OWNER_NAME, 'Owner', 'The organization that owns this one, such as the group a newspaper belongs to.', $since)->maxLength(200)->visibleWhen($organizations),
            Setting::url(self::OWNER_URL, 'Owner’s website', 'Its home page’s address. Search engines take it to name the owner, as the owner’s own site does.', $since)->visibleWhen($organizations),
            Setting::urls(self::OWNER_PROFILES, 'Owner’s profile links', 'Its pages on other sites, one address a line.', $since)->max(20)->visibleWhen($organizations),
        ];
    }

    /**
     * The kind the site says it is, `''` for nobody in particular or a kind
     * Teme doesn't know: read through the settings, so a page that asks
     * carries the group's tag.
     */
    public static function kind(): string
    {
        $kind = app(SettingsReader::class)->get(SearchAndSocial::KEY, self::KIND);

        return is_string($kind) && array_key_exists($kind, PublisherKinds::options()) ? $kind : PublisherKinds::NOBODY;
    }

    public function group(): string
    {
        return SearchAndSocial::KEY;
    }

    /**
     * What would leave search engines a publisher they can't use: a person
     * not chosen, a business without its street or town, a place given by
     * half, a phone or country that isn't one, an owner with half a name, a
     * logo too small. Only what the chosen kind shows is checked (I-874).
     */
    public function problems(array $values): array
    {
        // A field the kind hides isn't sent, so what is stored stands in for it.
        $effective = [...app(SettingsReader::class)->group(SearchAndSocial::KEY), ...$values];
        $kind = self::text($effective[self::KIND] ?? null);
        $problems = [];

        if ($kind === PublisherKinds::PERSON && self::text($effective[self::PERSON] ?? null) === '') {
            $problems['values.'.self::PERSON] = 'Choose the person the site represents, or Nobody in particular.';
        }

        if (PublisherKinds::isOrganization($kind)) {
            $problems += self::logoProblem($effective[self::LOGO] ?? null);
            $problems += self::ownerProblems($effective);
        }

        if (PublisherKinds::isBusiness($kind)) {
            $problems += self::businessProblems($effective);
        }

        return $problems;
    }

    /**
     * The logo's size, if it is too small; a value that isn't a live image
     * is left to the media type's own rule.
     *
     * @return array<string, string>
     */
    private static function logoProblem(mixed $id): array
    {
        if (! is_string($id) || $id === '') {
            return [];
        }

        $size = DB::table('media')->where('id', $id)->where('kind', 'image')->whereNull('deleted_at')->first(['width', 'height']);

        if ($size === null || ((int) $size->width >= self::LOGO_SMALLEST && (int) $size->height >= self::LOGO_SMALLEST)) {
            return [];
        }

        return ['values.'.self::LOGO => sprintf('The logo must be at least %1$d × %1$d pixels; this one is %2$d × %3$d.', self::LOGO_SMALLEST, (int) $size->width, (int) $size->height)];
    }

    /**
     * The owner's name and website go together, and the website is another
     * site's (I-725).
     *
     * @param  array<string, mixed>  $values
     *
     * @return array<string, string>
     */
    private static function ownerProblems(array $values): array
    {
        $name = self::text($values[self::OWNER_NAME] ?? null);
        $website = self::text($values[self::OWNER_URL] ?? null);

        return match (true) {
            $name === '' && $website === '' => [],
            $website === '' => ['values.'.self::OWNER_URL => 'Give the owner’s website too, so search engines can tell which organization it is.'],
            $name === '' => ['values.'.self::OWNER_NAME => 'Give the owner’s name too.'],
            preg_match(self::WEBSITE, $website) !== 1 => ['values.'.self::OWNER_URL => 'Give the owner’s website as a full address, starting https://.'],
            self::onThisSite($website) => ['values.'.self::OWNER_URL => 'The owner’s website is this site. Leave the owner empty unless another organization owns this one.'],
            default => [],
        };
    }

    /**
     * A business's street and town, which Google requires (I-703), and its
     * phone, country and place, when given.
     *
     * @param  array<string, mixed>  $values
     *
     * @return array<string, string>
     */
    private static function businessProblems(array $values): array
    {
        $problems = [];

        if (self::text($values[self::STREET] ?? null) === '') {
            $problems['values.'.self::STREET] = 'A business needs its street address: search engines leave out a business without one.';
        }

        if (self::text($values[self::LOCALITY] ?? null) === '') {
            $problems['values.'.self::LOCALITY] = 'A business needs its town or city: search engines leave out a business without one.';
        }

        $phone = self::text($values[self::PHONE] ?? null);

        if ($phone !== '' && (preg_match('/^[0-9 +().-]+$/', $phone) !== 1 || preg_match_all('/[0-9]/', $phone) < 5)) {
            $problems['values.'.self::PHONE] = 'Write the phone number in digits, spaces and + - ( ) only, such as +44 20 7946 0000.';
        }

        $country = self::text($values[self::COUNTRY] ?? null);

        if ($country !== '' && preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
            $problems['values.'.self::COUNTRY] = 'Give the country as its two-letter code, such as GB, US or NG.';
        }

        return $problems + self::placeProblems(self::text($values[self::LATITUDE] ?? null), self::text($values[self::LONGITUDE] ?? null));
    }

    /**
     * Latitude and longitude: both or neither, each degrees within its range.
     *
     * @return array<string, string>
     */
    private static function placeProblems(string $latitude, string $longitude): array
    {
        return match (true) {
            $latitude === '' && $longitude === '' => [],
            $longitude === '' => ['values.'.self::LONGITUDE => 'Give the longitude too, or clear the latitude.'],
            $latitude === '' => ['values.'.self::LATITUDE => 'Give the latitude too, or clear the longitude.'],
            ! self::degrees($latitude, 90) => ['values.'.self::LATITUDE => 'Write the latitude in degrees, from -90 to 90, such as 51.50740.'],
            ! self::degrees($longitude, 180) => ['values.'.self::LONGITUDE => 'Write the longitude in degrees, from -180 to 180, such as -0.12780.'],
            default => [],
        };
    }

    /** Whether the text is a number of degrees within the limit either way. */
    private static function degrees(string $value, int $limit): bool
    {
        return preg_match('/^-?[0-9]{1,3}(?:\.[0-9]+)?$/', $value) === 1 && abs((float) $value) <= $limit;
    }

    /** Whether an address is on the site's own host. */
    private static function onThisSite(string $address): bool
    {
        $host = parse_url($address, PHP_URL_HOST);

        return is_string($host) && mb_strtolower($host) === mb_strtolower((string) parse_url(url('/'), PHP_URL_HOST));
    }

    /** A value as trimmed text; anything that isn't text is empty, and left to its type's rule. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
