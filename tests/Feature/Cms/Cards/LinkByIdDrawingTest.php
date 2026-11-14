<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Feeds\FeedBody;
use App\Domain\Feeds\FeedUrls;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Taxonomy\Operations\DeleteTerm;
use App\Domain\Taxonomy\Operations\Input\DeleteTermInput;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\Operations\UpdateTerm;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->harbour = typesTerm($this->editor, 'category', 'Harbour');
    $this->ada = lkAuthor('Ada Harbour');
    $this->report = mediaUpload($this->editor, mediaPdf(), 'Annual report.pdf');
});

it('draws a link to a category, an author and a file at their addresses now, keeping each place, with no target and no rel', function (): void {
    $html = app(DocumentRenderer::class)->html(docWith(
        lkLink("teme:term/{$this->harbour->id}#latest", 'the harbour'),
        lkLink("teme:user/{$this->ada->id}", 'Ada', ['target' => '_blank']),
        lkLink("teme:media/{$this->report->id}#page=2", 'the report'),
    ));

    expect($html)->toBe(
        '<p><a href="'.Addresses::relativeUrl($this->harbour).'#latest">the harbour</a></p>'
        .'<p><a href="'.Addresses::relativeUrl($this->ada).'">Ada</a></p>'
        .'<p><a href="'.MediaDisks::public()->url($this->report->original_path).'#page=2">the report</a></p>'
    );
});

it('draws an image file at its widest size, its original being private', function (): void {
    $image = lkImage($this->editor);

    expect(app(DocumentRenderer::class)->html(docWith(lkLink("teme:media/{$image->id}", 'the picture, larger'))))
        ->toBe('<p><a href="'.MediaDisks::public()->url("{$image->id}/v1/harbour-w1280.webp").'">the picture, larger</a></p>');
});

it('follows a category to its new address when it is renamed (rule 9)', function (): void {
    app(UpdateTerm::class)->execute(new SaveTermInput('category', 'The harbour', 'the-harbour', null, null, (string) $this->editor->id, $this->harbour->id));

    expect(app(DocumentRenderer::class)->html(docWith(lkLink("teme:term/{$this->harbour->id}", 'the harbour'))))
        ->toBe('<p><a href="'.Addresses::relativeUrl($this->harbour->refresh()).'">the harbour</a></p>')
        ->toContain('/category/the-harbour');
});

it('draws only the words once there is nowhere to go: a category deleted, a file in the trash, an image with no size yet, a person unknown', function (): void {
    $image = lkImage($this->editor);
    DB::table('media')->where('id', $image->id)->update(['variants' => '{}']);
    app(DeleteTerm::class)->execute(new DeleteTermInput($this->harbour->id, (string) $this->editor->id));
    app(TrashMedia::class)->execute(new MediaActionInput($this->report->id, (string) $this->editor->id));

    expect(app(DocumentRenderer::class)->html(docWith(
        lkLink("teme:term/{$this->harbour->id}", 'the harbour'),
        lkLink("teme:media/{$this->report->id}", 'the report'),
        lkLink("teme:media/{$image->id}", 'the picture'),
        lkLink('teme:user/999999', 'nobody'),
    )))->toBe('<p>the harbour</p><p>the report</p><p>the picture</p><p>nobody</p>');
});

it('draws them absolute in a feed', function (): void {
    $urls = app(FeedUrls::class);

    expect(app(FeedBody::class)->html(docWith(
        lkLink("teme:term/{$this->harbour->id}", 'the harbour'),
        lkLink("teme:user/{$this->ada->id}", 'Ada'),
        lkLink("teme:media/{$this->report->id}", 'the report'),
    )))
        ->toContain('<a href="'.$urls->absolute((string) Addresses::relativeUrl($this->harbour)).'">the harbour</a>')
        ->toContain('<a href="'.$urls->absolute((string) Addresses::relativeUrl($this->ada)).'">Ada</a>')
        ->toContain('<a href="'.$urls->absolute(MediaDisks::public()->url($this->report->original_path)).'">the report</a>')
        ->toContain('href="http');
});

it('records each target on the page it is drawn on, so a change to it redraws the page (I-263)', function (): void {
    app(RenderRecord::class)->start(0);

    app(DocumentRenderer::class)->html(docWith(
        lkLink("teme:term/{$this->harbour->id}", 'the harbour'),
        lkLink("teme:user/{$this->ada->id}", 'Ada'),
        lkLink("teme:media/{$this->report->id}", 'the report'),
    ));

    expect(app(RenderRecord::class)->tags())->toContain("term:{$this->harbour->id}", "person:{$this->ada->id}", "media:{$this->report->id}");
});

it('looks each category and person up once per page, and each file once', function (): void {
    $paths = 0;
    $media = 0;

    DB::listen(function (QueryExecuted $query) use (&$paths, &$media): void {
        $paths += str_contains($query->sql, 'from "paths"') ? 1 : 0;
        $media += str_contains($query->sql, 'from "media"') ? 1 : 0;
    });

    app(DocumentRenderer::class)->html(docWith(
        lkLink("teme:term/{$this->harbour->id}", 'one'),
        lkLink("teme:term/{$this->harbour->id}#latest", 'two'),
        lkLink("teme:user/{$this->ada->id}", 'Ada'),
        lkLink("teme:media/{$this->report->id}", 'the report'),
        lkLink("teme:media/{$this->report->id}#page=2", 'again'),
    ));

    expect($paths)->toBe(2)->and($media)->toBe(1);
});
