<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Site\Document\DocumentMeta;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\Withheld;
use App\Domain\StructuredData\OpeningHours;
use App\Domain\StructuredData\PublisherNodes;
use App\Domain\StructuredData\Vocabulary;
use App\Domain\Users\Operations\DeleteUser;
use App\Domain\Users\Operations\Input\DeleteUserInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->jane = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->jane->forceFill(['bio' => 'Covers the harbour board.'])->save();
    app(PathProjection::class)->syncUser($this->jane);
    $this->writer = User::factory()->create(['name' => 'Ada Obi'])->assignRole('author');
    $this->home = 'http://localhost/';
    $this->organization = 'http://localhost/#organization';
    $this->represent = fn (array $values) => cmSettings($values, SearchAndSocial::KEY);
    $this->restaurant = [
        'publisher_type' => 'Restaurant', 'publisher_name' => 'Harbour Kitchen',
        'business_street_address' => '12 Aba Road', 'business_locality' => 'Port Harcourt', 'business_region' => 'Rivers',
        'business_postal_code' => '500272', 'business_country' => 'ng', 'business_phone' => '+234 803 000 0000',
        'business_latitude' => '4.81500', 'business_longitude' => '7.04980', 'business_price_range' => '₦₦',
        'business_hours' => [
            ['day' => 'monday', 'hours' => [['opens' => '09:00', 'closes' => '12:30'], ['opens' => '14:00', 'closes' => '18:00']], 'closed' => false],
            ['day' => 'tuesday', 'hours' => [['opens' => '09:00', 'closes' => '12:30'], ['opens' => '14:00', 'closes' => '18:00']], 'closed' => false],
            ['day' => 'saturday', 'hours' => [['opens' => '10:00', 'closes' => '16:00']], 'closed' => false],
            ['day' => 'sunday', 'hours' => [], 'closed' => true],
        ],
    ];
});

it('names no publisher while the site represents nobody in particular, as a new site does (I-708)', function (): void {
    headPost($this->writer, 'Harbour news');

    $graph = sdGraph('/harbour-news/');

    expect(sdNode($graph, $this->home.'#website'))->not->toHaveKey('publisher')
        ->and(sdNode($graph, $this->organization))->toBeNull()
        ->and(sdNode($graph, 'http://localhost/harbour-news/#article'))->not->toHaveKey('publisher');
});

it('describes an organization at the site\'s address, named as the site, as the publisher of the site and of every article (I-696, I-708)', function (): void {
    ($this->represent)(['publisher_type' => 'NewsMediaOrganization', 'publisher_alternate_name' => 'TL', 'publisher_profiles' => ['https://x.com/harbourledger', 'https://www.wikidata.org/wiki/Q1']]);
    headPost($this->writer, 'Harbour news');

    $graph = sdGraph('/harbour-news/');

    expect(sdNode($graph, $this->organization))->toEqual([
        '@id' => $this->organization, '@type' => 'NewsMediaOrganization', 'name' => pageSiteName(), 'alternateName' => 'TL',
        'url' => $this->home, 'sameAs' => ['https://x.com/harbourledger', 'https://www.wikidata.org/wiki/Q1'],
    ])
        ->and(sdNode($graph, $this->home.'#website'))->toMatchArray(['publisher' => ['@id' => $this->organization], 'alternateName' => 'TL'])
        ->and(sdNode($graph, 'http://localhost/harbour-news/#article')['publisher'])->toBe(['@id' => $this->organization]);
});

it('keeps the organization\'s other name off the WebSite when it goes by a name of its own (I-708)', function (): void {
    ($this->represent)(['publisher_type' => 'Corporation', 'publisher_name' => 'Harbour Ledger Limited', 'publisher_alternate_name' => 'HLL']);

    $graph = sdGraph('/');

    expect(sdNode($graph, $this->organization))->toMatchArray(['@type' => 'Corporation', 'name' => 'Harbour Ledger Limited', 'alternateName' => 'HLL'])
        ->and(sdNode($graph, $this->home.'#website'))->not->toHaveKey('alternateName')
        ->and(sdNode($graph, $this->home.'#website')['publisher'])->toBe(['@id' => $this->organization]);
});

