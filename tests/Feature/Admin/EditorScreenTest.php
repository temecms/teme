<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
});

it('opens the editor on the working revision', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Three days of music.')));

    $this->actingAs($this->author)
        ->get("/admin/content/{$content->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('content/edit')
            ->where('session.title', 'Harbour festival')
            ->where('session.fromAutosave', false)
            ->where('session.workingRevisionId', $content->refresh()->working_revision_id)
            ->where('session.document.content.0.content.0.text', 'Three days of music.')
            ->where('can.edit', true));
});

it('opens on the person\'s own unsaved work when there is some, and says so', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    edSave($content->id, $this->author, docWith(docText('Half a sentence')), title: 'Harbour festival 2026');

    $this->actingAs($this->author)
        ->get("/admin/content/{$content->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.fromAutosave', true)
            ->where('session.title', 'Harbour festival 2026')
            ->where('session.document.content.0.content.0.text', 'Half a sentence')
            ->whereNot('session.savedAt', null));
});

it('refuses the editor to someone who may not edit the article', function (): void {
    $content = lifecycleDraft($this->editor, 'Not theirs');
    $contributor = User::factory()->create()->assignRole('contributor');

    $this->actingAs($contributor)->get("/admin/content/{$content->id}/edit")->assertForbidden();
});

it('refuses the editor for a trashed article', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    app(TrashContent::class)->execute(new ContentActionInput($content->id, (string) $this->author->id));

    $this->actingAs($this->author)->get("/admin/content/{$content->id}/edit")->assertNotFound();
});

it('accepts an autosave and answers with the time it was saved', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    $this->actingAs($this->author)
        ->postJson("/admin/content/{$content->id}/autosave", [
            'title' => 'Harbour festival',
            'document' => docWith(docText('Typing')),
            'fields' => [],
            'terms' => [],
            'based_on_revision_id' => $content->working_revision_id,
        ])
        ->assertOk()
        ->assertJsonPath('stale', false)
        ->assertJsonPath('workingRevisionId', $content->working_revision_id)
        ->assertJsonStructure(['savedAt', 'stale', 'workingRevisionId', 'otherEditors']);

    expect(DB::table('content_autosaves')->where('content_id', $content->id)->count())->toBe(1);
});

it('keeps the spaces at the edges of text beside a bold word, when it autosaves and when it saves a new draft', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $paragraph = ['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Fares rise on '],
        ['type' => 'text', 'text' => 'Monday', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => ' and again in May.'],
    ]];
    $texts = fn (?string $json): array => array_column(json_decode((string) $json, true)['content'][0]['content'], 'text');

    $this->actingAs($this->author)
        ->postJson("/admin/content/{$content->id}/autosave", [
            'title' => 'Harbour festival',
            'document' => docWith($paragraph),
            'fields' => [],
            'terms' => [],
            'based_on_revision_id' => $content->working_revision_id,
        ])
        ->assertOk();

    $draft = $this->actingAs($this->author)
        ->postJson("/admin/content/{$content->id}/save-as-draft", ['title' => 'A copy', 'document' => docWith($paragraph), 'fields' => [], 'terms' => []])
        ->assertCreated()
        ->json('id');
    $revision = DB::table('contents')->where('id', $draft)->value('working_revision_id');

    expect($texts(DB::table('content_autosaves')->where('content_id', $content->id)->value('document')))->toBe(['Fares rise on ', 'Monday', ' and again in May.'])
        ->and($texts(DB::table('content_revisions')->where('id', $revision)->value('document')))->toBe(['Fares rise on ', 'Monday', ' and again in May.']);
});

it('answers a stale autosave with stale, and does not write', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $stale = $content->working_revision_id;
    docSave($content, $this->editor, docWith(docText('Theirs')));

    $this->actingAs($this->author)
        ->postJson("/admin/content/{$content->id}/autosave", [
            'title' => 'Harbour festival',
            'document' => docWith(docText('Mine')),
            'fields' => [],
            'terms' => [],
            'based_on_revision_id' => $stale,
        ])
        ->assertOk()
        ->assertJsonPath('stale', true)
        ->assertJsonPath('workingRevisionId', $content->refresh()->working_revision_id);

    expect(DB::table('content_autosaves')->where('content_id', $content->id)->count())->toBe(0);
});

