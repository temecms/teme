<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Api\Llms\MarkdownPages;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Redirects\AddressCounts;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\WholeSite;
use App\Domain\Users\Operations\Input\UpdateProfileInput;
use App\Domain\Users\Operations\UpdateProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->folder = sys_get_temp_dir().'/teme-md-'.getmypid().'-'.uniqid();
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')], 'page_cache.stores.file.path' => $this->folder]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->admin = User::factory()->create()->assignRole('administrator');
    // The 404 log's open spool, each line without its time.
    $this->spooled = function (): array {
        $file = app(AddressCounts::class)->folder().'/'.AddressCounts::OPEN;
        $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];

        return array_map(fn (string $line): string => implode("\t", array_slice(explode("\t", $line), 0, -1)), $lines);
    };
});

afterEach(fn () => cacheOff($this->folder));

it('answers a listed item\'s .md copy, with its title, excerpt, date, byline, address and body', function (): void {
    Switches::llmsOn();
    $site = DeliverySite::build();
    $path = (string) DB::table('paths')->where('target_id', $site->public->id)->where('is_current', true)->value('path');
    $sequel = (string) DB::table('paths')->where('target_id', $site->sequel->id)->where('is_current', true)->value('path');
    $date = $site->public->published_at?->setTimezone('Africa/Lagos')->format('Y-m-d');

    $response = addrGet('/'.$path.'.md')->assertOk();

    expect($response->getContent())->toStartWith(implode("\n\n", [
        '# Arrival',
        '> A linguist is asked to talk to the visitors.',
        'Published '.$date.' · Ada Obi · http://localhost/'.$path.'/',
        'Louise Banks teaches languages. [The sequel](http://localhost/'.$sequel.'/) came later; the unfinished cut and the hidden one did not.',
        '![The shell over the field](http://localhost/',
    ]))->toEndWith(")\n")
        ->not->toContain('SECRET')
        ->not->toContain('teme:')
        ->not->toContain('Read the unfinished cut');
});

it('answers /index.md for the page shown as the home page, and the page\'s own .md address goes to the site', function (): void {
    Switches::llmsOn();
    $welcome = addrPublished($this->admin, 'Welcome', 'page');
    addrChange($this->admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $welcome->id]);

    $response = addrGet('/index.md')->assertOk();

    expect($response->getContent())->toStartWith("# Welcome\n\nPublished ")
        ->toContain(' · http://localhost/');
    expect($response->headers->get('Link'))->toBe('<http://localhost/>; rel="canonical"');

    addrGet('/welcome.md')->assertNotFound();
});

it('answers no .md page for an item the sitemap leaves out, handing the address to the site', function (): void {
    Switches::llmsOn();
    $named = headPost($this->author, 'Named story');
    $hiddenType = headPost($this->author, 'Typed story');
    $items = [
        'noindexed-story' => headPost($this->author, 'Noindexed story', [BuiltInFields::NOINDEX => true]),
        'pointing-story' => headPost($this->author, 'Pointing story', [BuiltInFields::CANONICAL => ['item' => $named->id]]),
        'elsewhere-story' => headPost($this->author, 'Elsewhere story', [BuiltInFields::CANONICAL => ['address' => 'https://elsewhere.example/story']]),
        'protected-story' => headPost($this->author, 'Protected story'),
        'private-story' => headPost($this->author, 'Private story'),
        'draft-story' => lifecycleDraft($this->author, 'Draft story'),
        'trashed-story' => headPost($this->author, 'Trashed story'),
    ];
    foreach (['protected-story' => 'protected', 'private-story' => 'private'] as $slug => $visibility) {
        app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($items[$slug]->id, $visibility, $visibility === 'protected' ? 'hunter2' : null, (string) $this->admin->id));
    }
    pageTrash($items['trashed-story'], $this->admin);

    addrGet('/named-story.md')->assertOk();

    foreach (array_keys($items) as $slug) {
        $response = addrGet('/'.$slug.'.md')->assertNotFound();

        expect($response->getContent())->not->toContain('# ')->and($response->headers->get('Content-Type'))->not->toContain('text/markdown');
    }

    cmSettings(['hide_type_post' => true], SearchAndSocial::KEY);
    addrGet('/typed-story.md')->assertNotFound();
    addrGet('/never-was.md')->assertNotFound();

    $logged = ($this->spooled)();

    expect($hiddenType->slug)->toBe('typed-story');
    foreach ([...array_keys($items), 'typed-story', 'never-was'] as $slug) {
        expect(count(array_filter($logged, fn (string $line): bool => str_starts_with($line, "n\t{$slug}.md"))))->toBe(1, $slug);
    }
});

