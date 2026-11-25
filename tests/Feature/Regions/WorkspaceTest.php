<?php

declare(strict_types=1);

use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Regions\Operations\DiscardRegionChanges;
use App\Domain\Regions\Operations\Input\DiscardRegionChangesInput;
use App\Domain\Themes\ManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
 * Content › Regions on one screen: the regions in their groups, each with
 * how full it is and whether it has unpublished changes, the chosen one's
 * document to edit in place, and Discard changes.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/open')]]);
    themeActivate('good');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->discard = fn (string $id, ?string $working, User $actor) => app(DiscardRegionChanges::class)->execute(new DiscardRegionChangesInput($id, $working, (string) $actor->id));
});

it('sends the chosen region with its document, how full it is and whether it has unpublished changes', function (): void {
    $region = regionCreate($this->editor);
    regionSave($region, $this->editor, [docText('One'), docText('Two')]);

    $this->actingAs($this->editor)->get('/admin/regions?region=after-content')->assertInertia(fn (Assert $page) => $page
        ->component('regions/index')
        ->where('groups.0.group', 'Regions')
        ->where('groups.0.regions.0.key', 'after-content')
        ->where('groups.0.regions.0.used', 2)
        ->where('groups.0.regions.0.max', 5)
        ->where('groups.0.regions.0.dirty', true)
        ->where('chosen.key', 'after-content')
        ->where('chosen.contentId', $region->id)
        ->has('chosen.session.document.content', 2)
        ->where('canManageSiteCode', false)
        ->has('manifest.cards'));
});

it('chooses the first region when none is named, and sends an empty region with no document', function (): void {
    $this->actingAs($this->editor)->get('/admin/regions')->assertInertia(fn (Assert $page) => $page
        ->where('chosen.key', 'after-content')
        ->where('chosen.contentId', null)
        ->where('chosen.session', null)
        ->where('groups.0.regions.0.dirty', false));
});

it('lists a theme’s regions under the groups it names, the rest under Regions', function (): void {
    themeActivate('open');

    $this->actingAs($this->editor)->get('/admin/regions?region=narrow')->assertInertia(fn (Assert $page) => $page
        ->where('groups.0.group', 'Ad placements')
        ->where('groups.0.regions.0.key', 'sidebar')
        ->where('groups.1.group', 'Regions')
        ->where('chosen.key', 'narrow'));
});

it('refuses a region group that is not a short name', function (mixed $group): void {
    $path = base_path('tests/Fixtures/themes/good');
    $manifest = json_decode((string) file_get_contents("{$path}/teme-theme.json"), true);
    $manifest['regions']['after-content']['group'] = $group;

    expect(implode("\n", app(ManifestValidator::class)->errors($manifest, $path)))->toContain('gives its group as a name of 1 to 40 characters');
})->with(['empty' => [' '], 'too long' => [str_repeat('a', 41)], 'not text' => [[1]]]);

it('discards the working changes and the person’s autosave, back to the published revision', function (): void {
    $region = regionCreate($this->editor);
    regionSave($region, $this->editor, [docText('Live')]);
    listsPublish($region, $this->editor);
    $published = $region->fresh()->published_revision_id;
    regionSave($region, $this->editor, [docText('Draft only')]);
    edSave($region->id, $this->editor, docWith(docText('Unsaved')));

    ($this->discard)($region->id, $region->fresh()->working_revision_id, $this->editor);

    expect($region->fresh()->working_revision_id)->toBe($published)
        ->and(DB::table('content_autosaves')->where('content_id', $region->id)->count())->toBe(0);
});

it('returns a region never published to the empty one it was made with', function (): void {
    $region = regionCreate($this->editor);
    $first = $region->fresh()->working_revision_id;
    regionSave($region, $this->editor, [docText('Draft only')]);

    ($this->discard)($region->id, $region->fresh()->working_revision_id, $this->editor);

    expect($region->fresh()->working_revision_id)->toBe($first)
        ->and($region->fresh()->published_revision_id)->toBeNull();
});

it('writes nothing when the person may not manage regions', function (): void {
    $region = regionCreate($this->editor);
    regionSave($region, $this->editor, [docText('Draft only')]);
    edSave($region->id, $this->editor, docWith(docText('Unsaved')));
    $working = $region->fresh()->working_revision_id;
    $author = User::factory()->create()->assignRole('author');

    expect(fn () => ($this->discard)($region->id, $working, $author))->toThrow(AuthorizationException::class)
        ->and($region->fresh()->working_revision_id)->toBe($working)
        ->and(DB::table('content_autosaves')->where('content_id', $region->id)->count())->toBe(1);
});

it('refuses a discard based on an older revision, and writes nothing', function (): void {
    $region = regionCreate($this->editor);
    $old = $region->fresh()->working_revision_id;
    regionSave($region, $this->editor, [docText('Newer')]);
    $working = $region->fresh()->working_revision_id;

    expect(fn () => ($this->discard)($region->id, $old, $this->editor))->toThrow(StaleRevisionException::class)
        ->and($region->fresh()->working_revision_id)->toBe($working);
});

it('discards through the screen’s route, showing the region again, and refuses it to people without the permission', function (): void {
    $region = regionCreate($this->editor);
    $first = $region->fresh()->working_revision_id;
    regionSave($region, $this->editor, [docText('Draft only')]);
    $author = User::factory()->create()->assignRole('author');
    $working = $region->fresh()->working_revision_id;

    $this->actingAs($author)->post("/admin/regions/{$region->id}/discard", ['working_revision_id' => $working])->assertForbidden();
    $this->actingAs($this->editor)->post("/admin/regions/{$region->id}/discard", ['working_revision_id' => 'old'])
        ->assertRedirect('/admin/regions?region=after-content')
        ->assertInertiaFlash('toast.type', 'error');

    expect($region->fresh()->working_revision_id)->toBe($working);

    $this->actingAs($this->editor)->post("/admin/regions/{$region->id}/discard", ['working_revision_id' => $working])
        ->assertRedirect('/admin/regions?region=after-content')
        ->assertInertiaFlash('toast.message', 'Changes discarded.');

    expect($region->fresh()->working_revision_id)->toBe($first);
});

it('discards only a region', function (): void {
    $post = lifecycleDraft($this->editor, 'A story');

    $this->actingAs($this->editor)->post("/admin/regions/{$post->id}/discard", ['working_revision_id' => $post->working_revision_id])->assertNotFound();
});
