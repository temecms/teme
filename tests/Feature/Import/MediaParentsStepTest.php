<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\Media\MediaParentsStep;
use App\Domain\Import\Phases\Media\MediaPhase;
use App\Domain\Import\Source\SourcePage;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Media\Models\Media;
use App\Domain\Redirects\Models\Redirect;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ApiRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->importer = User::factory()->create()->assignRole('administrator');
    $this->source = ImportRecords::source();
    $this->run = ImportRecords::run($this->source);
    $this->post = addrPublished($this->importer, 'Harbour news');
});

/**
 * An imported attachment of the source: a new item, and its map row with
 * these facts (WordPress's parent, its page's path, whether it uses
 * another's item).
 *
 * @param  array<string, mixed>  $facts
 */
function mpsAttachment(string $sourceId, string $runId, User $actor, int $id, array $facts): Media
{
    $media = mediaUpload($actor, mediaJpeg(100 + $id, 100), "photo-{$id}.jpg");
    ImportRecords::entry($sourceId, $runId, 'attachment', (string) $id, 'created', [
        'teme_type' => 'media',
        'teme_id' => $media->id,
        'facts' => json_encode(['duplicate' => false, ...$facts], JSON_THROW_ON_ERROR),
    ]);

    return $media;
}

/**
 * WordPress's post $id as the posts phase leaves it: imported as $item.
 */
function mpsPost(string $sourceId, string $runId, int $id, Content $item): void
{
    ImportRecords::entry($sourceId, $runId, 'post', (string) $id, 'created', ['teme_type' => 'content', 'teme_id' => $item->id]);
}

/**
 * A reader the step must never use: it reads nothing from the old site.
 */
function mpsReader(): SourceReader
{
    return new class implements SourceReader
    {
        public function page(string $list, array $query, int $afterId, int $limit): SourcePage
        {
            throw new LogicException('The step reads nothing from the old site.');
        }

        public function whole(string $list, array $query = []): array
        {
            throw new LogicException('The step reads nothing from the old site.');
        }
    };
}

/**
 * The step run from the start to its end, a job at a time, as the
 * operator at the server's shell; the words of the run's messages.
 *
 * @return list<string>
 */
function mpsRun(string $sourceId, string $runId): array
{
    return scElevated(function () use ($sourceId, $runId): array {
        $context = new ImportContext($runId, $sourceId, ImportRunKind::Full, JobActor::operator(), null, 0, []);
        $words = [];
        $cursor = null;

        do {
            $result = app(MediaParentsStep::class)->run($context, mpsReader(), $cursor);
            $words = [...$words, ...array_map(fn ($message): string => $message->message, $result->messages)];
            $cursor = $result->cursor;
        } while ($cursor !== null);

        return $words;
    });
}

it('sets each item’s Uploaded for from its WordPress parent, never overwriting it (I-553)', function (): void {
    $other = Content::factory()->create();
    mpsPost($this->source, $this->run, 7, $this->post);
    $first = mpsAttachment($this->source, $this->run, $this->importer, 12, ['parent' => 7, 'page_path' => null]);
    $held = mpsAttachment($this->source, $this->run, $this->importer, 13, ['parent' => 7, 'page_path' => null]);
    DB::table('media')->where('id', $held->id)->update(['uploaded_for' => $other->id]);
    $lost = mpsAttachment($this->source, $this->run, $this->importer, 14, ['parent' => 99, 'page_path' => null]);
    $theirs = mpsAttachment($this->source, $this->run, $this->importer, 15, ['parent' => 7, 'page_path' => null, 'duplicate' => true]);

    expect(mpsRun($this->source, $this->run))->toBe([])
        ->and($first->fresh()?->uploaded_for)->toBe($this->post->id)
        ->and($held->fresh()?->uploaded_for)->toBe($other->id)
        ->and($lost->fresh()?->uploaded_for)->toBeNull()
        ->and($theirs->fresh()?->uploaded_for)->toBeNull();
});

it('turns each attachment page into a 301 to its post, or to its file when it has none (I-244, I-860)', function (): void {
    mpsPost($this->source, $this->run, 7, $this->post);
    mpsAttachment($this->source, $this->run, $this->importer, 12, ['parent' => 7, 'page_path' => '2024/05/06/harbour/photo-12']);
    $alone = mpsAttachment($this->source, $this->run, $this->importer, 14, ['parent' => 0, 'page_path' => 'photo-14']);

    mpsRun($this->source, $this->run);
    $redirects = Redirect::query()->orderBy('source')->get()
        ->map(fn (Redirect $redirect): array => [$redirect->source, $redirect->origin, $redirect->status, $redirect->target_kind, $redirect->target])
        ->all();

    expect($redirects)->toBe([
        ['2024/05/06/harbour/photo-12', 'import', 301, 'content', $this->post->id],
        ['photo-14', 'import', 301, 'media', $alone->id],
    ])
        ->and(addrLocation(addrGet('/2024/05/06/harbour/photo-12/')))->toBe('/harbour-news/');
});

