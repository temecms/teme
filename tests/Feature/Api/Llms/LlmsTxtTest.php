<?php

declare(strict_types=1);

use App\Domain\Api\Llms\LlmsTxt;
use App\Domain\Cache\RenderRecord;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\WholeSite;
use App\Http\Site\Middleware\MarkCacheable;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Api\LlmsPosts;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->admin = User::factory()->create()->assignRole('administrator');
    cmSettings(['site_title' => 'Harbour Notes', 'tagline' => 'News from the quay'], 'general');
});

/**
 * Sets when an item went live, so the order of a list never rests on a tie.
 */
function llmsDated(string $id, string $when): void
{
    DB::table('contents')->where('id', $id)->update(['published_at' => $when]);
}

it('answers the site\'s 404 page while llms.txt is off', function (): void {
    headPost($this->author, 'Plain story');

    addrGet('/llms.txt')->assertNotFound();
});

it('answers the site\'s 404 page while the site discourages search engines', function (): void {
    Switches::llmsOn();
    headPost($this->author, 'Plain story');
    cmSettings([WholeSite::DISCOURAGE => true], SearchAndSocial::KEY);

    addrGet('/llms.txt')->assertNotFound();
});

it('writes the site\'s name, tagline and a section per listed type, newest first, each linking to its .md copy', function (): void {
    Switches::llmsOn();
    $first = headPost($this->author, 'First story', [BuiltInFields::EXCERPT => 'The first one.']);
    $second = headPost($this->author, 'Second story');
    llmsDated($first->id, '2026-01-01 10:00:00');
    llmsDated($second->id, '2026-02-01 10:00:00');
    $page = addrPublished($this->admin, 'About', 'page');

    $response = addrGet('/llms.txt')->assertOk();

    expect($response->getContent())->toBe(implode("\n", [
        '# Harbour Notes',
        '',
        '> News from the quay',
        '',
        "This site's published content, newest first in each section. Each link opens a plain-text copy of the page.",
        '',
        '## Posts',
        '',
        '- [Second story](http://localhost/second-story.md)',
        '- [First story](http://localhost/first-story.md): The first one.',
        '',
        '## Pages',
        '',
        '- [About](http://localhost/'.$page->slug.'.md)',
        '',
    ]));
});

it('lists only what the sitemap lists', function (): void {
    Switches::llmsOn();
    $listed = headPost($this->author, 'Listed story');
    $named = headPost($this->author, 'Named story');
    headPost($this->author, 'Noindexed story', [BuiltInFields::NOINDEX => true]);
    headPost($this->author, 'Pointing story', [BuiltInFields::CANONICAL => ['item' => $named->id]]);
    headPost($this->author, 'Elsewhere story', [BuiltInFields::CANONICAL => ['address' => 'https://elsewhere.example/story']]);
    $protected = headPost($this->author, 'Protected story');
    $private = headPost($this->author, 'Private story');
    lifecycleDraft($this->author, 'Draft story');
    foreach (['protected' => $protected, 'private' => $private] as $visibility => $item) {
        app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($item->id, $visibility, $visibility === 'protected' ? 'hunter2' : null, (string) $this->admin->id));
    }

    $text = (string) addrGet('/llms.txt')->assertOk()->getContent();

    expect($text)->toContain('(http://localhost/listed-story.md)')
        ->toContain('(http://localhost/named-story.md)')
        ->not->toContain('Noindexed')
        ->not->toContain('Pointing')
        ->not->toContain('Elsewhere')
        ->not->toContain('Protected')
        ->not->toContain('Private')
        ->not->toContain('Draft');

    cmSettings(['hide_type_post' => true], SearchAndSocial::KEY);

    expect((string) addrGet('/llms.txt')->assertOk()->getContent())->not->toContain('## Posts')->not->toContain($listed->slug);
});

it('lists at most 100 items a section', function (): void {
    Switches::llmsOn();
    LlmsPosts::many($this->author, 102);

    $text = (string) addrGet('/llms.txt')->assertOk()->getContent();

    expect(substr_count($text, "\n- ["))->toBe(100);
});

it('adds the API\'s line only while the API is on', function (): void {
    Switches::llmsOn();
    headPost($this->author, 'Plain story');

    expect((string) addrGet('/llms.txt')->getContent())->not->toContain('## API');

    Switches::apiOn();

    expect((string) addrGet('/llms.txt')->getContent())->toEndWith("\n## API\n\n- [Delivery API](http://localhost/api/v1): The same content as JSON.\n");
});

it('writes a title\'s newlines as spaces and escapes its brackets', function (): void {
    Switches::llmsOn();
    $item = headPost($this->author, 'Plain story', [BuiltInFields::EXCERPT => "Two\nlines\tof it"]);
    $revision = (string) $item->refresh()->published_revision_id;
    DB::table('content_revisions')->where('id', $revision)->update(['title' => "A [bracketed]\nway \\ out"]);

    expect((string) addrGet('/llms.txt')->getContent())
        ->toContain('- [A \[bracketed\] way \\\\ out](http://localhost/plain-story.md): Two lines of it');
});

it('answers text/plain, noindex, and is marked for the page cache', function (): void {
    Switches::llmsOn();
    headPost($this->author, 'Plain story');

    $response = addrGet('/llms.txt')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and(collect(app('router')->getRoutes()->getByName('llms-txt')->gatherMiddleware())->contains(MarkCacheable::class))->toBeTrue();
});

it('names no item whose .md address the route cannot answer, and none at /index while a front page is chosen', function (): void {
    Switches::llmsOn();
    $odd = headPost($this->author, 'Odd story');
    DB::table('paths')->where('target_id', $odd->id)->where('is_current', true)->update(['path' => 'café-story']);
    $kept = headPost($this->author, 'Kept story');

    $text = (string) addrGet('/llms.txt')->assertOk()->getContent();

    expect($text)->toContain('(http://localhost/kept-story.md)')->not->toContain('caf');
    expect($kept->slug)->toBe('kept-story');
});

it('tags the file for the page cache with each section\'s type list', function (): void {
    Switches::llmsOn();
    headPost($this->author, 'Plain story');
    app(RenderRecord::class)->start(0);

    LlmsTxt::text();

    expect(app(RenderRecord::class)->tags())->toContain('list:post', 'list:page');
});
