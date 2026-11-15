<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\UnlinkTranslationInput;
use App\Domain\Contents\Operations\UnlinkTranslation;
use App\Domain\Contents\Queries\EditorSession;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

it('gives each version the editor may open its current revision, for Mark up to date to be based on', function (): void {
    $versions = collect(EditorSession::for($this->site->post, (string) $this->site->editor->id)->translations)->keyBy('language');

    expect($versions['fr']['workingRevisionId'])->toBe((string) DB::table('contents')->where('id', $this->site->postFr)->value('working_revision_id'))
        ->and($versions['es']['workingRevisionId'])->toBe((string) DB::table('contents')->where('id', $this->site->postEs)->value('working_revision_id'));
});

it('gives no revision for a version the person may not open', function (): void {
    $author = User::factory()->create()->assignRole('author');
    app(UnlinkTranslation::class)->execute(new UnlinkTranslationInput($this->site->postEs, (string) $this->site->editor->id));
    $mine = LanguageSite::version($author, 'Mío', 'es', $this->site->postGroup, [], publish: false);

    $versions = collect(EditorSession::for($mine, (string) $author->id)->translations)->keyBy('language');

    expect($versions['fr']['workingRevisionId'])->toBeNull()
        ->and($versions['es']['workingRevisionId'])->toBe((string) DB::table('contents')->where('id', $mine)->value('working_revision_id'));
});

it('offers, for Link a different post…, items of the type in that language outside the group, by title', function (): void {
    $seul = LanguageSite::version($this->site->editor, 'Seul', 'fr', null, [], publish: true);
    LanguageSite::version($this->site->editor, 'Autre', 'fr', null, [], publish: false);
    LanguageSite::version($this->site->editor, 'Seul aussi', 'fr', null, [], publish: false, type: 'page');

    $this->actingAs($this->site->editor)
        ->getJson("/admin/content/posts/{$this->site->alone}/translations/candidates?language=fr&q=seu")
        ->assertOk()
        ->assertExactJson([['id' => $seul, 'title' => 'Seul', 'status' => 'published']]);

    $found = $this->actingAs($this->site->editor)
        ->getJson("/admin/content/posts/{$this->site->post}/translations/candidates?language=fr")
        ->assertOk()
        ->json();

    expect(array_column($found, 'title'))->toEqualCanonicalizing(['Seul', 'Autre']);
});

it('offers nothing for a language the site doesn\'t have', function (): void {
    $this->actingAs($this->site->editor)
        ->getJson("/admin/content/posts/{$this->site->alone}/translations/candidates?language=it")
        ->assertOk()
        ->assertExactJson([]);
});

it('refuses the search to someone who may not change the item, and to an address naming another type', function (): void {
    $author = User::factory()->create()->assignRole('author');

    $this->actingAs($author)
        ->getJson("/admin/content/posts/{$this->site->alone}/translations/candidates?language=fr")
        ->assertForbidden();

    $this->actingAs($this->site->editor)
        ->getJson("/admin/content/pages/{$this->site->alone}/translations/candidates?language=fr")
        ->assertNotFound();
});
