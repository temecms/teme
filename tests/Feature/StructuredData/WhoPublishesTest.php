<?php

declare(strict_types=1);

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\StructuredData\PublisherKinds;
use App\Domain\StructuredData\WhoPublishes;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->save = fn (array $values, int $version = 0) => $this->actingAs($this->admin)->from('/admin/settings/seo')
        ->put('/admin/settings/seo', ['values' => $values, 'expected_version' => $version]);
    $this->stored = fn (): array => (array) json_decode((string) (DB::table('settings')->where('group_key', SearchAndSocial::KEY)->value('overrides') ?? '{}'), true);
    $this->setting = fn (string $key): Setting => collect((new WhoPublishes)->settings())->firstWhere('key', $key);
});

it('adds "Who publishes the site" to Search and social at 300, after the whole site and before social cards, naming nobody in particular until set (I-874, I-876)', function (): void {
    $sections = SearchAndSocial::ordered(app()->tagged(SearchAndSocial::SECTIONS));
    $keys = array_map(fn (SearchAndSocialSection $section): string => $section->key(), $sections);
    $who = collect($sections)->first(fn (SearchAndSocialSection $section): bool => $section->key() === 'who-publishes');

    expect(array_values(array_intersect($keys, ['whole-site', 'who-publishes', 'social-cards'])))->toBe(['whole-site', 'who-publishes', 'social-cards'])
        ->and([$who->order(), $who->label()])->toBe([300, 'Who publishes the site'])
        ->and(array_map(fn (Setting $setting): string => $setting->key, $who->settings()))->toBe([
            'publisher_type', 'publisher_name', 'publisher_alternate_name', 'publisher_logo', 'publisher_profiles', 'publisher_person',
            'business_street_address', 'business_locality', 'business_region', 'business_postal_code', 'business_country',
            'business_phone', 'business_latitude', 'business_longitude', 'business_price_range', 'business_hours',
            'publisher_owner_name', 'publisher_owner_url', 'publisher_owner_profiles',
        ])
        ->and(($this->setting)('publisher_type')->options)->toBe([
            '' => 'Nobody in particular',
            'Organization' => 'Organization', 'NewsMediaOrganization' => 'News media organization', 'Corporation' => 'Corporation',
            'NGO' => 'NGO', 'EducationalOrganization' => 'Educational organization', 'GovernmentOrganization' => 'Government organization',
            'LocalBusiness' => 'Local business', 'Store' => 'Store', 'Restaurant' => 'Restaurant',
            'LegalService' => 'Legal service', 'AccountingService' => 'Accounting service', 'MedicalBusiness' => 'Medical business',
            'Person' => 'A person',
        ])
        ->and(app(SettingsReader::class)->get(SearchAndSocial::KEY, 'publisher_type'))->toBe('')
        ->and(WhoPublishes::kind())->toBe('');
});

it('shows each field only for the kinds it describes (I-874)', function (): void {
    $organizations = [...array_keys(PublisherKinds::ORGANIZATIONS), ...array_keys(PublisherKinds::BUSINESSES)];
    $businesses = array_keys(PublisherKinds::BUSINESSES);

    expect(($this->setting)('publisher_type')->visibleWhen)->toBe([])
        ->and(($this->setting)('publisher_name')->visibleWhen)->toBe(['publisher_type' => $organizations])
        ->and(($this->setting)('publisher_logo')->visibleWhen)->toBe(['publisher_type' => $organizations])
        ->and(($this->setting)('publisher_owner_url')->visibleWhen)->toBe(['publisher_type' => $organizations])
        ->and(($this->setting)('publisher_profiles')->visibleWhen)->toBe(['publisher_type' => [...$organizations, PublisherKinds::PERSON]])
        ->and(($this->setting)('publisher_person')->visibleWhen)->toBe(['publisher_type' => PublisherKinds::PERSON])
        ->and(($this->setting)('business_street_address')->visibleWhen)->toBe(['publisher_type' => $businesses])
        ->and(($this->setting)('business_hours')->visibleWhen)->toBe(['publisher_type' => $businesses]);
});

it('saves a restaurant with its address, phone, place on the map, price range, week and owner (I-708, I-725, I-877)', function (): void {
    ($this->save)([
        'publisher_type' => 'Restaurant',
        'publisher_name' => 'Harbour Kitchen',
        'publisher_profiles' => "https://www.instagram.com/harbourkitchen\nhttps://www.wikidata.org/wiki/Q42",
        'business_street_address' => '12 Aba Road', 'business_locality' => 'Port Harcourt', 'business_region' => 'Rivers',
        'business_postal_code' => '500272', 'business_country' => 'NG', 'business_phone' => '+234 803 000 0000',
        'business_latitude' => '4.81500', 'business_longitude' => '7.04980', 'business_price_range' => '₦₦',
        'business_hours' => ['monday' => ['hours' => [['opens' => '11:00', 'closes' => '22:00']]], 'sunday' => ['closed' => '1']],
        'publisher_owner_name' => 'Harbour Group', 'publisher_owner_url' => 'https://harbourgroup.example/',
    ])->assertSessionHasNoErrors();

    $values = app(SettingsReader::class)->group(SearchAndSocial::KEY);

    expect(WhoPublishes::kind())->toBe('Restaurant')
        ->and($values['publisher_profiles'])->toBe(['https://www.instagram.com/harbourkitchen', 'https://www.wikidata.org/wiki/Q42'])
        ->and($values['business_hours'])->toEqual([
            ['day' => 'monday', 'hours' => [['opens' => '11:00', 'closes' => '22:00']], 'closed' => false],
            ['day' => 'sunday', 'hours' => [], 'closed' => true],
        ])
        ->and($values['publisher_owner_url'])->toBe('https://harbourgroup.example/');
});

