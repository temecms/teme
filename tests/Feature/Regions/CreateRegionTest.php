<?php

declare(strict_types=1);

use App\Domain\Regions\Operations\CreateRegion;
use App\Domain\Regions\Operations\Input\CreateRegionInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('creates the region\'s content row as a draft, titled by its label', function (): void {
    $region = regionCreate($this->editor);

    expect($region->type)->toBe('region')
        ->and($region->slug)->toBe('good--after-content')
        ->and($region->title)->toBe('After the article')
        ->and($region->status)->toBe('draft');
});

it('refuses a region the theme does not declare, and a second row for the same region', function (): void {
    expect(fn () => app(CreateRegion::class)->execute(new CreateRegionInput('good', 'sidebar', (string) $this->editor->id)))
        ->toThrow(ValidationException::class);

    regionCreate($this->editor);
    expect(fn () => regionCreate($this->editor))->toThrow(ValidationException::class);
});

it('writes nothing when the person may not manage regions', function (): void {
    $author = User::factory()->create()->assignRole('author');

    expect(fn () => app(CreateRegion::class)->execute(new CreateRegionInput('good', 'after-content', (string) $author->id)))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('contents')->where('type', 'region')->count())->toBe(0);
});

it('refuses to start a region as a new draft, even through the editor\'s endpoint, and writes nothing', function (): void {
    $region = regionCreate($this->editor);

    expect(fn () => lifecycleDraft($this->editor, 'After the article', 'region'))
        ->toThrow(ValidationException::class, 'made on their own screen');

    $this->actingAs($this->editor)->postJson("/admin/content/{$region->id}/save-as-draft", [
        'title' => 'After the article', 'document' => docWith(docText('Mine')), 'fields' => [], 'terms' => [],
    ])->assertUnprocessable()->assertJsonValidationErrors('type');

    expect(DB::table('contents')->where('type', 'region')->count())->toBe(1);
});

it('refuses a preview link for a region, which has no page of its own, and writes nothing', function (): void {
    $region = regionCreate($this->editor);

    expect(fn () => histPreview($region, $this->editor))->toThrow(ValidationException::class, 'no page of their own');
    expect(DB::table('preview_links')->count())->toBe(0);
});

it('keeps regions off the dashboard\'s panels, as off the lists', function (): void {
    regionCreate($this->editor);
    $post = lifecycleDraft($this->editor, 'A post draft');

    expect(array_column(dashPanel($this->editor, 'my_drafts')['rows'], 'id'))->toBe([$post->id])
        ->and(array_column(dashPanel($this->editor, 'my_recent_edits')['rows'], 'id'))->toBe([$post->id]);
});
