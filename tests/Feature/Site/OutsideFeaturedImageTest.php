<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\ImageMarkup;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\ListingViews;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/*
 * A featured image may be an outside picture too: the old site's address,
 * which only an import writes while it copies the file (#716). The site
 * draws it from there; a person may keep it or choose another.
 */

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
    $this->outside = ['source' => 'https://old.example.org/wp-content/uploads/harbour.jpg', 'width' => 1600, 'height' => 900, 'alt' => 'Boats at dawn', 'decorative' => false];
});

/**
 * A published post whose featured image is the outside picture, as an import leaves it.
 *
 * @param  array<string, mixed>  $featured
 */
function ofPost(User $author, array $featured): Content
{
    $post = pageArticle($author, 'Harbour');
    $fields = [...(array) $post->fields, BuiltInFields::FEATURED_IMAGE => $featured];
    ContentRevision::query()->where('content_id', $post->id)->update(['fields' => json_encode($fields)]);
    Content::query()->whereKey($post->id)->update(['fields' => json_encode($fields)]);

    return $post->refresh();
}

it('leads an article with the outside picture, drawn from the old site\'s address (#716)', function (): void {
    $view = app(ArticleViews::class)->published(ofPost($this->author, $this->outside));

    expect($view->leadIsFeatured)->toBeTrue()
        ->and($view->lead?->alt)->toBe('Boats at dawn')
        ->and(app(ImageMarkup::class)->render((string) $view->lead?->id, 'content', true, $view->lead?->alt))
        ->toBe('<img src="https://old.example.org/wp-content/uploads/harbour.jpg" alt="Boats at dawn" width="1600" height="900" fetchpriority="high" decoding="async" referrerpolicy="no-referrer">');
});

it('shows the outside picture on the post\'s card in a listing (#716)', function (): void {
    $post = ofPost($this->author, $this->outside);

    $item = app(ListingViews::class)->itemsById([(string) $post->id])[(string) $post->id] ?? null;

    expect(app(ImageMarkup::class)->render((string) $item?->image?->id, 'content'))
        ->toContain('src="https://old.example.org/wp-content/uploads/harbour.jpg"');
});

it('keeps the outside featured image when a person saves, and refuses one they add, saving nothing (#716)', function (): void {
    $post = ofPost($this->author, $this->outside);
    $document = (array) ContentRevision::query()->findOrFail($post->working_revision_id)->document;

    docSave($post, $this->author, $document, [...(array) $post->fields, BuiltInFields::FEATURED_IMAGE => [...$this->outside, 'alt' => 'Boats']]);
    $revisions = ContentRevision::query()->count();

    expect(fn () => docSave($post, $this->author, $document, [...(array) $post->fields, BuiltInFields::FEATURED_IMAGE => [...$this->outside, 'source' => 'https://elsewhere.example/cat.jpg']]))
        ->toThrow(ValidationException::class, 'An image from the old site stays only as the import left it: choose another from the library.')
        ->and(ContentRevision::query()->count())->toBe($revisions);
});
