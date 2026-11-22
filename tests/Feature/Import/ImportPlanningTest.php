<?php

declare(strict_types=1);

use App\Domain\Import\Planning\ImportAnalysis;
use App\Domain\Import\Planning\ImportChoices;
use App\Domain\Import\Planning\SiteAnalysis;
use App\Domain\Import\Source\SourceRefused;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->fake = importFake();
    $this->source = importSource($this->admin);

    $this->analysis = fn (): SiteAnalysis => app(ImportAnalysis::class)->read($this->source->id);

    // The choices the screen would send with every default kept.
    $this->input = fn (array $changes = []): array => array_replace([
        'menu_locations' => ['primary' => 'primary', 'footer_navigation' => ''],
        'templates' => ['template-full-width.php' => 'full-width', 'template-landing.php' => ''],
        'roles' => ['wpseo_manager' => 'editor', 'wpseo_editor' => 'contributor'],
        'owner' => (string) $this->admin->id,
        'outside_images' => ['cdn.partner.example'],
        'pause' => '2000',
    ], $changes);
});

it('counts what comes across from the site report (study V §4.0)', function (): void {
    $analysis = ($this->analysis)()->toArray();

    expect($analysis['reported'])->toBeTrue()
        ->and($analysis['reportedAt'])->toBe('2026-10-01T09:30:00+00:00')
        ->and($analysis['seo'])->toBe('Yoast SEO')
        ->and($analysis['comes'])->toBe([
            ['key' => 'posts', 'label' => 'Posts', 'count' => 126],
            ['key' => 'pages', 'label' => 'Pages', 'count' => 9],
            ['key' => 'media', 'label' => 'Media files', 'count' => 340],
            ['key' => 'categories', 'label' => 'Categories', 'count' => 12],
            ['key' => 'tags', 'label' => 'Tags', 'count' => 85],
            ['key' => 'people', 'label' => 'People', 'count' => 5],
            ['key' => 'comments', 'label' => 'Comments', 'count' => 231],
            ['key' => 'menus', 'label' => 'Menus', 'count' => 2],
        ]);
});

it('lists what stays behind, each with its count and why (I-328, I-344, I-1701)', function (): void {
    expect(($this->analysis)()->toArray()['stays'])->toBe([
        ['key' => 'type.idb_movie', 'wordpressKey' => 'idb_movie', 'label' => 'Movies', 'count' => 42, 'why' => 'A custom type. Teme can’t import these yet.'],
        ['key' => 'type.ap_outbox', 'wordpressKey' => 'ap_outbox', 'label' => 'Outbox', 'count' => 300, 'why' => 'A custom type. Teme can’t import these yet.'],
        ['key' => 'taxonomy.idb_genre', 'wordpressKey' => 'idb_genre', 'label' => 'Genres', 'count' => 9, 'why' => 'A custom taxonomy. Teme can’t import these yet.'],
        ['key' => 'subscribers', 'wordpressKey' => null, 'label' => 'Subscribers', 'count' => 2, 'why' => 'Readers can’t have accounts on Teme yet. Their comments come across, and those who have published posts come as contributors.'],
        ['key' => 'revisions', 'wordpressKey' => null, 'label' => 'Revisions', 'count' => 640, 'why' => 'Earlier versions stay in WordPress.'],
        ['key' => 'widgets', 'wordpressKey' => null, 'label' => 'Widgets', 'count' => 6, 'why' => 'Set up your theme’s regions again in Content › Regions.'],
        ['key' => 'other-comments', 'wordpressKey' => null, 'label' => 'Other comments', 'count' => 21, 'why' => 'Likes, mentions and notes plugins kept as comments.'],
    ]);
});

it('warns only when public custom types hold most of the published items (study V §5)', function (string $case): void {
    $published = $case === 'most' ? '500' : '128';
    $this->fake->items('analyze', tiReport(['content_types' => ['post_types' => ['idb_movie' => ['counts' => ['publish' => $published]]]]]));

    $warning = ($this->analysis)()->toArray()['warning'];

    expect($warning)->toBe($case === 'most' ? ['custom' => 500, 'total' => 628] : null);
})->with(['most', 'as many as the posts and pages']);

it('says when WordPress has made no site report yet', function (): void {
    $this->fake->items('analyze', ['api' => 1, 'report' => null, 'message' => 'No report yet. Run Tools › Teme site report first.']);

    $analysis = ($this->analysis)();

    expect($analysis->reported)->toBeFalse()
        ->and($analysis->toArray())->toMatchArray(['reportedAt' => null, 'comes' => [], 'stays' => [], 'warning' => null, 'seo' => null])
        ->and($analysis->menuLocations())->toBe([]);
});

