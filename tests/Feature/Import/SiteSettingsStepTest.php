<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\Settings\SitemapAddresses;
use App\Domain\Import\Phases\Settings\SiteSettingsStep;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Import\ImportRecords;
use Tests\Fixtures\Import\OldSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->admin = User::factory()->create()->assignRole('administrator');
    [$this->source, $this->run] = OldSite::running('settings');
});

/**
 * The step run as the operator; its messages, and the codes among them.
 *
 * @return list<ImportMessage>
 */
function ssRun(string $source, string $run, OldSite $site): array
{
    $said = scElevated(fn (): array => OldSite::walk(app(SiteSettingsStep::class), OldSite::context($source, $run), $site));
    app()->forgetInstance(AddressSettings::class);

    return $said;
}

/**
 * @param  list<ImportMessage>  $said
 *
 * @return list<string>
 */
function ssCodes(array $said): array
{
    return array_map(fn (ImportMessage $message): string => $message->code, $said);
}

it('copies the title, the tagline, posts per page, the feeds and the search engines’ switch, unescaped once (I-342)', function (): void {
    $said = ssRun($this->source, $this->run, (new OldSite)->options(['blogname' => 'Tom &amp; Jerry’s', 'blogdescription' => 'Notes &lt;from&gt; the harbour', 'posts_per_page' => '-1', 'posts_per_rss' => '250', 'rss_use_excerpt' => '1', 'blog_public' => '0']));
    $settings = app(SettingsReader::class);

    expect($settings->string('general', 'site_title'))->toBe('Tom & Jerry’s')
        ->and($settings->string('general', 'tagline'))->toBe('Notes <from> the harbour')
        ->and($settings->int('reading', 'posts_per_page'))->toBe(100)
        ->and($settings->int('seo', 'feed_items'))->toBe(100)
        ->and($settings->string('seo', 'feed_content'))->toBe('excerpt')
        ->and($settings->bool('seo', 'discourage_search_engines'))->toBeTrue()
        ->and(ssCodes($said))->toBe(['setting.discouraged', 'setting.not-carried'])
        ->and($said[0]->message)->toBe('The old site asked search engines to stay away, so this one does too. Switch it off in Settings › Search and social once the site should be found.')
        ->and($said[1]->message)->toBe('The old site’s date format (j F Y), time format (H:i) and first day of the week aren’t kept: Teme writes dates as the site’s language does.');
});

it('maps Discussion as I-586 says, the page size only when the old site paged its comments', function (): void {
    ssRun($this->source, $this->run, (new OldSite)->options([
        'default_comment_status' => 'open', 'comment_registration' => '1', 'close_comments_for_old_posts' => '1', 'close_comments_days_old' => '30',
        'thread_comments' => '0', 'thread_comments_depth' => '7', 'page_comments' => '1', 'comments_per_page' => '25',
        'comment_moderation' => '1', 'comment_previously_approved' => '0', 'comment_max_links' => '5',
    ]));
    $settings = app(SettingsReader::class);

    expect($settings->stringList('discussion', 'open_for'))->toContain('post')->not->toContain('page')
        ->and($settings->bool('discussion', 'signed_in_only'))->toBeTrue()
        ->and($settings->int('discussion', 'close_after_days'))->toBe(30)
        ->and($settings->int('discussion', 'thread_depth'))->toBe(1)
        ->and($settings->int('discussion', 'per_page'))->toBe(25)
        ->and($settings->bool('discussion', 'hold_all'))->toBeTrue()
        ->and($settings->bool('discussion', 'approve_returning'))->toBeFalse()
        ->and($settings->int('discussion', 'hold_links'))->toBe(5)
        ->and($settings->string('modules', 'comments'))->toBe('on');

    ssRun($this->source, $this->run, (new OldSite)->options(['page_comments' => '1', 'comments_per_page' => '500', 'default_comment_status' => 'closed']));

    expect(app(SettingsReader::class)->int('discussion', 'per_page'))->toBe(25)
        ->and(app(SettingsReader::class)->stringList('discussion', 'open_for'))->not->toContain('post');
});