it('gives the organization its logo at the site\'s #logo, at its widest size, and none once the image is in the trash (I-875)', function (): void {
    $logo = pageImage($this->writer, 600, 600);
    ($this->represent)(['publisher_type' => 'Organization', 'publisher_logo' => $logo->id]);
    $widths = array_filter((array) $logo->refresh()->variants, fn (string $key): bool => preg_match('/^w\d+$/', $key) === 1, ARRAY_FILTER_USE_KEY);
    uasort($widths, fn (array $a, array $b): int => $b['width'] <=> $a['width']);
    $url = url(MediaDisks::public()->url(reset($widths)['path']));

    $graph = sdGraph('/');

    expect(sdNode($graph, $this->organization)['logo'])->toBe(['@id' => $this->home.'#logo'])
        ->and(sdNode($graph, $this->home.'#logo'))->toEqual([
            '@id' => $this->home.'#logo', '@type' => 'ImageObject', 'url' => $url, 'contentUrl' => $url,
            'width' => 600, 'height' => 600, 'inLanguage' => 'en',
        ]);

    app(TrashMedia::class)->execute(new MediaActionInput($logo->id, (string) $this->writer->id));
    $trashed = sdGraph('/');

    expect(sdNode($trashed, $this->home.'#logo'))->toBeNull()
        ->and(sdNode($trashed, $this->organization))->not->toHaveKey('logo');
});

it('describes a local business with its address, phone, place on the map, price range and week, each opening once with every day it keeps (I-708, I-877)', function (): void {
    ($this->represent)($this->restaurant);

    expect(sdNode(sdGraph('/'), $this->organization))->toEqual([
        '@id' => $this->organization, '@type' => 'Restaurant', 'name' => 'Harbour Kitchen', 'url' => $this->home,
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => '12 Aba Road', 'addressLocality' => 'Port Harcourt', 'addressRegion' => 'Rivers', 'postalCode' => '500272', 'addressCountry' => 'NG'],
        'telephone' => '+234 803 000 0000',
        'geo' => ['@type' => 'GeoCoordinates', 'latitude' => '4.81500', 'longitude' => '7.04980'],
        'priceRange' => '₦₦',
        'openingHoursSpecification' => [
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday'], 'opens' => '09:00', 'closes' => '12:30'],
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday'], 'opens' => '14:00', 'closes' => '18:00'],
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Saturday'], 'opens' => '10:00', 'closes' => '16:00'],
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Sunday'], 'opens' => '00:00', 'closes' => '00:00'],
        ],
    ]);
});

it('writes an opening past midnight as it is, and nothing for a week that says nothing (I-877)', function (): void {
    expect(OpeningHours::specification([
        ['day' => 'friday', 'hours' => [['opens' => '18:00', 'closes' => '02:00']], 'closed' => false],
        ['day' => 'saturday', 'hours' => [['opens' => '18:00', 'closes' => '02:00']], 'closed' => false],
    ]))->toBe([
        ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Friday', 'Saturday'], 'opens' => '18:00', 'closes' => '02:00'],
    ])
        ->and(OpeningHours::specification([]))->toBe([]);
});

it('names the organization that owns it at the @id its own site would give itself, with its own profile links (I-725)', function (): void {
    ($this->represent)(['publisher_type' => 'NewsMediaOrganization', 'publisher_owner_name' => 'Harbour Media Group', 'publisher_owner_url' => 'https://harbourmedia.example', 'publisher_owner_profiles' => ['https://x.com/harbourmedia']]);
    $owner = 'https://harbourmedia.example/#organization';

    $graph = sdGraph('/');

    expect(sdNode($graph, $this->organization)['parentOrganization'])->toBe(['@id' => $owner])
        ->and(sdNode($graph, $owner))->toEqual(['@id' => $owner, '@type' => 'Organization', 'name' => 'Harbour Media Group', 'url' => 'https://harbourmedia.example/', 'sameAs' => ['https://x.com/harbourmedia']]);
});

it('represents a person at their author address, their name and bio from their profile, named once on their own article and page (I-696, I-708)', function (): void {
    ($this->represent)(['publisher_type' => 'Person', 'publisher_person' => (string) $this->jane->id, 'publisher_profiles' => ['https://x.com/janedoe']]);
    headPost($this->jane, 'Harbour news');
    $person = 'http://localhost/author/jane-doe/#person';

    $article = sdGraph('/harbour-news/');
    $profile = sdGraph('/author/jane-doe/');

    expect(sdNode($article, $person))->toEqual(['@id' => $person, '@type' => 'Person', 'name' => 'Jane Doe', 'url' => 'http://localhost/author/jane-doe/', 'description' => 'Covers the harbour board.', 'sameAs' => ['https://x.com/janedoe']])
        ->and(array_filter($article, fn (array $node): bool => $node['@id'] === $person))->toHaveCount(1)
        ->and(sdNode($article, $this->home.'#website')['publisher'])->toBe(['@id' => $person])
        ->and(sdNode($article, 'http://localhost/harbour-news/#article'))->toMatchArray(['author' => [['@id' => $person]], 'publisher' => ['@id' => $person]])
        ->and(sdNode($article, $this->organization))->toBeNull()
        ->and(sdNode($profile, 'http://localhost/author/jane-doe/')['mainEntity'])->toBe(['@id' => $person])
        ->and(sdNode($profile, $person)['sameAs'])->toBe(['https://x.com/janedoe']);
});

