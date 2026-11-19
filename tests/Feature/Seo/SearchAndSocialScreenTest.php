<?php

declare(strict_types=1);

use App\Domain\RobotsTxt\AiTrainingCrawlers;
use App\Domain\RobotsTxt\RobotsTxt;
use App\Domain\Seo\Queries\SearchAndSocialScreen;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use App\Domain\Seo\WholeSite;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\Setting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->data = fn (?User $viewer = null): array => app(SearchAndSocialScreen::class)->data($viewer ?? $this->admin);
    $this->section = fn (array $data, string $key): ?array => collect($data['sections'])->firstWhere('key', $key);
    $this->row = fn (array $data, string $key): ?array => collect($data['titles']['rows'])->firstWhere('key', $key);
});

it('gives core\'s sections in their order, each with its own fields and its words (I-874)', function (): void {
    $data = ($this->data)();
    $order = ['titles-and-listing', 'whole-site', 'site-verification', 'social-cards', 'feeds', 'robots-txt', 'not-found-log'];

    expect(array_values(array_intersect(array_column($data['sections'], 'key'), $order)))->toBe($order)
        ->and(array_column(($this->section)($data, 'social-cards')['fields'], 'key'))->toBe(['default_social_image'])
        ->and(array_column(($this->section)($data, 'not-found-log')['fields'], 'key'))->toBe(['not_found_log_size', 'not_found_log_ignore'])
        ->and(($this->section)($data, 'feeds')['note'])->toBe('RSS, at the addresses WordPress used, so a moved site’s subscribers keep getting it.')
        ->and(($this->section)($data, 'social-cards')['note'])->toBe('Each item’s own social title, description and image are set in the editor, under Search and social.')
        ->and(($this->section)($data, 'whole-site')['note'])->toBeNull()
        ->and($data['description'])->toBe('Titles in search results, what search engines may list, social cards, feeds, robots.txt, the API and llms.txt, and the 404 log.');
});

it('gives a section another part registers its place by its order, with its fields', function (): void {
    // 250: between core's sections, clear of the hundreds and fifties they take.
    app()->instance('test.section.publisher', new readonly class implements SearchAndSocialSection
    {
        public function order(): int
        {
            return 250;
        }

        public function key(): string
        {
            return 'test-publisher';
        }

        public function label(): string
        {
            return 'A plugin’s section';
        }

        public function settings(): array
        {
            return [Setting::text('test_publisher_name', 'Name', 'A name for this test.', '0.1.0')];
        }
    });
    app()->tag(['test.section.publisher'], SearchAndSocial::SECTIONS);
    app(SettingsRegistry::class)->replace(SearchAndSocial::group(app()->tagged(SearchAndSocial::SECTIONS)));

    $data = ($this->data)();
    $keys = array_column($data['sections'], 'key');

    expect(array_search('test-publisher', $keys, true))->toBe(array_search('whole-site', $keys, true) + 1)
        ->and(array_search('site-verification', $keys, true))->toBe(array_search('test-publisher', $keys, true) + 1)
        ->and(($this->section)($data, 'test-publisher'))->toMatchArray(['label' => 'A plugin’s section', 'note' => null])
        ->and(($this->section)($data, 'test-publisher')['fields'][0]['key'])->toBe('test_publisher_name')
        ->and($data['values'])->toHaveKey('test_publisher_name');
});

it('gives each title row its example\'s tokens: the newest live item, the viewer, this month, a sample search, and the home row\'s second page (I-852, I-904)', function (): void {
    cmSettings(['tagline' => 'Harbour stories'], 'general');
    cmItem(User::factory()->create(['name' => 'Jane Doe'])->assignRole('author'), 'Harbour news');
    $data = ($this->data)();
    $home = ($this->row)($data, 'home');

    expect(($this->row)($data, 'type:post')['tokens'])->toMatchArray(['title' => 'Harbour news', 'author' => 'Jane Doe', 'type' => 'Post', 'site' => 'My Teme site'])
        ->and(($this->row)($data, 'type:page')['tokens']['title'])->toBe('Example page')
        ->and(($this->row)($data, 'author')['tokens'])->toMatchArray(['title' => 'Ada Admin', 'author' => 'Ada Admin'])
        ->and(($this->row)($data, 'search')['tokens'])->toMatchArray(['title' => 'Search results for “ferry fares”', 'search' => 'ferry fares'])
        ->and(($this->row)($data, 'not_found')['tokens']['title'])->toBe('Page not found')
        ->and($home['tokens'])->toMatchArray(['site' => 'My Teme site', 'tagline' => 'Harbour stories'])
        ->and($home['pageTwo'])->toMatchArray(['site' => 'My Teme site', 'tagline' => 'Harbour stories', 'page' => 'Page 2'])
        ->and(($this->row)($data, 'type:post')['pageTwo'])->toBeNull()
        ->and([$home['hide'], $home['note']])->toBe([null, 'Not hidden on its own: Discourage search engines hides the whole site. A page shown as the home page uses this row unless it has its own SEO title; a page that lists the latest posts uses its type’s row.'])
        ->and(($this->row)($data, 'search')['note'])->toBe('Always hidden: search engines are asked not to list any search.')
        ->and(($this->row)($data, 'not_found')['note'])->toBe('Never listed: it answers “not found”.')
        ->and(($this->row)($data, 'type:post')['note'])->toBeNull()
        ->and($data['titles']['tokens'])->toBe(['title', 'site', 'tagline', 'sep', 'page', 'term', 'author', 'type', 'excerpt', 'date', 'year', 'search'])
        ->and([$data['titles']['separator'], $data['titles']['themeName']])->toBe(['–', 'Fixture Good']);
});

it('says whether the copy is the live site, links what search engines fetch, and has no sitemap while discouraged (I-873, I-883)', function (): void {
    expect(($this->data)())->toMatchArray([
        'live' => false,
        'discouraged' => false,
        'files' => ['sitemap' => 'http://localhost/sitemap.xml', 'robots' => 'http://localhost/robots.txt', 'feed' => 'http://localhost/feed/'],
    ]);

    cmSettings([WholeSite::DISCOURAGE => true], SearchAndSocial::KEY);
    $this->app['env'] = 'production';

    expect(($this->data)())->toMatchArray(['live' => true, 'discouraged' => true])
        ->and(($this->data)()['files']['sitemap'])->toBeNull();
});

it('shows robots.txt as it is now, Teme\'s list of training crawlers, and Site code\'s address to whoever may manage it (I-707)', function (): void {
    $seoOnly = User::factory()->create()->assignRole('editor')->givePermissionTo('settings.seo.manage');
    $data = ($this->data)();

    expect($data['robots'])->toBe(['text' => RobotsTxt::text(), 'crawlers' => AiTrainingCrawlers::agents()])
        ->and($data['siteCodeUrl'])->toBe(route('settings.site-code'))
        ->and(($this->data)($seoOnly)['siteCodeUrl'])->toBeNull();
});

it('links the 404 log for whoever may manage redirects (I-970)', function (): void {
    $seoOnly = User::factory()->create()->givePermissionTo('settings.seo.manage');

    expect(($this->data)()['notFoundLogUrl'])->toBe(route('tools.redirects.not-found'))
        ->and(($this->data)($seoOnly)['notFoundLogUrl'])->toBeNull();
});
