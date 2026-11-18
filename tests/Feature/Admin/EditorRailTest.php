<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Taxonomy\Queries\TermPicker;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
});

it('gives the rail the publish state and the date', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    listsPublish($content, $this->author);

    $this->actingAs($this->author)
        ->get("/admin/content/{$content->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.status', 'published')
            ->where('session.publishedAt', $content->refresh()->published_at->toIso8601String()));
});

it('offers Publish to an editor and Submit for review to a contributor', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $content = lifecycleDraft($contributor, 'Their first post');

    $this->actingAs($contributor)->get("/admin/content/{$content->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('can.publish', false));
    $this->actingAs($this->editor)->get("/admin/content/{$content->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('can.publish', true));

    expect(file_get_contents(resource_path('js/components/editor/editor-rail.tsx')))->toContain('Submit for review');
});

it('keeps one pane in the rail, which a selected card opens', function (): void {
    $rail = file_get_contents(resource_path('js/components/editor/editor-rail.tsx'));

    expect($rail)->toContain('useState<RailPane | null>')
        ->toContain('CardInspector')
        ->not->toContain('selectedCard');
});

it('counts images with no alt text and says when there is no excerpt', function (): void {
    $input = tempnam(sys_get_temp_dir(), 'teme-checks');
    file_put_contents($input, json_encode([
        'title' => 'Harbour festival',
        'fields' => [BuiltInFields::EXCERPT => ''],
        'document' => docWith(
            // Missing: no alt on the card, none in the library.
            ['type' => 'image', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3A', 'mediaId' => 'a', 'alt' => '', 'decorative' => false]],
            // Not missing: the library's alt is used.
            ['type' => 'image', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3B', 'mediaId' => 'b', 'alt' => '', 'decorative' => false]],
            // Not missing: decorative.
            ['type' => 'image', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3C', 'mediaId' => 'a', 'alt' => '', 'decorative' => true]],
            // Not missing: the card's own alt.
            ['type' => 'image', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3D', 'mediaId' => 'a', 'alt' => 'Boats', 'decorative' => false]],
            // Missing: one of its images has no alt in the library.
            ['type' => 'gallery', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3E', 'mediaIds' => ['a', 'b']]],
        ),
        'media' => [
            'a' => ['variants' => [], 'width' => 1200, 'height' => 800, 'alt' => '', 'title' => 'A'],
            'b' => ['variants' => [], 'width' => 1200, 'height' => 800, 'alt' => 'In the library', 'title' => 'B'],
        ],
        'linked' => (object) [],
    ]));

    $run = Process::timeout(60)->run(['node', base_path('tests/Fixtures/checks.mjs'), $input]);
    unlink($input);

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and(json_decode($run->output(), true))->toBe(['hasTitle' => true, 'missingAlt' => 2, 'hasExcerpt' => false, 'hasFeaturedImage' => false, 'badLinks' => []]);
});

it('gives a post a picker per taxonomy, and a page none', function (): void {
    $post = lifecycleDraft($this->author, 'A post');
    $about = lifecycleDraft($this->editor, 'About us', 'page');

    $this->actingAs($this->author)->get("/admin/content/{$post->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.taxonomies.0.key', 'category')
            ->where('session.taxonomies.0.hierarchical', true)
            ->where('session.taxonomies.1.key', 'tag')
            ->where('session.taxonomies.1.hierarchical', false));

    $this->actingAs($this->editor)->get("/admin/content/{$about->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('session.taxonomies', []));
});

it('gives a hierarchical taxonomy its whole tree, each term with its parent', function (): void {
    $news = taxonomyTerm('category', 'News');
    $local = taxonomyTerm('category', 'Local', $news->id);

    expect(app(TermPicker::class)->tree('category'))->toContain(
        ['id' => $news->id, 'name' => 'News', 'parentId' => null],
        ['id' => $local->id, 'name' => 'Local', 'parentId' => $news->id],
    );
});

it('draws the tree picker to its spec', function (): void {
    expect(file_get_contents(resource_path('js/components/term-tree-picker.tsx')))
        ->toContain('<ScrollArea className="max-h-44">')
        ->toContain('SEARCH_AFTER = 8')
        ->toContain('+ Add')
        ->toContain('canAdd');
});

it('gives the rail the address, the author and how many revisions there are', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Three days of music.')));

    $this->actingAs($this->author)->get("/admin/content/{$content->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.slug', $content->refresh()->slug)
            ->where('session.addressPattern', '/{slug}/')
            ->where('session.author', ['id' => $this->author->id, 'name' => 'Ada'])
            ->where('session.revisionCount', 2)
            ->where('siteUrl', url('/')));
});

it('gives a hierarchical taxonomy its tree in the session, and says who may add terms', function (): void {
    $news = taxonomyTerm('category', 'News');
    $post = lifecycleDraft($this->author, 'A post');

    $this->actingAs($this->editor)->get("/admin/content/{$post->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.taxonomies.0.terms', [['id' => $news->id, 'name' => 'News', 'parentId' => null]])
            ->where('session.taxonomies.1.terms', null)
            ->where('session.can.manageTerms', true));

    $this->actingAs($this->author)->get("/admin/content/{$post->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('session.can.manageTerms', false));
});

it('publishes from the editor, and answers with the new state', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    $this->actingAs($this->author)->postJson("/admin/content/{$content->id}/publish", [
        'revision_id' => $content->working_revision_id,
    ])->assertOk()
        ->assertJsonPath('status', 'published')
        ->assertJsonPath('publishedAt', fn (?string $at): bool => $at !== null);

    expect($content->refresh()->status)->toBe('published');
});

it('publishes nothing for someone who may not publish', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $content = lifecycleDraft($contributor, 'Their first post');

    $this->actingAs($contributor)->postJson("/admin/content/{$content->id}/publish", [
        'revision_id' => $content->working_revision_id,
    ])->assertForbidden();

    expect($content->refresh()->status)->toBe('draft');
});

it('changes the address from the editor, and refuses one that is taken', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    lifecycleDraft($this->author, 'Taken')->forceFill(['slug' => 'taken'])->save();

    $this->actingAs($this->author)->postJson("/admin/content/{$content->id}/address", [
        'slug' => 'harbour-2026', 'expected_revision_id' => $content->working_revision_id,
    ])->assertOk()->assertJsonPath('slug', 'harbour-2026');

    $this->actingAs($this->author)->postJson("/admin/content/{$content->id}/address", [
        'slug' => 'taken', 'expected_revision_id' => $content->working_revision_id,
    ])->assertUnprocessable();

    expect($content->refresh()->slug)->toBe('harbour-2026');
});

it('changes no address for someone who may not edit the article', function (): void {
    $content = lifecycleDraft($this->editor, 'Not theirs');
    $contributor = User::factory()->create()->assignRole('contributor');

    $this->actingAs($contributor)->postJson("/admin/content/{$content->id}/address", [
        'slug' => 'mine', 'expected_revision_id' => $content->working_revision_id,
    ])->assertForbidden();

    expect($content->refresh()->slug)->not->toBe('mine');
});

it('names the excerpt field in the browser\'s checks by its fixed key', function (): void {
    expect(file_get_contents(resource_path('js/components/editor/rail/checks.ts')))->toContain(BuiltInFields::EXCERPT);
});

it('adds a term from the editor\'s tree picker, and answers with it', function (): void {
    $news = taxonomyTerm('category', 'News');

    $response = $this->actingAs($this->editor)->postJson('/admin/categories', ['name' => 'Harbour', 'parent_id' => $news->id])
        ->assertCreated()
        ->assertJsonPath('name', 'Harbour')
        ->assertJsonPath('parentId', $news->id);

    expect(app(TermPicker::class)->tree('category'))->toContain(['id' => $response->json('id'), 'name' => 'Harbour', 'parentId' => $news->id]);
});

it('adds no term for someone who may not manage terms', function (): void {
    $this->actingAs($this->author)->postJson('/admin/categories', ['name' => 'Harbour'])->assertForbidden();

    expect(app(TermPicker::class)->tree('category'))->toBe([]);
});

it('gives the rail each taxonomy\'s address segment, for adding terms', function (): void {
    $post = lifecycleDraft($this->author, 'A post');

    $this->actingAs($this->author)->get("/admin/content/{$post->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('session.taxonomies.0.segment', 'categories'));
});

it('gives the rail the pending schedule', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $when = now()->addDay()->startOfMinute();
    schedFor($content, $this->editor, $when->toIso8601String());

    $this->actingAs($this->editor)->get("/admin/content/{$content->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.schedule.runAt', $when->toIso8601String())
            ->where('session.schedule.heldReason', null)
            ->where('session.schedule.failedReason', null)
            ->has('siteTimezone'));
});

it('gives the rail a pending review, with who submitted it', function (): void {
    $contributor = User::factory()->create(['name' => 'Chidi'])->assignRole('contributor');
    $content = lifecycleDraft($contributor, 'Night market');
    schedSubmit($content, $contributor);

    $this->actingAs($this->editor)->get("/admin/content/{$content->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.review.state', 'pending')
            ->where('session.review.submittedBy', 'Chidi')
            ->where('session.review.revisionId', $content->refresh()->working_revision_id)
            ->where('can.publish', true)
            ->has('siteTimezone'));
});

it('schedules and cancels through the routes, and refuses someone who may not publish', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $edit = "/admin/content/{$content->id}/edit";

    $this->actingAs($this->editor)->from($edit)
        ->post("/admin/content/{$content->id}/schedule", [
            'revision_id' => $content->refresh()->working_revision_id,
            'action' => 'publish',
            'run_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertRedirect($edit);

    expect($content->refresh()->status)->toBe('scheduled');

    $this->actingAs($this->editor)->from($edit)
        ->delete("/admin/content/{$content->id}/schedule")
        ->assertRedirect($edit);

    expect($content->refresh()->status)->toBe('draft');

    $contributor = User::factory()->create()->assignRole('contributor');
    $theirs = lifecycleDraft($contributor, 'Night market');

    $this->actingAs($contributor)
        ->post("/admin/content/{$theirs->id}/schedule", [
            'revision_id' => $theirs->refresh()->working_revision_id,
            'action' => 'publish',
            'run_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertForbidden();

    expect(DB::table('scheduled_publications')->where('content_id', $theirs->id)->count())->toBe(0);
});

it('submits, returns and approves through the routes, and refuses a decision from the writer', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $content = lifecycleDraft($contributor, 'Night market');
    $edit = "/admin/content/{$content->id}/edit";

    $this->actingAs($contributor)->from($edit)
        ->post("/admin/content/{$content->id}/review", ['revision_id' => $content->refresh()->working_revision_id])
        ->assertRedirect($edit);
    $reviewId = DB::table('content_reviews')->where('content_id', $content->id)->whereNull('decided_at')->value('id');

    $this->actingAs($contributor)
        ->post("/admin/content/{$content->id}/review/approve", ['review_id' => $reviewId])
        ->assertForbidden();

    $this->actingAs($this->editor)->from($edit)
        ->post("/admin/content/{$content->id}/review/send-back", ['review_id' => $reviewId, 'note' => 'Add a photo credit.'])
        ->assertRedirect($edit);

    expect($content->refresh()->status)->toBe('draft');

    $this->actingAs($contributor)
        ->post("/admin/content/{$content->id}/review", ['revision_id' => $content->refresh()->working_revision_id]);
    $again = DB::table('content_reviews')->where('content_id', $content->id)->whereNull('decided_at')->value('id');

    $this->actingAs($this->editor)->from($edit)
        ->post("/admin/content/{$content->id}/review/approve", ['review_id' => $again])
        ->assertRedirect($edit);

    expect($content->refresh()->status)->toBe('published');
});

it('serves the schedule and review endpoints inside the web group, where CSRF is checked', function (string $name): void {
    expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('web');
})->with(['content.schedule.store', 'content.schedule.destroy', 'content.review.store', 'content.review.approve', 'content.review.send-back']);

it('answers the editor in JSON with the new state, so the editor stays open', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $when = now()->addDay()->startOfMinute();

    $this->actingAs($this->editor)
        ->postJson("/admin/content/{$content->id}/schedule", [
            'revision_id' => $content->refresh()->working_revision_id,
            'action' => 'publish',
            'run_at' => $when->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('status', 'scheduled')
        ->assertJsonPath('schedule.runAt', $when->toIso8601String())
        ->assertJsonPath('review', null);

    $this->actingAs($this->editor)
        ->deleteJson("/admin/content/{$content->id}/schedule")
        ->assertOk()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('schedule', null)
        ->assertJsonPath('message', 'Schedule cancelled.');
});

it('checkpoints before publishing, submitting or scheduling, from the action bar', function (): void {
    $page = uiComponent('pages/content/edit.tsx');

    expect($page)->toContain('const checkpointThen = ')
        // Publish, Submit for review and Schedule each go through it.
        ->and(substr_count($page, 'checkpointThen('))->toBeGreaterThanOrEqual(3)
        ->and(uiComponent('components/editor/editor-rail.tsx'))
        ->toContain('Submit for review')
        ->toContain('Approve')
        ->toContain('Return')
        ->not->toContain('Review requests arrive in a later release');
});

it('names the zone under the schedule control', function (): void {
    expect(file_get_contents(resource_path('js/components/editor/schedule-control.tsx')))
        ->toContain('siteTimezone')
        ->toContain('timeZone');
});

it('warns when an article that can have a featured image has none', function (): void {
    $without = schedChecks(['title' => 'Harbour festival', 'fields' => [], 'document' => docWith(docText()), 'media' => (object) [], 'linked' => (object) []]);
    $with = schedChecks(['title' => 'Harbour festival', 'fields' => [BuiltInFields::FEATURED_IMAGE => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2A', 'alt' => '', 'decorative' => false]], 'document' => docWith(docText()), 'media' => (object) [], 'linked' => (object) []]);

    expect($without['hasFeaturedImage'])->toBeFalse()
        ->and($with['hasFeaturedImage'])->toBeTrue();
});

it('lists the links to deleted or unpublished articles, in document order, and not the published ones', function (): void {
    $live = '01k5z9p2qh7n3x4c8v6b0m1t2a';
    $draft = '01k5z9p2qh7n3x4c8v6b0m1t2b';
    $trashed = '01k5z9p2qh7n3x4c8v6b0m1t2c';
    $gone = '01k5z9p2qh7n3x4c8v6b0m1t2d';

    $checks = schedChecks([
        'title' => 'Harbour festival',
        'fields' => [],
        'document' => docWith(
            schedLinkTo($trashed, 'the parade'),
            schedLinkTo($live, 'the festival'),
            ['type' => 'button', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3A', 'label' => 'Market', 'contentId' => $draft, 'url' => null, 'style' => 'primary']],
            schedLinkTo($gone, 'the old page'),
            schedLinkTo($trashed, 'the parade again'),
        ),
        'media' => (object) [],
        'linked' => [
            $live => ['title' => 'Harbour festival', 'state' => 'published'],
            $draft => ['title' => 'Night market', 'state' => 'unpublished'],
            $trashed => ['title' => 'Lantern parade', 'state' => 'trashed'],
            $gone => ['title' => null, 'state' => 'missing'],
        ],
    ]);

    expect($checks['badLinks'])->toBe([
        ['id' => $trashed, 'title' => 'Lantern parade', 'state' => 'trashed'],
        ['id' => $draft, 'title' => 'Night market', 'state' => 'unpublished'],
        ['id' => $gone, 'title' => null, 'state' => 'missing'],
    ]);
});

it('does not warn about a link whose state has not arrived yet', function (): void {
    $checks = schedChecks([
        'title' => 'Harbour festival',
        'fields' => [],
        'document' => docWith(schedLinkTo('01k5z9p2qh7n3x4c8v6b0m1t2b', 'the market')),
        'media' => (object) [],
        'linked' => (object) [],
    ]);

    expect($checks['badLinks'])->toBe([]);
});

it('draws the rail\'s checks from the one list, the featured image only where the type has one, and blocks nothing (I-880)', function (): void {
    expect(uiComponent('components/editor/rail/checks.ts'))
        ->toContain('None. Social cards use the first image in the body, or the site’s default.')
        ->toContain('Some lead to an item readers can’t reach:')
        ->toContain('hasFeaturedImageField')
        ->and(uiComponent('components/editor/rail/checks-panel.tsx'))->toContain('checks: WritingCheck[];')
        ->and(uiComponent('components/editor/rail/writing-checks.ts'))->toContain('export function writingChecks(')
        ->and(uiComponent('pages/content/edit.tsx'))->toContain('hasFeaturedImageField: featured !== undefined')
        ->toContain('useLinkedContent(')
        // Warnings, never gates: the action bar, where Publish, Schedule and
        // Submit live, hands the checks to ChecksPanel and reads none of them.
        ->and(uiComponent('components/editor/editor-rail.tsx'))->not->toMatch('/\b(writing|checks)\.(filter|some|every|length)/')
        ->and(uiComponent('components/editor/rail/publish-panel.tsx'))->not->toContain('CoreCheck');
});

it('names the featured image field and the internal link pattern in the browser\'s checks as the server does', function (): void {
    expect(uiComponent('components/editor/rail/checks.ts'))
        ->toContain(BuiltInFields::FEATURED_IMAGE)
        ->toContain('teme:content\/([0-9a-hjkmnp-tv-z]{26})');
});
