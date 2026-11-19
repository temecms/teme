<?php

declare(strict_types=1);

use App\Domain\Comments\CommentModeration;
use App\Domain\Comments\Queries\DiscussionScreen;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Dashboard\Queries\Dashboard;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->author = User::factory()->create()->assignRole('author');
    $this->contributor = User::factory()->create()->assignRole('contributor');
});

it('makes pages answer to their own six, named as a type’s own are (I-3313)', function (): void {
    $pages = app(ContentTypeRegistry::class)->get('page');

    expect(array_map(fn (string $action): string => $pages->ability($action), ['create', 'edit.own', 'edit.any', 'publish', 'delete.own', 'delete.any']))
        ->toBe(['page.create', 'page.edit.own', 'page.edit.any', 'page.publish', 'page.delete.own', 'page.delete.any'])
        ->and(app(ContentTypeRegistry::class)->get('post')->ability('create'))->toBe('content.create');
});

it('lets editors and administrators write, publish and trash pages, and still lets authors write posts', function (): void {
    foreach ([$this->editor, $this->admin] as $person) {
        $page = addrPublished($person, 'About us '.$person->id, 'page');
        app(TrashContent::class)->execute(new ContentActionInput($page->id, (string) $person->id));

        expect($page->refresh()->status)->toBe('published')
            ->and($page->deleted_at)->not->toBeNull();
    }

    expect(addrPublished($this->author, 'Harbour news')->status)->toBe('published');
});

it('writes nothing when an author or a contributor starts a page', function (string $role): void {
    $person = $this->{$role};
    $revisions = DB::table('content_revisions')->count();

    expect(fn () => lifecycleDraft($person, 'About us', 'page'))->toThrow(AuthorizationException::class);

    $this->actingAs($person)->post('/admin/pages', ['title' => 'Contact'])->assertForbidden();

    expect(Content::query()->where('type', 'page')->count())->toBe(0)
        ->and(DB::table('content_revisions')->count())->toBe($revisions);
})->with(['an author' => 'author', 'a contributor' => 'contributor']);

it('writes nothing when a writer acts on a page they wrote before pages were editors’', function (string $role, string $action): void {
    $person = $this->{$role};
    $page = pgAuthorsPage($person);
    $before = $page->only(['title', 'status', 'working_revision_id', 'published_revision_id', 'deleted_at', 'author_id']);
    $counts = fn (): array => [DB::table('content_revisions')->count(), DB::table('content_reviews')->count(), DB::table('scheduled_publications')->count()];
    $was = $counts();

    // Named, not closures: Pest calls a closure in a dataset to resolve it.
    $act = fn (): mixed => match ($action) {
        'save' => docSave($page, $person, docWith(docText('Who we are.'))),
        'details' => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($page->id, $page->working_revision_id, (string) $person->id, title: 'Who we are')),
        'publish' => listsPublish($page, $person),
        'trash' => app(TrashContent::class)->execute(new ContentActionInput($page->id, (string) $person->id)),
        'submit' => schedSubmit($page, $person),
    };

    expect($act)->toThrow(AuthorizationException::class)
        ->and($counts())->toBe($was)
        ->and($page->refresh()->only(array_keys($before)))->toBe($before);
})->with([
    'an author saves a draft (SaveDraft)' => ['author', 'save'],
    'an author changes its title (UpdateContentDetails)' => ['author', 'details'],
    'an author publishes it (PublishRevision)' => ['author', 'publish'],
    'an author trashes it (TrashContent)' => ['author', 'trash'],
    'a contributor submits it for review (SubmitForReview)' => ['contributor', 'submit'],
]);

it('keeps a page an author wrote theirs by name and an editor’s to change', function (): void {
    $page = pgAuthorsPage($this->author, published: true);
    $as = fn (User $person): array => [Gate::forUser($person)->allows('update', $page), Gate::forUser($person)->allows('publish', $page), Gate::forUser($person)->allows('delete', $page)];

    expect($as($this->author))->toBe([false, false, false])
        ->and($as($this->contributor))->toBe([false, false, false])
        ->and($as($this->editor))->toBe([true, true, true])
        ->and($as($this->admin))->toBe([true, true, true]);

    $this->actingAs($this->author)->get("/admin/content/{$page->id}/edit")->assertForbidden();
    $this->actingAs($this->editor)->get("/admin/content/{$page->id}/edit")->assertOk();
});

