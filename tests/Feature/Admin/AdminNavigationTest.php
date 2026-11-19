<?php

declare(strict_types=1);

use App\Domain\Navigation\BadgeProvider;
use App\Domain\Navigation\NavEntry;
use App\Domain\Navigation\NavigationRegistry;
use App\Domain\Navigation\NavSection;
use App\Domain\Navigation\Queries\AdminNavigation;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

/*
 * Each case reads the shared `nav` prop from the dashboard, which every
 * signed-in person may open.
 */

it('shows an administrator every core entry, in section order, with the taxonomies under Posts', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    $nav = $this->actingAs($admin)->get(route('dashboard'))->viewData('page')['props']['nav'];

    // Media sits at order 250, after pages (200); Comments at 280, after Regions; Themes at 50 and Plugins at 60, before People (100); Types at 75 and Taxonomies at 80, between Themes and People; Tools at 800, before Settings (900).
    expect(array_column($nav, 'key'))->toBe(['dashboard', 'posts', 'pages', 'media', 'navigation', 'regions', 'comments', 'themes', 'plugins', 'types', 'taxonomies', 'users', 'tools', 'settings'])
        ->and(array_column($nav, 'section'))->toBe(['home', 'content', 'content', 'content', 'content', 'content', 'content', 'site', 'site', 'site', 'site', 'site', 'site', 'site'])
        ->and(array_column($nav, 'label'))->toBe(['Home', 'Posts', 'Pages', 'Media', 'Navigation', 'Regions', 'Comments', 'Themes', 'Plugins', 'Types', 'Taxonomies', 'People', 'Tools', 'Settings'])
        ->and($nav[1]['href'])->toBe(route('content.index', ['type' => 'posts'], false))
        ->and($nav[1]['icon'])->toBe('file-text')
        ->and(array_column($nav[1]['children'], 'key'))->toBe(['categories', 'tags'])
        ->and(array_column($nav[1]['children'], 'label'))->toBe(['Categories', 'Tags'])
        ->and($nav[1]['children'][0]['href'])->toBe(route('terms.index', ['taxonomy' => 'categories'], false))
        ->and($nav[1]['children'][0]['icon'])->toBe('folder')
        ->and($nav[2]['children'])->toBe([])
        ->and($nav[3]['href'])->toBe(route('media.index', [], false))
        ->and($nav[7]['href'])->toBe(route('themes.index', [], false))
        ->and($nav[7]['icon'])->toBe('brush')
        ->and($nav[8]['href'])->toBe(route('plugins.index', [], false))
        ->and($nav[8]['icon'])->toBe('plug')
        ->and($nav[9]['href'])->toBe(route('types.index', [], false))
        ->and($nav[9]['icon'])->toBe('shapes')
        ->and($nav[10]['href'])->toBe(route('taxonomies.index', [], false))
        ->and($nav[10]['icon'])->toBe('tags')
        ->and($nav[11]['href'])->toBe(route('users.index', [], false));
});

it('shows people only what they may open', function (string $role, array $keys): void {
    $person = User::factory()->create()->assignRole($role);

    $nav = $this->actingAs($person)->get(route('dashboard'))->viewData('page')['props']['nav'];

    expect(array_column($nav, 'key'))->toBe($keys);
})->with([
    // Editors hold terms.manage, so Categories and Tags are theirs, under
    // Posts, and media.upload, but not users.manage or any settings
    // ability. They manage redirects, so Tools is theirs (I-850).
    'an editor' => ['editor', ['dashboard', 'posts', 'pages', 'media', 'navigation', 'regions', 'comments', 'tools']],
    // Authors hold media.upload, and neither terms.manage, users.manage nor
    // a settings ability; they look after their own items' comments (I-483).
    // Pages are editors' and administrators' (I-3313).
    'an author' => ['author', ['dashboard', 'posts', 'media', 'comments']],
    // Contributors hold content.create and nothing that opens another list.
    // Pages are editors' and administrators' (I-3313).
    'a contributor' => ['contributor', ['dashboard', 'posts']],
]);

it('links Settings to its hub for whoever may manage a group, and hides it when there is none', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $mediaOnly = User::factory()->create()->assignRole('editor');
    $mediaOnly->givePermissionTo('settings.media.manage');
    $author = User::factory()->create()->assignRole('author');

    $settings = fn (User $user): ?array => collect($this->actingAs($user)->get(route('dashboard'))->viewData('page')['props']['nav'])
        ->firstWhere('key', 'settings');

    expect($settings($admin)['href'])->toBe(route('settings.index', [], false))
        ->and($settings($mediaOnly)['href'])->toBe(route('settings.index', [], false))
        ->and($settings($author))->toBeNull();
});

it('builds the sidebar from the server, not from a list in the browser', function (): void {
    $source = file_get_contents(resource_path('js/components/app-sidebar.tsx'));

    expect($source)->not->toContain('contentIndex(')
        ->and($source)->not->toContain('termsIndex(')
        ->and($source)->toContain('usePage().props');
});