it('moves its redirect to the file on to the post once a later run brought the post (I-860)', function (): void {
    $media = mpsAttachment($this->source, $this->run, $this->importer, 12, ['parent' => 7, 'page_path' => 'photo-12']);
    mpsRun($this->source, $this->run);
    $redirect = Redirect::query()->sole();

    expect([$redirect->target_kind, $redirect->target])->toBe(['media', $media->id]);

    mpsPost($this->source, $this->run, 7, $this->post);
    mpsRun($this->source, $this->run);

    expect(Redirect::query()->sole()->only(['id', 'target_kind', 'target', 'origin']))->toBe(['id' => $redirect->id, 'target_kind' => 'content', 'target' => $this->post->id, 'origin' => 'import'])
        ->and($media->fresh()?->uploaded_for)->toBe($this->post->id);
});

it('leaves alone a page a query names, a post holds, or another redirect holds', function (string $case): void {
    mpsPost($this->source, $this->run, 7, $this->post);

    if ($case === 'a person’s redirect') {
        rdSaved('/photo-12/', '/news/');
    }

    $page = match ($case) {
        'a query' => null,
        'a post’s own address' => 'harbour-news',
        default => 'photo-12',
    };
    mpsAttachment($this->source, $this->run, $this->importer, 12, ['parent' => 7, 'page_path' => $page]);

    expect(mpsRun($this->source, $this->run))->toBe([])
        ->and(Redirect::query()->where('origin', 'import')->count())->toBe(0)
        ->and(addrGet('/harbour-news/')->status())->toBe(200)
        ->and(Redirect::query()->where('source', 'photo-12')->value('target'))->toBe($case === 'a person’s redirect' ? '/news/' : null);
})->with(['a query', 'a post’s own address', 'a person’s redirect']);

it('reports an attachment page Teme can’t make a redirect, and goes on', function (): void {
    rdSaved('/a/', '/photo-12/');
    mpsAttachment($this->source, $this->run, $this->importer, 12, ['parent' => 0, 'page_path' => 'photo-12']);
    mpsAttachment($this->source, $this->run, $this->importer, 13, ['parent' => 0, 'page_path' => 'photo-13']);

    expect(mpsRun($this->source, $this->run))->toBe(['The attachment page /photo-12/ couldn’t become a redirect: /a already sends readers to /photo-12/, so they’d take two steps. Change that redirect first, or choose another address.'])
        ->and(Redirect::query()->where('origin', 'import')->pluck('source')->all())->toBe(['photo-13']);
});

it('finds nothing to do when it runs again', function (): void {
    mpsPost($this->source, $this->run, 7, $this->post);
    $media = mpsAttachment($this->source, $this->run, $this->importer, 12, ['parent' => 7, 'page_path' => 'photo-12']);
    mpsRun($this->source, $this->run);
    $before = [Redirect::query()->sole()->updated_at?->toIso8601String(), $media->fresh()?->updated_at?->toIso8601String()];
    $this->travel(1)->minutes();

    expect(mpsRun($this->source, $this->run))->toBe([])
        ->and([Redirect::query()->sole()->updated_at?->toIso8601String(), $media->fresh()?->updated_at?->toIso8601String()])->toBe($before);
});

it('reads two hundred a job, carrying on after the last it read', function (): void {
    foreach (range(1, MediaParentsStep::PAGE + 1) as $id) {
        $mediaId = (string) Str::ulid();
        DB::table('media')->insert([
            'id' => $mediaId, 'kind' => 'document', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'original_filename' => "menu-{$id}.pdf",
            'name' => "menu-{$id}", 'original_path' => "menu-{$id}.pdf", 'size_bytes' => 1, 'sha256' => hash('sha256', (string) $id),
            'title' => "Menu {$id}", 'processing' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        ImportRecords::entry($this->source, $this->run, 'attachment', (string) $id, 'created', ['teme_type' => 'media', 'teme_id' => $mediaId, 'facts' => '{"parent": 0}']);
    }

    $cursors = scElevated(function (): array {
        $context = new ImportContext($this->run, $this->source, ImportRunKind::Full, JobActor::operator(), null, 0, []);
        $first = app(MediaParentsStep::class)->run($context, mpsReader(), null);
        $second = app(MediaParentsStep::class)->run($context, mpsReader(), $first->cursor);

        return [$first->cursor, $second->cursor];
    });

    // The map's own order compares IDs as text, so "98" is the two hundredth of 1 to 201, and "99" the last.
    expect($cursors)->toBe(['98', null]);
});

it('sets Uploaded for and the attachment page’s redirect in a whole run, after posts and pages', function (): void {
    Sleep::fake();
    $bytes = dlJpeg(640, 480);
    Http::fake(fn (ApiRequest $request) => rawurldecode((string) parse_url($request->url(), PHP_URL_PATH)) === '/wp-content/uploads/2024/05/Harbour.jpg' ? Http::response($bytes, 200) : null);
    importFake();
    $source = importSource();
    mpsPost($source->id, ImportRecords::run($source->id), 7, $this->post);
    importPhaseAlone(app(MediaPhase::class), app(MediaParentsStep::class));

    importRun($source);
    $media = Media::query()->where('source_url', 'https://old.example/wp-content/uploads/2024/05/Harbour.jpg')->sole();

    expect($media->uploaded_for)->toBe($this->post->id)
        ->and(Redirect::query()->where('origin', 'import')->sole()->only(['source', 'target_kind', 'target']))->toBe(['source' => '2024/05/06/harbour/harbour', 'target_kind' => 'content', 'target' => $this->post->id]);
});