it('shuts Pages to authors and contributors: the list, the menu and New page', function (string $role, array $menu): void {
    $person = $this->{$role};

    $this->actingAs($person)->get('/admin/pages')->assertForbidden();
    $this->actingAs($person)->get('/admin/posts')->assertOk();

    $this->actingAs($person)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nav', fn ($nav): bool => collect($nav)->pluck('key')->all() === $menu)
            ->where('contentTypes', fn ($types): bool => collect($types)->firstWhere('key', 'page')['canCreate'] === false
                && collect($types)->firstWhere('key', 'post')['canCreate'] === true));

    $this->actingAs($this->editor)->get('/admin/pages')->assertOk();
})->with([
    'an author' => ['author', ['dashboard', 'posts', 'media', 'comments']],
    'a contributor' => ['contributor', ['dashboard', 'posts']],
]);

it('links Home’s figures only to the lists the person may open', function (): void {
    $figures = fn (User $person): array => collect(app(Dashboard::class)->forUser($person)['figures'])->pluck('url', 'key')->all();

    expect($figures($this->author)['page.published'])->toBeNull()
        ->and($figures($this->author)['post.published'])->toBe(route('content.index', ['type' => 'posts', 'tab' => 'published']))
        ->and($figures($this->contributor)['page.drafts'])->toBeNull()
        ->and($figures($this->editor)['page.published'])->toBe(route('content.index', ['type' => 'pages', 'tab' => 'published']));
});

it('leaves a page’s comments to editors and administrators, its author’s own posts still hers', function (): void {
    $page = pgAuthorsPage($this->author, published: true);
    $post = cmItem($this->author, 'Harbour news');

    expect(CommentModeration::allows($this->author, $page))->toBeFalse()
        ->and(CommentModeration::allows($this->author, $post))->toBeTrue()
        ->and(CommentModeration::allows($this->editor, $page))->toBeTrue();
});

it('holds a page an author scheduled before pages were editors’, and shows it held on an editor’s Home', function (): void {
    // Authors as they stood before (I-482): page rights, taken away after scheduling.
    $before = ['page.create', 'page.edit.own', 'page.publish'];
    Role::findByName('author')->givePermissionTo($before);
    $page = lifecycleDraft($this->author, 'Opening hours', 'page');
    schedFor($page, $this->author, now()->addMinute()->toIso8601String());
    Role::findByName('author')->revokePermissionTo($before);
    $this->travel(2)->minutes();

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    expect($page->refresh()->status)->toBe('scheduled')
        ->and($page->published_revision_id)->toBeNull()
        ->and(schedRow($page)->held_reason)->toBe('scheduler_cannot_publish')
        ->and(collect(dashPanel($this->editor, 'scheduled')['rows'] ?? [])->firstWhere('id', $page->id)['subtitle'] ?? null)
        ->toStartWith('Held: the person who scheduled it can no longer publish');
});

it('shows Pages on Site › Types with their own six, held by administrators and editors', function (): void {
    $this->actingAs($this->admin)->get('/admin/types/page')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('type.permissions', 'own')
            ->where('permissions.actions.0.ability', 'page.create')
            ->where('permissions.actions.5.ability', 'page.delete.any')
            ->where('permissions.holds.administrator', ['create', 'edit.own', 'edit.any', 'publish', 'delete.own', 'delete.any'])
            // An editor holds page.delete.any and not .own; "anyone’s" covers their own (I-3312).
            ->where('permissions.holds.editor', ['create', 'edit.own', 'edit.any', 'publish', 'delete.own', 'delete.any'])
            ->where('permissions.holds.author', [])
            ->where('permissions.holds.contributor', []));
});

it('says on Settings › Discussion that authors skip the checks on their own posts alone', function (): void {
    expect(DiscussionScreen::INFO['Moderation']['value'])
        ->toBe('Editors and administrators, and authors on their own posts: their comments are approved at once.');
});
