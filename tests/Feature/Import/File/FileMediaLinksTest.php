<?php

declare(strict_types=1);

use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Media\Models\Media;
use App\Domain\Redirects\RedirectLookup;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Fixtures\Import\ExportFiles;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\FileImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    Storage::fake('local');
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake();
    importResolve([FakeCompanion::HOST => [FakeCompanion::IP]]);
    config(['teme.import.local_sources' => false]);
    Http::preventStrayRequests();
    Http::fake();
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('says once that media kept on the old site stays there, and counts none of its addresses as errors', function (): void {
    $run = FileImports::run(FileImports::ready('companion-site.xml', $this->admin), ['media' => 'links']);
    $messages = ImportMessageRecord::query()->where('run_id', $run->id)->orderBy('id')->get(['code', 'level', 'message']);
    $media = fn (string $message): bool => str_contains($message, 'attachment') || str_contains($message, 'media library') || str_contains($message, '/wp-content/uploads/');

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($messages->where('level.value', 'error')->filter(fn ($m): bool => $media($m->message))->values()->all())->toBe([])
        ->and($messages->where('code', 'post.featured-missing')->count())->toBe(0)
        ->and($messages->where('code', 'check.old-files')->count())->toBe(0)
        ->and(app(RedirectLookup::class)->oldFile('/wp-content/uploads/2024/05/menu.pdf'))->toBeNull()
        ->and($messages->where('code', 'check.media-kept')->map(fn ($m): array => [$m->level->value, $m->message])->values()->all())->toBe([[
            'warning', '2 media files stay on old.example, as chosen, and 1 post’s featured image: their old addresses and the posts’ images point there, and stop working when that site closes. Import the file again and choose Download to bring them.',
        ]]);
});

it('keeps an own image not in the export’s attachments as a link when media stays on the old site', function (): void {
    $src = 'https://old.example/wp-content/uploads/2024/05/Teeth-Whitening.jpg';
    $bytes = (string) preg_replace_callback('~<item>.*?</item>~s', function (array $item) use ($src): string {
        if (! str_contains($item[0], '<wp:post_id>12</wp:post_id>')) {
            return $item[0];
        }

        return (string) preg_replace(
            '~<content:encoded><!\[CDATA\[.*?\]\]></content:encoded>~s',
            '<content:encoded><![CDATA[<!-- wp:image --><figure class="wp-block-image"><img src="'.$src.'" alt="Teeth" class="wp-image-99"/></figure><!-- /wp:image -->]]></content:encoded>',
            $item[0],
            1,
        );
    }, (string) file_get_contents(ExportFiles::path('companion-site.xml')));

    $run = FileImports::run(FileImports::ready('companion-site.xml', $this->admin, $bytes), ['media' => 'links']);
    $post = ImportMapEntry::query()->where('object_type', 'post')->where('object_id', '12')->sole();
    $document = ContentRevision::query()->findOrFail(
        DB::table('contents')->where('id', $post->teme_id)->value('working_revision_id')
    )->document;
    $codes = ImportMessageRecord::query()->where('map_id', $post->id)->orderBy('id')->pluck('code')->all();

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(Media::query()->count())->toBe(0)
        ->and($codes)->toContain('image.outside')
        ->and($codes)->not->toContain('image.missing')
        ->and(json_encode($document, JSON_UNESCAPED_SLASHES))->toContain($src)
        ->and(json_encode($document))->not->toContain('"type":"image"');
});

it('brings the articles first without a download, and says the pictures follow from the old site (#716)', function (): void {
    $run = FileImports::run(FileImports::ready('companion-site.xml', $this->admin), ['media' => 'later']);
    $messages = ImportMessageRecord::query()->where('run_id', $run->id)->orderBy('id')->get(['code', 'level', 'message']);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(ImportMapEntry::query()->where('object_type', 'attachment')->where('run_id', $run->id)->count())->toBe(0)
        ->and($messages->where('code', 'post.featured-missing')->count())->toBe(0)
        ->and($messages->where('code', 'check.old-files')->count())->toBe(0)
        ->and($messages->where('code', 'check.media-kept')->count())->toBe(0)
        ->and($messages->where('code', 'check.media-later')->map(fn ($m): array => [$m->level->value, $m->message])->values()->all())->toBe([[
            'warning', 'The media files come after the articles, in the background: until each one is copied here, posts show it from old.example. Keep that site up until the pictures are done.',
        ]]);
});