it('reads counts written as text and leaves out what a report doesn’t have', function (): void {
    $this->fake->items('analyze', ['api' => 1, 'report' => ['generated_at' => 'not a date', 'sections' => [
        'content_types' => ['post_types' => ['post' => ['builtin' => true, 'counts' => ['publish' => '7', 'draft' => 'many']], 'idb_book' => ['builtin' => false]]],
        'users' => ['total' => '4', 'by_role' => ['subscriber' => '1', 'none' => '1'], 'custom_roles' => []],
    ]]]);

    $analysis = ($this->analysis)()->toArray();

    expect($analysis['reportedAt'])->toBeNull()
        ->and(array_column($analysis['comes'], 'count', 'key'))->toBe(['posts' => 7, 'pages' => 0, 'media' => 0, 'categories' => 0, 'tags' => 0, 'people' => 2, 'comments' => 0, 'menus' => 0])
        ->and(array_column($analysis['stays'], 'key'))->toBe(['subscribers'])
        ->and($analysis['seo'])->toBeNull();
});

it('keeps the analysis for six hours, and reads the site again once it is forgotten (I-261)', function (): void {
    ($this->analysis)();
    ($this->analysis)();

    expect($this->fake->requestsTo('analyze'))->toHaveCount(1)
        ->and(app(ImportAnalysis::class)->kept($this->source->id))->not->toBeNull();

    app(ImportAnalysis::class)->forget($this->source->id);

    expect(app(ImportAnalysis::class)->kept($this->source->id))->toBeNull();

    ($this->analysis)();

    expect($this->fake->requestsTo('analyze'))->toHaveCount(2);
});

it('never keeps a site’s refusal, so the next look asks again', function (): void {
    $this->fake->answer('analyze', 503);

    expect(fn () => ($this->analysis)())->toThrow(SourceRefused::class, 'The site is busy or had an error (503). Try again in a minute.')
        ->and(app(ImportAnalysis::class)->kept($this->source->id))->toBeNull();

    ($this->analysis)();

    expect($this->fake->requestsTo('analyze'))->toHaveCount(2);
});

it('offers each choice from the analysis and from Teme, each with its default (I-1703)', function (): void {
    $form = app(ImportChoices::class)->form(($this->analysis)(), (string) $this->admin->id);

    expect($form)->toBe([
        'menuLocations' => [['key' => 'primary', 'value' => 'primary'], ['key' => 'footer_navigation', 'value' => '']],
        'slots' => [['value' => 'primary', 'label' => 'Header'], ['value' => 'footer', 'label' => 'Footer']],
        'templates' => [['key' => 'template-full-width.php', 'count' => 2, 'value' => ''], ['key' => 'template-landing.php', 'count' => 1, 'value' => '']],
        'themeTemplates' => [['value' => 'full-width', 'label' => 'Full width']],
        'roles' => [['key' => 'wpseo_manager', 'value' => 'editor'], ['key' => 'wpseo_editor', 'value' => 'author']],
        'roleOptions' => [
            ['value' => 'administrator', 'label' => 'Administrator'],
            ['value' => 'editor', 'label' => 'Editor'],
            ['value' => 'author', 'label' => 'Author'],
            ['value' => 'contributor', 'label' => 'Contributor'],
            ['value' => 'none', 'label' => 'Leave behind'],
        ],
        'owner' => (string) $this->admin->id,
        'people' => [['value' => (string) $this->admin->id, 'label' => 'Ada Admin']],
        'outsideHosts' => [['host' => 'cdn.partner.example', 'count' => 40], ['host' => 'photos.friend.example', 'count' => 12]],
        'pause' => 1000,
        'pauses' => [
            ['value' => 0, 'label' => 'No pause'],
            ['value' => 500, 'label' => 'Half a second'],
            ['value' => 1000, 'label' => 'One second'],
            ['value' => 2000, 'label' => 'Two seconds'],
            ['value' => 5000, 'label' => 'Five seconds'],
        ],
    ]);
});

it('finds the nearest Teme role at or below a custom role, from its capabilities, as the users phase does', function (array $capabilities, ?string $expected): void {
    expect(ImportChoices::suggestedRole($capabilities))->toBe($expected);
})->with([
    'everything an administrator does' => [['read', 'edit_posts', 'upload_files', 'publish_posts', 'edit_others_posts', 'publish_pages', 'manage_options', 'edit_users'], 'administrator'],
    'site settings, but not other people\'s accounts' => [['read', 'edit_posts', 'upload_files', 'publish_posts', 'edit_others_posts', 'publish_pages', 'manage_options'], 'editor'],
    'an editor\'s, as Yoast SEO copies them' => [['read', 'edit_posts', 'upload_files', 'publish_posts', 'edit_others_posts', 'publish_pages', 'wpseo_bulk_edit'], 'editor'],
    'publishing their own posts' => [['read', 'edit_posts', 'upload_files', 'publish_posts'], 'author'],
    'publishing without uploading' => [['read', 'edit_posts', 'publish_posts'], 'contributor'],
    'reading only, as a shop\'s customer' => [['read'], null],
    'settings and accounts, but no writing' => [['read', 'manage_options', 'edit_users'], null],
]);

