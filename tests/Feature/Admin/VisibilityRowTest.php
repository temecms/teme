<?php

declare(strict_types=1);

use App\Domain\Contents\Visibility;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->contributor = User::factory()->create(['name' => 'Chidi Contributor'])->assignRole('contributor');
    $this->item = cmItem($this->author, 'Harbour news');
});

it('gives the editor the item\'s visibility, and its password only to whoever may publish it (I-1700)', function (): void {
    Visibilities::change($this->item, 'protected', $this->author, 'Harbour 2024');

    $this->actingAs($this->author)->get(route('content.edit', ['content' => $this->item->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.visibility', 'protected')
            ->where('session.readerPassword', 'Harbour 2024')
            ->where('session.passwordUnreadable', false));

    $draft = Visibilities::change(lifecycleDraft($this->contributor, 'Notes'), 'protected', $this->editor, 'Harbour 2024');

    $this->actingAs($this->contributor)->get(route('content.edit', ['content' => $draft->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.visibility', 'protected')
            ->where('session.readerPassword', null)
            ->where('session.can.publish', false));
});

it('says when an item\'s password can\'t be read on this server (I-1700)', function (): void {
    Visibilities::change($this->item, 'protected', $this->author, 'Harbour 2024');
    DB::table('contents')->where('id', $this->item->id)->update(['reader_password' => 'not-a-sealed-value']);

    $this->actingAs($this->author)->get(route('content.edit', ['content' => $this->item->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.readerPassword', null)
            ->where('session.passwordUnreadable', true));
});

it('changes the visibility from the editor, answering what the row shows next (I-1700)', function (): void {
    $this->actingAs($this->author)
        ->postJson(route('content.visibility', ['content' => $this->item->id]), ['visibility' => 'private'])
        ->assertOk()
        ->assertExactJson(['visibility' => 'private', 'readerPassword' => null, 'passwordUnreadable' => false, 'url' => null]);

    $this->actingAs($this->author)
        ->postJson(route('content.visibility', ['content' => $this->item->id]), ['visibility' => 'protected', 'password' => 'Harbour 2024'])
        ->assertOk()
        ->assertJsonPath('visibility', 'protected')
        ->assertJsonPath('readerPassword', 'Harbour 2024')
        // Not url(), which trims the trailing slash (docs/architecture/addresses.md).
        ->assertJsonPath('url', rtrim(url('/'), '/').'/harbour-news/');
});

it('refuses from the editor with the reason the row shows (I-1700)', function (): void {
    $this->actingAs($this->author)
        ->postJson(route('content.visibility', ['content' => $this->item->id]), ['visibility' => 'protected', 'password' => '  '])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password' => 'Enter a password of up to 255 characters.']);

    $this->actingAs($this->contributor)
        ->postJson(route('content.visibility', ['content' => $this->item->id]), ['visibility' => 'private'])
        ->assertForbidden();

    expect(DB::table('contents')->where('id', $this->item->id)->value('visibility'))->toBe('public');
});

it('lists a private item under Private, never under Published, and says what each row is after its title (I-1700)', function (): void {
    Visibilities::change($this->item, 'private', $this->author);
    $protected = Visibilities::change(cmItem($this->author, 'Members'), 'protected', $this->author, 'Harbour 2024');

    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts', 'tab' => 'private']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows): bool => collect($rows)->pluck('title')->all() === ['Harbour news'])
            ->where('rows.0.visibility', Visibility::Private->value)
            ->where('tabs', fn ($tabs): bool => collect($tabs)->firstWhere('key', 'private') === ['key' => 'private', 'label' => 'Private', 'count' => 1]
                && collect($tabs)->firstWhere('key', 'published')['count'] === 1
                && collect($tabs)->firstWhere('key', 'all')['count'] === 2));

    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts', 'tab' => 'published']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows): bool => collect($rows)->pluck('title')->all() === ['Members'])
            ->where('rows.0.visibility', $protected->visibility->value));
});

it('draws the Visibility row, the chip\'s Private and the list\'s words, with the signposts gone (I-1700)', function (): void {
    expect(uiComponent('components/editor/rail/publish-panel.tsx'))
        ->toContain('id="editor-visibility"')
        ->toContain('id="editor-password"')
        ->toContain('Only people who can edit it can read it, here in the admin. On the site its address answers “not found”.')
        ->toContain('Readers need the password to read it. Lists on the site show its title, without its excerpt or image.')
        ->toContain('Protect with this password')
        ->toContain('Change password')
        ->not->toContain('Private items never take comments.')
        ->not->toContain('Later (I-1700)')
        ->and(uiComponent('components/editor/status-chip.tsx'))
        ->toContain("'Private'")
        ->not->toContain('Later (I-1700)')
        ->and(uiComponent('pages/content/index.tsx'))
        ->toContain("__('Password protected')")
        ->not->toContain('Later (I-342)')
        ->and(uiComponent('pages/content/edit.tsx'))
        ->toContain('ContentEditorController.visibility(session.id).url');
});