it('answers no .md page while llms.txt is off', function (): void {
    headPost($this->author, 'Plain story');

    addrGet('/plain-story.md')->assertNotFound();

    expect(array_filter(($this->spooled)(), fn (string $line): bool => str_starts_with($line, "n\tplain-story.md")))->toHaveCount(1);
});

it('answers no .md page while the site discourages search engines, handing the address to the site', function (): void {
    Switches::llmsOn();
    headPost($this->author, 'Plain story');
    addrGet('/plain-story.md')->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');

    cmSettings([WholeSite::DISCOURAGE => true], SearchAndSocial::KEY);
    $response = addrGet('/plain-story.md')->assertNotFound();

    expect($response->headers->get('Content-Type'))->not->toContain('text/markdown')
        ->and((string) $response->getContent())->not->toContain('# Plain story');
});

it('shows the author\'s new name at once, with the page cache on', function (): void {
    Switches::llmsOn();
    $folder = cacheOn();
    headPost($this->author, 'Plain story');

    addrGet('/plain-story.md')->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->assertSee('Jane Doe');
    addrGet('/plain-story.md')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    expect(cacheEntry('/plain-story.md')?->tags)->toContain(PageTags::person((string) $this->author->id));

    app(UpdateProfile::class)->execute(new UpdateProfileInput((string) $this->author->id, 'Jane Obi', (string) $this->author->email, (string) $this->author->id));
    $renamed = addrGet('/plain-story.md')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    expect((string) $renamed->getContent())->toContain(' · Jane Obi · ')->not->toContain('Jane Doe');

    cacheOff($folder);
});

it('sends noindex and the page as canonical', function (): void {
    Switches::llmsOn();
    headPost($this->author, 'Plain story');

    $response = addrGet('/plain-story.md')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/markdown; charset=UTF-8')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($response->headers->get('Link'))->toBe('<http://localhost/plain-story/>; rel="canonical"');
});

it('lets a redirect rule whose source ends .md answer where no .md page is listed, and the page where one is', function (): void {
    Switches::llmsOn();
    headPost($this->author, 'Plain story');
    rdSaved('/plain-story.md', '/elsewhere/');
    rdSaved('/gone-story.md', '/elsewhere/');

    addrGet('/plain-story.md')->assertOk();
    addrGet('/gone-story.md')->assertStatus(301);
});

it('tags the page with the item and reads it in at most 12 statements', function (): void {
    Switches::llmsOn();
    headPost($this->author, 'Plain story', [BuiltInFields::EXCERPT => 'Short.']);
    addrGet('/plain-story.md')->assertOk();
    DB::flushQueryLog();
    DB::enableQueryLog();

    addrGet('/plain-story.md')->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(12);
});

it('tags the page for the page cache with the item and the items its body links to', function (): void {
    Switches::llmsOn();
    $target = headPost($this->author, 'Target story');
    $link = ['type' => 'text', 'text' => 'the target', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:content/'.$target->id]]]];
    $source = headPost($this->author, 'Source story', nodes: [['type' => 'paragraph', 'content' => [$link]]]);
    app(RenderRecord::class)->start(0);

    $page = MarkdownPages::find('source-story');

    $text = MarkdownPages::text($page ?? []);

    expect($page)->not->toBeNull()
        ->and($text)->toContain('[the target](http://localhost/target-story/)')
        ->and(app(RenderRecord::class)->tags())->toContain('content:'.$source->id, 'content:'.$target->id);
});