it('sets the home page and the posts page from the pages the import made, and says when the home page didn’t come', function (): void {
    $home = addrPublished($this->admin, 'Welcome', 'page');
    $blog = addrPublished($this->admin, 'Blog', 'page');
    ImportRecords::entry($this->source, $this->run, 'post', '2', 'created', ['teme_type' => 'content', 'teme_id' => $home->id]);
    ImportRecords::entry($this->source, $this->run, 'post', '3', 'created', ['teme_type' => 'content', 'teme_id' => $blog->id]);

    ssRun($this->source, $this->run, (new OldSite)->options(['show_on_front' => 'page', 'page_on_front' => '2', 'page_for_posts' => '3']));

    expect(AddressSettings::current()->frontShows)->toBe(AddressSettings::FRONT_PAGE)
        ->and(AddressSettings::current()->frontPageId)->toBe($home->id)
        ->and(AddressSettings::current()->postsPageId)->toBe($blog->id)
        ->and(addrGet('/')->getStatusCode())->toBe(200);

    $said = ssRun($this->source, $this->run, (new OldSite)->options(['show_on_front' => 'page', 'page_on_front' => '99']));

    expect(AddressSettings::current()->frontPageId)->toBe($home->id)
        ->and(ssCodes($said))->toContain('setting.home-page-missing');
});

it('names each sitemap the old site served, by its plugin and the import’s counts', function (?string $plugin, array $counts, array $paths): void {
    expect(SitemapAddresses::for($plugin, $counts, ['category', 'post_tag']))->toBe($paths);
})->with([
    'Yoast SEO' => ['yoast', ['post' => 1500, 'page' => 3, 'author' => 1], ['/post-sitemap.xml', '/post-sitemap2.xml', '/page-sitemap.xml', '/author-sitemap.xml']],
    'Rank Math' => ['rank_math', ['post' => 450, 'category' => 0], ['/post-sitemap.xml', '/post-sitemap2.xml', '/post-sitemap3.xml', '/category-sitemap.xml']],
    'WordPress itself' => [null, ['post' => 2001, 'post_tag' => 5, 'author' => 1], ['/wp-sitemap-posts-post-1.xml', '/wp-sitemap-posts-post-2.xml', '/wp-sitemap-taxonomies-post_tag-1.xml', '/wp-sitemap-users-1.xml']],
]);

it('sends the old SEO plugin’s sitemaps on to /sitemap.xml (I-1717)', function (): void {
    ssRun($this->source, $this->run, (new OldSite)->options([], 'en_GB', 'yoast'));

    expect(DB::table('redirects')->orderBy('source')->pluck('target', 'source')->all())->toBe([
        'author-sitemap.xml' => '/sitemap.xml',
        'category-sitemap.xml' => '/sitemap.xml',
        'page-sitemap.xml' => '/sitemap.xml',
        'post-sitemap.xml' => '/sitemap.xml',
        'post_tag-sitemap.xml' => '/sitemap.xml',
    ])
        ->and(DB::table('redirects')->distinct()->pluck('origin')->all())->toBe(['import'])
        ->and(addrLocation(addrGet('/post-sitemap.xml')))->toBe('/sitemap.xml');
});

it('keeps a setting a person changed since the last import, and follows the old site elsewhere', function (): void {
    ssRun($this->source, $this->run, new OldSite);
    $version = (int) DB::table('settings')->where('group_key', 'general')->value('version');
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['site_title' => 'Harbour Notes'], [], [], $version, (string) $this->admin->id));

    $said = ssRun($this->source, $this->run, (new OldSite)->options(['blogname' => 'Renamed', 'blogdescription' => 'A new tagline']));
    $settings = app(SettingsReader::class);

    expect($settings->string('general', 'site_title'))->toBe('Harbour Notes')
        ->and($settings->string('general', 'tagline'))->toBe('A new tagline')
        ->and(collect($said)->firstWhere('code', 'setting.kept-here')?->message)->toBe('Changed here since the last import, so kept as they are: Site title.');
});

it('writes nothing when the person running it may no longer import', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => OldSite::walk(app(SiteSettingsStep::class), OldSite::context($this->source, $this->run, $editor), new OldSite))->toThrow(AuthorizationException::class)
        ->and(DB::table('settings_changes')->count())->toBe(0);
});

it('registers the settings at 1000, for every kind of run', function (): void {
    $phase = app(ImportPhases::class)->find('settings');

    expect($phase)->toBeInstanceOf(SiteSettingsStep::class)
        ->and($phase?->order())->toBe(1000)
        ->and($phase?->label())->toBe('Settings')
        ->and($phase?->runsIn(ImportRunKind::CatchUp))->toBeTrue();
});