it('refuses what would leave search engines a publisher they can\'t use, naming the field, and keeps nothing (I-708, I-725)', function (array $values, string $field, string $message): void {
    ($this->save)($values)->assertSessionHasErrors([$field => $message]);

    expect(($this->stored)())->toBe([]);
})->with([
    'a person not chosen' => [['publisher_type' => 'Person', 'publisher_person' => ''], 'values.publisher_person', 'Choose the person the site represents, or Nobody in particular.'],
    'a business with no street address' => [['publisher_type' => 'Restaurant', 'business_locality' => 'Port Harcourt'], 'values.business_street_address', 'A business needs its street address: search engines leave out a business without one.'],
    'a business with no town or city' => [['publisher_type' => 'Store', 'business_street_address' => '12 Aba Road'], 'values.business_locality', 'A business needs its town or city: search engines leave out a business without one.'],
    'a latitude alone' => [['publisher_type' => 'Store', 'business_street_address' => '12 Aba Road', 'business_locality' => 'Port Harcourt', 'business_latitude' => '4.81500'], 'values.business_longitude', 'Give the longitude too, or clear the latitude.'],
    'a latitude off the globe' => [['publisher_type' => 'Store', 'business_street_address' => '12 Aba Road', 'business_locality' => 'Port Harcourt', 'business_latitude' => '95', 'business_longitude' => '7.04980'], 'values.business_latitude', 'Write the latitude in degrees, from -90 to 90, such as 51.50740.'],
    'a longitude in words' => [['publisher_type' => 'Store', 'business_street_address' => '12 Aba Road', 'business_locality' => 'Port Harcourt', 'business_latitude' => '4.81500', 'business_longitude' => 'east'], 'values.business_longitude', 'Write the longitude in degrees, from -180 to 180, such as -0.12780.'],
    'a phone number in words' => [['publisher_type' => 'Store', 'business_street_address' => '12 Aba Road', 'business_locality' => 'Port Harcourt', 'business_phone' => 'Ask at the desk'], 'values.business_phone', 'Write the phone number in digits, spaces and + - ( ) only, such as +44 20 7946 0000.'],
    'a country by its name' => [['publisher_type' => 'Store', 'business_street_address' => '12 Aba Road', 'business_locality' => 'Port Harcourt', 'business_country' => 'Nigeria'], 'values.business_country', 'Give the country as its two-letter code, such as GB, US or NG.'],
    'an owner with no website' => [['publisher_type' => 'NewsMediaOrganization', 'publisher_owner_name' => 'Harbour Media Group'], 'values.publisher_owner_url', 'Give the owner’s website too, so search engines can tell which organization it is.'],
    'an owner\'s website with no name' => [['publisher_type' => 'NewsMediaOrganization', 'publisher_owner_url' => 'https://harbourmedia.example/'], 'values.publisher_owner_name', 'Give the owner’s name too.'],
    'this site as its owner' => [['publisher_type' => 'Corporation', 'publisher_owner_name' => 'Harbour Ledger', 'publisher_owner_url' => 'http://localhost/'], 'values.publisher_owner_url', 'The owner’s website is this site. Leave the owner empty unless another organization owns this one.'],
]);

it('refuses a logo smaller than 112 pixels either way, and takes one that size (I-875)', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    $banner = pageImage($this->admin, 600, 100);
    $square = pageImage($this->admin, 112, 112);

    ($this->save)(['publisher_type' => 'Organization', 'publisher_logo' => $banner->id])
        ->assertSessionHasErrors(['values.publisher_logo' => 'The logo must be at least 112 × 112 pixels; this one is 600 × 100.']);
    ($this->save)(['publisher_type' => 'Organization', 'publisher_logo' => $square->id])->assertSessionHasNoErrors();

    expect(app(SettingsReader::class)->get(SearchAndSocial::KEY, 'publisher_logo'))->toBe($square->id);
});

it('checks only what the chosen kind shows, so a business\'s old phone number and a half-filled owner don\'t stop a person being chosen (I-874)', function (): void {
    cmSettings(['business_phone' => 'Ask at the desk', 'publisher_owner_name' => 'Harbour Media Group'], SearchAndSocial::KEY);

    ($this->save)(['publisher_type' => 'Person', 'publisher_person' => (string) $this->admin->id])->assertSessionHasNoErrors();

    expect(WhoPublishes::kind())->toBe('Person')
        ->and(app(SettingsReader::class)->get(SearchAndSocial::KEY, 'business_phone'))->toBe('Ask at the desk');
});

it('writes nothing for someone who may not change Search and social (I-850)', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->put('/admin/settings/seo', ['values' => ['publisher_type' => 'Organization'], 'expected_version' => 0])->assertForbidden();

    expect(fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput(SearchAndSocial::KEY, ['publisher_type' => 'Organization'], [], [], 0, (string) $editor->id)))
        ->toThrow(AuthorizationException::class)
        ->and(($this->stored)())->toBe([]);
});
