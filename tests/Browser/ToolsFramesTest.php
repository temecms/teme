<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    // The page cache on, in a folder of this test's own; the site's address is left as it is, so the browser stays on its server.
    $this->folder = sys_get_temp_dir().'/teme-tools-frames-'.getmypid().'-'.uniqid();
    config(['page_cache.enabled' => true, 'page_cache.driver' => 'file', 'page_cache.stores.file.path' => $this->folder]);

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // Warm the screens before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('tools.index'))->assertOk();
});

afterEach(fn () => (new Filesystem)->deleteDirectory($this->folder));

dataset('tool frames', [
    'Import' => ['/admin/tools/import', 'Import'],
    'Backups' => ['/admin/tools/backups', 'Backups'],
    'Page cache' => ['/admin/tools/page-cache', 'Page cache'],
    'Updates' => ['/admin/tools/updates', 'Updates'],
    'Redirects' => ['/admin/tools/redirects', 'Redirects'],
    'Not found' => ['/admin/tools/redirects?tab=not-found', 'Redirects'],
    'AI activity' => ['/admin/tools/ai-activity', 'AI activity'],
]);

it('draws :dataset on one page pattern, one heading, nothing sideways and no small target at 375 pixels', function (string $url, string $title): void {
    $page = cmSignIn($this->admin)->resize(375, 812);
    cmNavigate($page, $url);
    cmWaitUntil($page, 'document.querySelectorAll(\'[data-slot="page"]\').length === 1');

    expect($page->script('() => [...document.querySelectorAll("main h1")].map((h) => h.textContent.trim())'))->toBe([$title])
        ->and($page->script('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth'))->toBeTrue()
        ->and(cmSmallTargets($page)['small'])->toBe([]);
})->with('tool frames');

it('names a Settings page in the crumb and keeps its h1 for the outline only', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/general')->assertOk();

    $page = cmSignIn($this->admin)->resize(1280, 900);
    cmNavigate($page, '/admin/settings/general');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="pattern-title"]\') !== null');

    $look = $page->script(<<<'JS'
        () => {
            const title = document.querySelector('[data-test="pattern-title"]');
            const crumb = document.querySelector('[data-test="crumbs"]');
            const description = title?.closest('[data-slot="page-header"]')?.querySelector('p');

            return {
                crumb: crumb?.innerText.replace(/\s+/g, ' ').trim() ?? null,
                h1: title?.textContent?.trim() ?? null,
                h1Hidden: title?.classList.contains('sr-only') === true,
                description: description?.textContent?.trim() ?? null,
                descriptionQuiet: description?.className.includes('text-small') === true,
            };
        }
        JS);

    expect($look['crumb'])->toContain('General')
        ->and($look['h1'])->toBe('General')
        ->and($look['h1Hidden'])->toBeTrue()
        ->and($look['description'])->not->toBeNull()
        ->and($look['descriptionQuiet'])->toBeTrue();
});

it('keeps Home’s greeting as a visible title', function (): void {
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();

    $page = cmSignIn($this->admin)->resize(1280, 900);
    cmNavigate($page, '/admin');
    shellSettled($page, '[data-slot="page-header"]');

    $look = $page->script(<<<'JS'
        () => {
            const title = document.querySelector('[data-test="pattern-title"]');

            return {
                text: title?.textContent?.trim() ?? null,
                visible: title?.classList.contains('sr-only') !== true,
            };
        }
        JS);

    expect($look['text'])->toContain('Ada Admin')
        ->and($look['visible'])->toBeTrue();
});

it('keeps Compare versions’ long title visible under a shorter crumb', function (): void {
    $draft = lifecycleDraft($this->admin, 'Harbour news');
    $from = (string) $draft->working_revision_id;
    $to = (string) docSave($draft, $this->admin, docWith(docText('The ferry leaves.')))->working_revision_id;
    $url = "/admin/content/{$draft->id}/compare?from={$from}&to={$to}";

    $this->actingAs($this->admin)->get($url)->assertOk();

    $page = cmSignIn($this->admin)->resize(1280, 900);
    cmNavigate($page, $url);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="pattern-title"]\') !== null');

    $look = $page->script(<<<'JS'
        () => {
            const title = document.querySelector('[data-test="pattern-title"]');
            const crumb = document.querySelector('[data-test="crumbs"]');

            return {
                crumb: crumb?.innerText.replace(/\s+/g, ' ').trim() ?? null,
                title: title?.textContent?.trim() ?? null,
                visible: title?.classList.contains('sr-only') !== true,
            };
        }
        JS);

    expect($look['crumb'])->toContain('Compare versions')
        ->and($look['title'])->toContain('Compare versions of')
        ->and($look['title'])->toContain('Harbour news')
        ->and($look['visible'])->toBeTrue();
});