it('sends the card manifest to the browser, so the editor does not restate it', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    $this->actingAs($this->author)
        ->get("/admin/content/{$content->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manifest.cards', 14)
            ->has('manifest.cards.0', fn (Assert $card) => $card
                ->hasAll(['name', 'label', 'group', 'keywords', 'icon', 'attributes'])
                ->etc())
            ->where('manifest.embedProviders.0.name', 'youtube')
            ->where('manifest.embedProviders.0.frame', 'https://www.youtube-nocookie.com/embed/$1'));
});

it('writes no revision while the person types', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $revisions = DB::table('content_revisions')->where('content_id', $content->id)->count();

    foreach (['One', 'Two', 'Three'] as $text) {
        $this->actingAs($this->author)->postJson("/admin/content/{$content->id}/autosave", [
            'title' => 'Harbour festival', 'document' => docWith(docText($text)), 'fields' => [], 'terms' => [],
            'based_on_revision_id' => $content->working_revision_id,
        ])->assertOk();
    }

    expect(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($revisions);
});

it('writes one revision on an explicit save, and answers with its ID', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $revisions = DB::table('content_revisions')->where('content_id', $content->id)->count();
    edSave($content->id, $this->author, docWith(docText('Three days of music.')));

    $response = $this->actingAs($this->author)->postJson("/admin/content/{$content->id}/checkpoint")->assertOk();

    expect(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($revisions + 1)
        ->and($response->json('revisionId'))->toBe($content->refresh()->working_revision_id);
});

it('answers a stale explicit save with 409, and writes nothing', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    edSave($content->id, $this->author, docWith(docText('Mine')));
    docSave($content, $this->editor, docWith(docText('Theirs')));
    $revisions = DB::table('content_revisions')->where('content_id', $content->id)->count();

    $this->actingAs($this->author)
        ->postJson("/admin/content/{$content->id}/checkpoint")
        ->assertStatus(409)
        ->assertJsonPath('stale', true);

    expect(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($revisions);
});

it('refuses an autosave whose document does not fit the schema', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    $this->actingAs($this->author)->postJson("/admin/content/{$content->id}/autosave", [
        'title' => 'Harbour festival', 'document' => docWith(['type' => 'script', 'attrs' => []]), 'fields' => [], 'terms' => [],
        'based_on_revision_id' => $content->working_revision_id,
    ])->assertUnprocessable();

    expect(DB::table('content_autosaves')->where('content_id', $content->id)->count())->toBe(0);
});

it('serves the autosave and checkpoint endpoints inside the web group, where CSRF is checked', function (string $name): void {
    expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('web');
})->with(['content.autosave', 'content.checkpoint']);

it('gives the browser the save timings from config, so they live in one place', function (): void {
    config(['editor.autosave_idle_ms' => 1500, 'editor.autosave_max_ms' => 9000, 'editor.checkpoint_after_minutes' => 4]);
    $content = lifecycleDraft($this->author, 'Harbour festival');

    $this->actingAs($this->author)
        ->get("/admin/content/{$content->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('editor.autosaveIdleMs', 1500)
            ->where('editor.autosaveMaxMs', 9000)
            ->where('editor.checkpointAfterMinutes', 4));
});

it('says who changed the article, and when, when a save is stale', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $basedOn = $content->working_revision_id;
    edSave($content->id, $this->author, docWith(docText('Mine')));
    docSave($content, $this->editor, docWith(docText('Theirs')));

    $this->actingAs($this->author)
        ->postJson("/admin/content/{$content->id}/checkpoint")
        ->assertStatus(409)
        ->assertJsonPath('changedBy', 'Eve')
        ->assertJsonPath('changedAt', fn (?string $at): bool => $at !== null);

    $this->actingAs($this->author)->postJson("/admin/content/{$content->id}/autosave", [
        'title' => 'Harbour festival', 'document' => docWith(docText('Mine, again')), 'fields' => [], 'terms' => [],
        'based_on_revision_id' => $basedOn,
    ])->assertOk()->assertJsonPath('stale', true)->assertJsonPath('changedBy', 'Eve');
});

it('tells the editor who else is in the article without saving anything', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $this->actingAs($this->author)->get("/admin/content/{$content->id}/edit")->assertOk();
    edSave($content->id, $this->editor, docWith(docText('Eve is typing')));

    $this->actingAs($this->author)
        ->get("/admin/content/{$content->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->missing('otherEditors')
            ->reloadOnly('otherEditors', fn (Assert $reload) => $reload
                ->where('otherEditors.0.name', 'Eve')));
});