it('shows Comments with how many wait on the items the person looks after, and leaves it out while comments are off (D18, D19)', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $editor = User::factory()->create()->assignRole('editor');
    cmComment(cmItem($author, 'Harbour news'), 'pending');
    cmComment(cmItem($editor, 'Market day'), 'pending');
    cmComment(cmItem($editor, 'Tide tables'), 'pending');
    $comments = fn (User $user): ?array => collect($this->actingAs($user)->get(route('dashboard'))->viewData('page')['props']['nav'])->firstWhere('key', 'comments');

    expect($comments($editor))->toMatchArray(['label' => 'Comments', 'icon' => 'message-square', 'href' => route('comments.index', [], false), 'badge' => 3])
        ->and($comments($author)['badge'] ?? null)->toBe(1);

    cmSettings(['comments' => 'off'], 'modules');

    expect($comments($editor))->toBeNull();
});

it('adds a child\'s badge to its parent\'s, and gives an entry with no provider none (I-220, D19)', function (): void {
    app()->instance('test.badge.two', new class implements BadgeProvider
    {
        public function countFor(User $user): int
        {
            return 2;
        }
    });
    $registry = app(NavigationRegistry::class);
    $registry->register(new NavEntry('reports', 'Reports', 'file', NavSection::Site, 500, route: 'dashboard', badge: 'test.badge.two'));
    $registry->register(new NavEntry('reports.jobs', 'Jobs', 'file', NavSection::Site, 10, route: 'dashboard', parent: 'reports', badge: 'test.badge.two'));
    $registry->register(new NavEntry('reports.status', 'Status', 'file', NavSection::Site, 20, route: 'dashboard', parent: 'reports'));

    $nav = collect(app(AdminNavigation::class)->forUser(User::factory()->create()->assignRole('administrator')->id));
    $reports = $nav->firstWhere('key', 'reports');

    expect($reports['badge'] ?? null)->toBe(4)
        ->and(array_column($reports['children'] ?? [], 'badge', 'key'))->toBe(['reports.jobs' => 2, 'reports.status' => 0])
        ->and($nav->firstWhere('key', 'dashboard')['badge'] ?? null)->toBe(0);
});

it('keeps a taxonomy no listed type uses at the top of Content', function (): void {
    app(TaxonomyRegistry::class)->register(new Taxonomy('topic', 'Topic', 'Topics', 'topics', icon: 'tag'));
    $admin = User::factory()->create()->assignRole('administrator');

    $nav = collect(app(AdminNavigation::class)->forUser($admin->id));

    expect($nav->firstWhere('key', 'topics'))->toMatchArray(['label' => 'Topics', 'section' => 'content', 'href' => route('terms.index', ['taxonomy' => 'topics'], false), 'children' => []])
        ->and(array_column($nav->firstWhere('key', 'posts')['children'], 'key'))->toBe(['categories', 'tags']);
});

it('brings a taxonomy up to the top of Content for someone who may manage its terms but not open its type', function (): void {
    $person = User::factory()->create();
    $person->givePermissionTo('terms.manage');

    $nav = app(AdminNavigation::class)->forUser($person->id);

    expect(array_column($nav, 'key'))->toBe(['dashboard', 'categories', 'tags'])
        ->and(array_column($nav, 'section'))->toBe(['home', 'content', 'content'])
        ->and(array_column($nav, 'children'))->toBe([[], [], []]);
});

it('gives Settings and Tools the screens the person may open, and every other entry none', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $editor = User::factory()->create()->assignRole('editor');
    $screens = fn (User $user, string $key): array => collect(app(AdminNavigation::class)->forUser($user->id))->firstWhere('key', $key)['screens'] ?? [];

    // Each list is read by its own entries: a later part adds to the Tools hub.
    expect($screens($admin, 'settings'))->toContain(['key' => 'media', 'label' => 'Media', 'icon' => 'settings', 'href' => route('settings.show', ['group' => 'media'], false)])
        ->and($screens($admin, 'tools'))->toContain(['key' => 'redirects', 'label' => 'Redirects', 'icon' => 'arrow-right', 'href' => route('tools.redirects.index', [], false)])
        // Editors manage redirects, and only administrators clear the page cache (I-850).
        ->and(array_column($screens($editor, 'tools'), 'key'))->toContain('redirects')->not->toContain('page-cache')
        ->and($screens($admin, 'media'))->toBe([])
        ->and($screens($admin, 'posts'))->toBe([]);
});

it('opens Settings on its hub, its pages listed in the menu\'s order', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $settings = collect(app(AdminNavigation::class)->forUser($admin->id))->firstWhere('key', 'settings');

    expect($settings['href'] ?? null)->toBe(route('settings.index', [], false))
        ->and($settings['screens'][0]['key'] ?? null)->toBe('general');
});