it('falls back to nobody in particular once the person\'s account is deleted, and purges every stored page that named them (I-878)', function (): void {
    $folder = cacheOn();
    ($this->represent)(['publisher_type' => 'Person', 'publisher_person' => (string) $this->jane->id]);
    headPost($this->writer, 'Harbour news');
    addrGet('/harbour-news/')->assertOk();

    expect(cacheEntry('/harbour-news/')?->tags)->toContain('person:'.$this->jane->id);

    $this->withElevation();
    $admin = User::factory()->create()->assignRole('administrator');
    app(DeleteUser::class)->execute(new DeleteUserInput((string) $this->jane->id, null, (string) $admin->id));

    expect(cacheEntry('/harbour-news/'))->toBeNull()
        ->and(sdNode(sdGraph('/harbour-news/'), $this->home.'#website'))->not->toHaveKey('publisher');

    cacheOff($folder);
});

it('leaves out a business stored without its address, as an import could leave it, with the reason, and the references to it go (I-703)', function (): void {
    ($this->represent)(['publisher_type' => 'Store', 'publisher_name' => 'Harbour Books']);

    $head = app(DocumentMeta::class)->for(new DocumentPage(null, null));

    expect(array_column($head->graph, '@id'))->toBe([$this->home.'#website'])
        ->and($head->graph[0])->not->toHaveKey('publisher')
        ->and($head->withheld)->toEqual([new Withheld($this->organization, ['Store'], [['address']])]);
});

it('leaves out a legal or accounting service stored without its address, as it does a store (I-889)', function (string $type): void {
    ($this->represent)(['publisher_type' => $type, 'publisher_name' => 'Harbour Partners']);

    $head = app(DocumentMeta::class)->for(new DocumentPage(null, null));

    expect(array_column($head->graph, '@id'))->toBe([$this->home.'#website'])
        ->and($head->withheld)->toEqual([new Withheld($this->organization, [$type], [['address']])]);
})->with(['LegalService', 'AccountingService']);

it('reads one statement for a logo and one for a person, and none for nobody in particular or an organization with no logo (I-701)', function (): void {
    $statements = function (): int {
        // Once first, so the settings it reads are already held.
        app(PublisherNodes::class)->for(new stdClass);

        return count(dashQueries(fn () => app(PublisherNodes::class)->for(new stdClass)));
    };

    $nobody = $statements();
    ($this->represent)(['publisher_type' => 'Organization']);
    $plain = $statements();
    ($this->represent)(['publisher_logo' => pageImage($this->writer, 300, 300)->id]);
    $logo = $statements();
    ($this->represent)(['publisher_type' => 'Person', 'publisher_person' => (string) $this->jane->id]);

    expect([$nobody, $plain, $logo, $statements()])->toBe([0, 0, 1, 1]);
});

it('writes only terms on the committed vocabulary, with every field of a business and its owner set (I-706)', function (): void {
    ($this->represent)([
        ...$this->restaurant,
        'publisher_alternate_name' => 'HK', 'publisher_logo' => pageImage($this->writer, 300, 300)->id, 'publisher_profiles' => ['https://x.com/harbourkitchen'],
        'publisher_owner_name' => 'Harbour Group', 'publisher_owner_url' => 'https://harbourgroup.example/', 'publisher_owner_profiles' => ['https://x.com/harbourgroup'],
    ]);
    headPost($this->writer, 'Harbour news');

    foreach (['/', '/harbour-news/'] as $uri) {
        $graph = sdGraph($uri);

        expect(sdNode($graph, $this->organization))->not->toBeNull()
            ->and(app(Vocabulary::class)->unknown($graph))->toBe([]);
    }
});

it('keeps the publisher\'s text inside its script, whatever it holds, and gives it back as typed (I-698)', function (): void {
    $hostile = '</script><script>x</script> <!-- '.mb_chr(0x2028).' Fish & Chips';
    ($this->represent)([
        'publisher_type' => 'LocalBusiness', 'publisher_name' => $hostile, 'publisher_alternate_name' => $hostile,
        'business_street_address' => $hostile, 'business_locality' => 'Port Harcourt', 'business_price_range' => $hostile,
        'publisher_owner_name' => $hostile, 'publisher_owner_url' => 'https://harbourmedia.example/',
    ]);

    $html = (string) addrGet('/')->assertOk()->getContent();
    preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $script);
    $json = $script[1] ?? '';
    $graph = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['@graph'];

    expect($json)->not->toContain('<')
        ->and($json)->toContain('Fish & Chips')
        ->and(sdNode($graph, $this->organization))->toMatchArray(['name' => $hostile, 'alternateName' => $hostile, 'priceRange' => $hostile])
        ->and(sdNode($graph, $this->organization)['address']['streetAddress'])->toBe($hostile)
        ->and(sdNode($graph, 'https://harbourmedia.example/#organization')['name'])->toBe($hostile);
});
