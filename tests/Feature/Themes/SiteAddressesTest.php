<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Site\SiteAddresses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    ltTypes();
    $this->admin = ctAdmin();
});

/** What `$site` holds for the page just set up, as a theme view reads it. */
function siteView(): object
{
    $html = View::make('theme::partials.site-addresses')->render();
    $read = fn (string $name): ?string => preg_match('/'.preg_quote($name, '/').'="([^"]*)"/', $html, $found) === 1 ? html_entity_decode($found[1]) : null;

    return new class($read)
    {
        public string $postsUrl;

        /** @var array<string, string> */
        public array $archives = [];

        public function __construct(Closure $read)
        {
            $this->postsUrl = (string) $read('data-posts');

            foreach (['post', 'film', 'recipe'] as $type) {
                if ($read('data-archive-'.$type) !== null) {
                    $this->archives[$type] = (string) $read('data-archive-'.$type);
                }
            }
        }
    };
}

it('gives the home page as the posts address while the latest posts are the home page', function (): void {
    expect(siteView()->postsUrl)->toBe(rtrim(url('/'), '/').'/');
});

it('gives the posts page\'s address when Reading names one', function (): void {
    $welcome = addrPublished($this->admin, 'Welcome', 'page');
    $news = addrPublished($this->admin, 'News', 'page');
    addrChange($this->admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $welcome->id, 'postsPageId' => $news->id]);

    expect(siteView()->postsUrl)->toBe(rtrim(url('/'), '/').'/news/');
});

it('gives each public type\'s archive address by its key, and none for a type without an archive', function (): void {
    $site = siteView();

    expect($site->archives)->toHaveKey('film')
        ->and($site->archives['film'])->toBe(rtrim(url('/'), '/').'/films/')
        ->and($site->archives)->not->toHaveKey('recipe')
        ->and($site->archives['post'])->toBe($site->postsUrl);
});

it('follows the site address it is served from, storing nothing', function (): void {
    config(['app.url' => 'http://example.test']);
    url()->forceRootUrl('http://example.test');
    app()->forgetInstance(SiteAddresses::class);

    expect(siteView()->archives['film'])->toBe('http://example.test/films/');
});