it('saves someone\'s refused work as a new draft, and leaves the article alone', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->editor, docWith(docText('Theirs')));
    $theirs = $content->refresh()->working_revision_id;

    $response = $this->actingAs($this->author)->postJson("/admin/content/{$content->id}/save-as-draft", [
        'title' => 'Harbour festival', 'document' => docWith(docText('Mine')), 'fields' => [], 'terms' => [],
    ])->assertCreated();

    $copy = DB::table('contents')->where('id', $response->json('id'))->first();

    expect($copy->type)->toBe('post')
        ->and($copy->status)->toBe('draft')
        ->and(DB::table('content_revisions')->where('id', $copy->working_revision_id)->value('plain_text'))->toBe('Mine')
        ->and($content->refresh()->working_revision_id)->toBe($theirs)
        ->and($response->json('editUrl'))->toEndWith("/admin/content/{$copy->id}/edit");
});

it('writes no new draft for someone who may not write', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $count = DB::table('contents')->count();

    $this->actingAs(User::factory()->create())->postJson("/admin/content/{$content->id}/save-as-draft", [
        'title' => 'Mine', 'document' => docWith(docText('Mine')), 'fields' => [], 'terms' => [],
    ])->assertForbidden();

    expect(DB::table('contents')->count())->toBe($count);
});

it('tells the editor which links an autosave stored differently', function (): void {
    $story = addrPublished($this->editor, 'Harbour festival');
    $content = lifecycleDraft($this->author, 'Weekend guide');

    $this->actingAs($this->author)->postJson("/admin/content/{$content->id}/autosave", [
        'title' => 'Weekend guide',
        'document' => docWith(
            guardLink('/harbour-festival/', 'the festival'),
            guardLink('/harbour-festival/#tickets', 'its tickets'),
            guardLink('/no-such-page/', 'a page no article has'),
            guardLink('#getting-there', 'getting there'),
            guardLink('https://example.org/', 'elsewhere'),
            guardLink('ftp://example.org/files', 'files'),
        ),
        'fields' => [],
        'terms' => [],
        'based_on_revision_id' => $content->working_revision_id,
    ])->assertOk()->assertJsonPath('links', [
        ['from' => '/harbour-festival/', 'to' => "teme:content/{$story->id}"],
        ['from' => '/harbour-festival/#tickets', 'to' => "teme:content/{$story->id}#tickets"],
        ['from' => 'ftp://example.org/files', 'to' => null],
    ]);

    expect((string) DB::table('content_autosaves')->where('content_id', $content->id)->value('document'))
        ->toContain("teme:content/{$story->id}#tickets")
        ->toContain('/no-such-page/')
        ->not->toContain('ftp:');
});

it('tells the editor when an autosave stored this site\'s full address by ID or as its path', function (): void {
    config(['app.url' => 'https://harbour.example']);
    $story = addrPublished($this->editor, 'Harbour festival');
    $content = lifecycleDraft($this->author, 'Weekend guide');

    $this->actingAs($this->author)->postJson("/admin/content/{$content->id}/autosave", [
        'title' => 'Weekend guide',
        'document' => docWith(
            guardLink('https://harbour.example/harbour-festival/', 'the festival'),
            guardLink('https://harbour.example/no-such-page/', 'a page no article has'),
            guardLink('https://example.org/', 'elsewhere'),
        ),
        'fields' => [],
        'terms' => [],
        'based_on_revision_id' => $content->working_revision_id,
    ])->assertOk()->assertJsonPath('links', [
        ['from' => 'https://harbour.example/harbour-festival/', 'to' => "teme:content/{$story->id}"],
        ['from' => 'https://harbour.example/no-such-page/', 'to' => '/no-such-page/'],
    ]);

    expect((string) DB::table('content_autosaves')->where('content_id', $content->id)->value('document'))
        ->toContain('/no-such-page/')
        ->not->toContain('harbour.example');
});

it('autosaves a draft with no title yet, and answers a checkpoint with the address its first title gave it', function (): void {
    $draft = lifecycleDraft($this->author, '');

    edSave($draft->id, $this->author, docWith(docText('Notes before a title.')), title: '');

    $this->actingAs($this->author)->postJson("/admin/content/{$draft->id}/checkpoint")
        ->assertOk()
        ->assertJsonPath('slug', 'untitled');

    edSave($draft->id, $this->author, docWith(docText('Three days of music.')), title: 'Harbour festival');

    $this->actingAs($this->author)->postJson("/admin/content/{$draft->id}/checkpoint")
        ->assertOk()
        ->assertJsonPath('slug', 'harbour-festival');
});
