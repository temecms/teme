<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\TranslateContentInput;
use App\Domain\Contents\Operations\TranslateContent;
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

/**
 * Every row a translation route may write, to compare before and after a refusal.
 *
 * @return array<string, mixed>
 */
function trRoutesState(): array
{
    return [
        'contents' => DB::table('contents')->orderBy('id')->get(['id', 'language', 'translation_group', 'working_revision_id', 'slug'])->map(fn ($row): array => (array) $row)->all(),
        'revisions' => DB::table('content_revisions')->count(),
        'terms' => DB::table('terms')->count(),
    ];
}

it('translates an item into a new draft and opens its editor', function (): void {
    $response = $this->actingAs($this->site->editor)
        ->post(route('content.translations.store', ['type' => 'posts', 'content' => $this->site->alone]), ['language' => 'fr']);

    $french = Content::query()->where('language', 'fr')->where('title', 'Alone')->sole();

    $response->assertRedirect(route('content.edit', ['content' => $french->id]))
        ->assertInertiaFlash('toast.message', 'Made a French draft from the English (United Kingdom) version.');
});

it('links another item as a version', function (): void {
    $seul = LanguageSite::version($this->site->editor, 'Seul', 'fr', null, [], publish: true);

    $this->actingAs($this->site->editor)
        ->from('/admin/content/'.$this->site->alone.'/edit')
        ->post(route('content.translations.link', ['type' => 'posts', 'content' => $this->site->alone]), ['with' => $seul])
        ->assertRedirect('/admin/content/'.$this->site->alone.'/edit')
        ->assertInertiaFlash('toast.message', 'Linked “Seul” as the French version.');

    expect(DB::table('contents')->where('id', $seul)->value('translation_group'))->toBe(DB::table('contents')->where('id', $this->site->alone)->value('translation_group'))->not->toBeNull();
});

it('unlinks a version of its group, and answers 404 for an item outside it', function (): void {
    $this->actingAs($this->site->editor)
        ->delete(route('content.translations.unlink', ['type' => 'posts', 'content' => $this->site->post, 'other' => $this->site->postEs]))
        ->assertInertiaFlash('toast.message', 'Unlinked “Hola”.');

    expect(DB::table('contents')->where('id', $this->site->postEs)->value('translation_group'))->toBeNull();

    $this->actingAs($this->site->editor)
        ->delete(route('content.translations.unlink', ['type' => 'posts', 'content' => $this->site->post, 'other' => $this->site->alone]))
        ->assertNotFound();
});

it('moves an item to another language', function (): void {
    $this->actingAs($this->site->editor)
        ->put(route('content.language.update', ['type' => 'posts', 'content' => $this->site->alone]), [
            'language' => 'fr',
            'expected_revision_id' => (string) Content::query()->findOrFail($this->site->alone)->working_revision_id,
        ])
        ->assertInertiaFlash('toast.message', 'Moved to French.');

    expect(DB::table('contents')->where('id', $this->site->alone)->value('language'))->toBe('fr');
});

it('refuses a move made on an older revision, in words', function (): void {
    $this->actingAs($this->site->editor)
        ->from('/admin/posts')
        ->put(route('content.language.update', ['type' => 'posts', 'content' => $this->site->alone]), ['language' => 'fr', 'expected_revision_id' => 'old'])
        ->assertSessionHasErrors(['expected_revision_id' => 'Someone changed this post while you were editing. Reload and try again.']);
});

it('marks a version up to date', function (): void {
    $french = app(TranslateContent::class)->execute(new TranslateContentInput($this->site->alone, 'fr', (string) $this->site->editor->id))->content;

    $this->actingAs($this->site->editor)
        ->post(route('content.translations.up-to-date', ['type' => 'posts', 'content' => $french->id]), ['expected_revision_id' => (string) $french->working_revision_id])
        ->assertInertiaFlash('toast.message', 'Marked as up to date with the English (United Kingdom) version.');
});

it('answers 404 when the address names another type', function (): void {
    $this->actingAs($this->site->editor)
        ->post(route('content.translations.store', ['type' => 'pages', 'content' => $this->site->alone]), ['language' => 'fr'])
        ->assertNotFound();
});

it('answers 403 and writes nothing for someone who may not', function (string $route): void {
    $author = User::factory()->create()->assignRole('author');
    $site = $this->site;
    $before = trRoutesState();

    $response = match ($route) {
        'store' => $this->actingAs($author)->post(route('content.translations.store', ['type' => 'pages', 'content' => $site->page]), ['language' => 'es']),
        'link' => $this->actingAs($author)->post(route('content.translations.link', ['type' => 'posts', 'content' => $site->alone]), ['with' => $site->postEs]),
        'unlink' => $this->actingAs($author)->delete(route('content.translations.unlink', ['type' => 'posts', 'content' => $site->post, 'other' => $site->postEs])),
        'language' => $this->actingAs($author)->put(route('content.language.update', ['type' => 'posts', 'content' => $site->alone]), ['language' => 'es', 'expected_revision_id' => (string) Content::query()->findOrFail($site->alone)->working_revision_id]),
        'up-to-date' => $this->actingAs($author)->post(route('content.translations.up-to-date', ['type' => 'posts', 'content' => $site->postFr]), ['expected_revision_id' => (string) Content::query()->findOrFail($site->postFr)->working_revision_id]),
    };

    $response->assertForbidden();
    expect(trRoutesState())->toBe($before);
})->with(['store', 'link', 'unlink', 'language', 'up-to-date']);