it('proposes leaving behind the people of a custom role that can’t write (I-1701)', function (): void {
    $this->fake->items('analyze', tiReport(['users' => ['custom_roles' => ['customer' => ['read']]]]));

    expect(app(ImportChoices::class)->form(($this->analysis)(), (string) $this->admin->id)['roles'])
        ->toContain(['key' => 'customer', 'value' => 'none']);
});

it('keeps choices that fit as the run’s choices, with the pause as an option', function (): void {
    $kept = app(ImportChoices::class)->validated(($this->input)(['roles' => ['wpseo_manager' => 'editor', 'wpseo_editor' => 'none']]), ($this->analysis)());

    expect($kept)->toBe([
        'choices' => [
            'menu_locations' => ['primary' => 'primary', 'footer_navigation' => null],
            'templates' => ['template-full-width.php' => 'full-width', 'template-landing.php' => null],
            'roles' => ['wpseo_manager' => 'editor', 'wpseo_editor' => 'none'],
            'owner' => (string) $this->admin->id,
            'outside_images' => ['cdn.partner.example'],
        ],
        'pauseMs' => 2000,
    ]);
});

it('refuses a choice that doesn’t fit, under its field, in words', function (string $case): void {
    [$changes, $field, $words] = match ($case) {
        'a place the theme lacks' => [['menu_locations' => ['primary' => 'sidebar', 'footer_navigation' => '']], 'menu_locations.primary', 'Choose one of the theme’s places for menus, or Don’t place it.'],
        'a template the theme lacks' => [['templates' => ['template-full-width.php' => 'landing', 'template-landing.php' => '']], 'templates.template-full-width.php', 'Choose one of the theme’s templates.'],
        'a role Teme lacks' => [['roles' => ['wpseo_manager' => 'editor', 'wpseo_editor' => 'subscriber']], 'roles.wpseo_editor', 'Choose one of Teme’s roles.'],
        'someone who can’t write' => [['owner' => '999999'], 'owner', 'Choose someone who can write on Teme.'],
        'a site not listed' => [['outside_images' => ['evil.example']], 'outside_images', 'Tick only the sites listed.'],
        'a pause not offered' => [['pause' => '750'], 'pause', 'Choose one of the pauses listed.'],
        'a location the report doesn’t list' => [['menu_locations' => ['primary' => 'primary', 'footer_navigation' => '', 'sidebar_left' => '']], 'menu_locations', 'Analyze the site again: its report has changed.'],
        'a template the report doesn’t list' => [['templates' => ['template-full-width.php' => '', 'template-landing.php' => '', 'old.php' => '']], 'templates', 'Analyze the site again: its report has changed.'],
    };

    try {
        app(ImportChoices::class)->validated(($this->input)($changes), ($this->analysis)());
        $this->fail('The choices were kept.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe([$field => [$words]]);
    }
})->with([
    'a place the theme lacks', 'a template the theme lacks', 'a role Teme lacks', 'someone who can’t write',
    'a site not listed', 'a pause not offered', 'a location the report doesn’t list', 'a template the report doesn’t list',
]);

/**
 * The fixture's site report, saying Polylang is on with five languages.
 *
 * @return array<string, mixed>
 */
function plPolylangReport(): array
{
    $answer = json_decode((string) file_get_contents(base_path('tests/Fixtures/Import/Companion/analyze.json')), true, flags: JSON_THROW_ON_ERROR);
    $answer['report']['sections']['multilingual'] = ['wpml' => null, 'polylang' => ['version' => '3.7.4', 'languages' => 5], 'translatepress' => null, 'weglot' => null];

    return $answer;
}

it('refuses to analyze a Polylang site through a companion too old to bring its languages, naming the version that can', function (): void {
    $this->fake->items('analyze', plPolylangReport());
    $this->fake->answer('languages', 404, ['code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.', 'data' => ['status' => 404]]);

    expect($this->analysis)->toThrow(function (SourceRefused $refusal): void {
        expect($refusal->reason)->toBe(SourceRefused::TOO_OLD)
            ->and($refusal->retryable)->toBeFalse()
            ->and($refusal->sentence())->toBe('The site report says Polylang is on, with 5 languages, and this site’s Teme Companion plugin is too old to bring languages across: everything would come in one language. Update the plugin to 0.3.0 or later, then choose Analyze again.');
    });

    expect(app(ImportAnalysis::class)->kept($this->source->id))->toBeNull();
});

it('analyzes a Polylang site whose companion brings its languages', function (): void {
    $this->fake->items('analyze', plPolylangReport());

    expect(($this->analysis)()->reported)->toBeTrue();
});
