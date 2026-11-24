<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Jobs\SwapPictures;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Input\StartMediaPassInput;
use App\Domain\Import\Operations\StartMediaPass;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\ExportFiles;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\FileImports;

uses(RefreshDatabase::class);

/*
 * The articles first, then the pictures (#716): the import of an export
 * file brings the posts with their pictures shown from the old site, then a
 * picture pass starts by itself, brings every media file and swaps each
 * post's outside pictures for the library's.
 */

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    Storage::fake('local');
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake();
    Http::fake(function (Request $request): ?PromiseInterface {
        $url = parse_url($request->url());
        $path = (string) ($url['path'] ?? '/');

        if (($url['host'] ?? '') !== FakeCompanion::HOST) {
            return null;
        }

        if (str_starts_with($path, '/wp-content/uploads/')) {
            if ($this->oldFilesGone ?? false) {
                return Http::response('', 404);
            }

            return str_ends_with($path, '.pdf')
                ? Http::response("%PDF-1.4\n% {$path}\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", 200, ['Content-Type' => 'application/pdf'])
                : Http::response(dlJpeg(), 200, ['Content-Type' => 'image/jpeg']);
        }

        return $path === '/wp-json/teme/v1/redirects' ? Http::response(['api' => 1, 'limit' => 500, 'next' => null, 'items' => []]) : null;
    });
    importFake();
    $this->admin = User::factory()->create()->assignRole('administrator');

    // Post 12 shows the harbour's large size, and has the harbour as its featured image.
    $this->src = 'https://old.example/wp-content/uploads/2024/05/Harbour-1024x683.jpg';
    $this->bytes = (string) preg_replace_callback('~<item>.*?</item>~s', function (array $item): string {
        if (! str_contains($item[0], '<wp:post_id>12</wp:post_id>') || str_contains($item[0], 'attachment')) {
            return $item[0];
        }

        $item[0] = str_replace('<wp:meta_value><![CDATA[41]]></wp:meta_value>', '<wp:meta_value><![CDATA[12]]></wp:meta_value>', $item[0]);

        return (string) preg_replace(
            '~<content:encoded><!\[CDATA\[.*?\]\]></content:encoded>~s',
            '<content:encoded><![CDATA[<!-- wp:image {"id":12} --><figure class="wp-block-image"><img src="'.$this->src.'" alt="Boats" width="1024" height="683" class="wp-image-12"/></figure><!-- /wp:image -->]]></content:encoded>',
            $item[0],
            1,
        );
    }, (string) file_get_contents(ExportFiles::path('companion-site.xml')));
});

/**
 * Post 12's working revision.
 */
function ppPost(): ContentRevision
{
    $post = ImportMapEntry::query()->where('object_type', 'post')->where('object_id', '12')->sole();

    return ContentRevision::query()->findOrFail(DB::table('contents')->where('id', $post->teme_id)->value('working_revision_id'));
}

it('brings the pictures after the articles by itself, and swaps each post\'s outside pictures for the library\'s (#716)', function (): void {
    $jobs = [];
    Queue::after(function (JobProcessed $event) use (&$jobs): void {
        $jobs[] = $event->job->resolveName();
    });
    $run = FileImports::run(FileImports::ready('companion-site.xml', $this->admin, $this->bytes), ['media' => 'later'], ['media_lanes' => 2]);
    $pass = ImportRun::query()->where('follows_run_id', $run->id)->sole();
    $harbour = app(ImportMap::class)->entry((string) $run->source_id, SourceRef::of('attachment', 12))?->teme_id;
    $revision = ppPost();
    $card = collect($revision->document['content'])->firstWhere('type', 'image');

    expect($run->fresh()->state)->toBe(ImportRunState::Finished)
        ->and($pass->kind)->toBe(ImportRunKind::MediaPass)
        ->and($pass->state)->toBe(ImportRunState::Finished)
        ->and($pass->options['media_lanes'])->toBe(2)
        ->and(Media::query()->count())->toBe(2)
        ->and($harbour)->not->toBeNull()
        // Swapped as its file came, a page of posts at a time, not only by the second pass after.
        ->and($jobs)->toContain(SwapPictures::class)
        ->and($card['attrs']['mediaId'])->toBe($harbour)
        ->and($card['attrs']['source'] ?? null)->toBeNull()
        ->and($card['attrs']['alt'])->toBe('Boats')
        ->and($revision->fields[BuiltInFields::FEATURED_IMAGE])->toBe(['id' => $harbour, 'alt' => 'Boats moored in a grey harbour', 'decorative' => false])
        ->and(ImportMapEntry::query()->where('object_type', 'post')->where('object_id', '12')->sole()->facts['waiting_media'])->toBe([]);
});

it('tries the files again in a pass started by hand, and leaves a post changed here as it is, saying so (#716)', function (): void {
    $this->oldFilesGone = true;
    $run = FileImports::run(FileImports::ready('companion-site.xml', $this->admin, $this->bytes), ['media' => 'later']);
    $first = ImportRun::query()->where('follows_run_id', $run->id)->sole();

    // The old site answers again; meanwhile a person saved the post here.
    $this->oldFilesGone = false;
    $revision = ppPost();
    docSave(Content::query()->findOrFail($revision->content_id), $this->admin, docWith(...[...$revision->document['content'], docText('Edited here.')]));

    $again = app(StartMediaPass::class)->execute(new StartMediaPassInput((string) $run->id, (string) $this->admin->id));
    $post = ImportMapEntry::query()->where('object_type', 'post')->where('object_id', '12')->sole();
    $card = collect(ppPost()->document['content'])->firstWhere('type', 'image');

    expect($first->state)->toBe(ImportRunState::Finished)
        ->and(ImportMessageRecord::query()->where('run_id', $first->id)->where('code', 'pictures.left')->pluck('message')->all())->toBe([
            '1 post still shows pictures from old.example, because their files couldn’t be copied here. They stop showing when that site closes: bring the pictures again from the import screen while it’s up.',
        ])
        ->and(ImportRun::query()->findOrFail($again['runId'])->state)->toBe(ImportRunState::Finished)
        ->and(Media::query()->count())->toBe(2)
        ->and($card['attrs']['source'])->toBe($this->src)
        ->and($card['attrs']['mediaId'])->toBeNull()
        ->and(ImportMessageRecord::query()->where('map_id', $post->id)->where('run_id', $again['runId'])->pluck('code')->all())->toBe(['second-pass.pictures-changed-here']);
});

it('refuses a pass after an import that downloaded its pictures, or before it finished (#716)', function (string $media, ImportRunState $state, string $message): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $run = ImportRun::query()->create(['source_id' => $file->source_id, 'file_id' => $file->id, 'kind' => ImportRunKind::Full, 'state' => $state, 'options' => ['pause_ms' => 0], 'choices' => ['media' => $media], 'started_at' => now(), 'high_water_at' => now(),
        ...($state === ImportRunState::Running ? ['phase' => 'content', 'phase_token' => strtolower((string) Str::ulid())] : ['ended_at' => now()])]);

    expect(fn () => app(StartMediaPass::class)->execute(new StartMediaPassInput((string) $run->id, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, $message)
        ->and(ImportRun::query()->where('follows_run_id', $run->id)->count())->toBe(0);
})->with([
    'downloaded with the articles' => ['download', ImportRunState::Finished, 'Only an import that brought the articles first has pictures to bring after.'],
    'still running' => ['later', ImportRunState::Running, 'The pictures come once the articles’ import has finished.'],
]);
