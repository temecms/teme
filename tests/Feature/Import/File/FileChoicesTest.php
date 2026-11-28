<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\AttachUploadsCopy;
use App\Domain\Import\Operations\Input\AttachUploadsCopyInput;
use App\Domain\Import\Planning\ImportAnalysis;
use App\Domain\Import\Planning\ImportChoices;
use App\Domain\Import\Queries\ImportFiles;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
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
    Http::fake(fn (Request $request) => str_ends_with($request->url(), '.pdf')
        ? Http::response("%PDF-1.4\n%%EOF\n", 200, ['Content-Type' => 'application/pdf'])
        : Http::response(dlJpeg(), 200, ['Content-Type' => 'image/jpeg']));
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * The Teme person a file's author became, by their login on WordPress.
 */
function fcPerson(string $sourceId, int $wordpressId): ?string
{
    return DB::table('import_map')->where('source_id', $sourceId)->where('object_type', 'user')->where('object_id', (string) $wordpressId)->value('teme_id');
}

it('makes no new account for an author whose email a person here has', function (): void {
    $ada = User::factory()->create(['email' => 'ada@old.example'])->assignRole('editor');
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $run = FileImports::run($file);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(User::query()->where('email', 'ada@old.example')->count())->toBe(1)
        ->and(fcPerson((string) $file->source_id, 1))->toBe((string) $ada->id)
        ->and($ada->refresh()->getRoleNames()->all())->toBe(['editor'])
        ->and(DB::table('contents')->where('author_id', $ada->id)->count())->toBeGreaterThan(0);
});

it('credits an author’s posts to the person chosen, and makes no account for them', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $run = FileImports::run($file, ['authors' => ['harbour' => 'to:'.$this->admin->id]]);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(User::query()->where('email', 'desk@old.example')->exists())->toBeFalse()
        ->and(fcPerson((string) $file->source_id, 3))->toBe((string) $this->admin->id)
        ->and(DB::table('contents')->where('author_id', $this->admin->id)->count())->toBeGreaterThan(0)
        ->and(DB::table('import_messages')->where('run_id', $run->id)->where('code', 'user.credited')->value('message'))
        ->toBe("Their posts and pages are credited to {$this->admin->name}, as chosen on the plan.");
});

it('adds an author with the role chosen, whose password nobody knows', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $run = FileImports::run($file, ['authors' => ['seo' => 'new:author', 'grace' => 'new:editor']]);
    $seo = User::query()->where('email', 'seo@old.example')->sole();

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($seo->getRoleNames()->all())->toBe(['author'])
        ->and(User::query()->where('email', 'grace@old.example')->sole()->getRoleNames()->all())->toBe(['editor'])
        ->and(DB::table('import_messages')->where('run_id', $run->id)->where('code', 'user.password-reset')->count())->toBeGreaterThanOrEqual(2);
});

it('leaves a custom type with no Teme type behind, and lists it on the plan', function (): void {
    $bytes = (string) preg_replace_callback('~<item>.*?</item>~s', fn (array $item): string => str_contains($item[0], '<wp:post_id>22</wp:post_id>')
        ? str_replace('<wp:post_type><![CDATA[page]]>', '<wp:post_type><![CDATA[recipe]]>', $item[0])
        : $item[0], (string) file_get_contents(ExportFiles::path('companion-site.xml')));
    $file = FileImports::ready('companion-site.xml', $this->admin, $bytes);
    $types = app(ImportChoices::class)->customTypes(app(ImportAnalysis::class)->read((string) $file->source_id));
    $run = FileImports::run($file);

    expect($types)->toBe([['key' => 'recipe', 'label' => 'recipe', 'count' => 1, 'type' => null, 'typeLabel' => null]])
        ->and($run->state)->toBe(ImportRunState::Finished)
        ->and(DB::table('contents')->where('slug', 'contact')->exists())->toBeFalse();
});

it('downloads nothing when media files stay at their old addresses', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $run = FileImports::run($file, ['media' => 'links']);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(DB::table('media')->count())->toBe(0);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/wp-content/uploads/'));
});

it('deletes an uploaded file 30 days after the last import from it ended, and keeps what it brought', function (): void {
    $old = FileImports::ready('companion-site.xml', $this->admin);
    FileImports::run($old);
    $contents = DB::table('contents')->count();
    $this->travel(31)->days();
    $recent = FileImports::ready('companion-site.xml', $this->admin);
    FileImports::run($recent);

    $this->artisan('teme:import:prune-files')->expectsOutput('Deleted 1 export file.')->assertExitCode(0);

    expect(DB::table('import_files')->whereNotNull('deleted_at')->pluck('id')->all())->toBe([$old->id])
        ->and(Storage::disk('local')->exists($old->path()))->toBeFalse()
        ->and(Storage::disk('local')->exists($recent->path()))->toBeTrue()
        ->and(DB::table('contents')->count())->toBe($contents)
        ->and(app(ImportFiles::class)->deletesOn($recent->id))->toStartWith(now()->addDays(30)->utc()->toDateString());
});

it('asks a file’s choices on its plan, refuses ones that don’t fit, and starts the file’s import with the rest', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    Queue::fake();
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $show = '/admin/tools/import/'.$file->source_id;
    $choices = fn (array $changes = []): array => array_replace([
        'menu_locations' => [], 'templates' => [], 'owner' => (string) $this->admin->id, 'pause' => '1000',
        'authors' => ['ada' => 'new:editor', 'grace' => 'new:author', 'harbour' => 'to:'.$this->admin->id, 'seo' => 'new:author', 'reader' => 'new:contributor'],
        'media' => 'download', 'category_base' => 'category', 'tag_base' => 'tag', 'types' => [], 'drafts' => '1', 'comments' => '1',
    ], $changes);

    $this->actingAs($this->admin)->get($show)
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('plan.form', null)
            ->where('plan.fileForm.authors.0.login', 'ada')
            ->where('plan.fileForm.authors.0.value', 'new:author')
            ->where('plan.fileForm.media', 'download')
            ->where('plan.fileForm.pageCount', 6)
            ->where('plan.oldSiteAnswers', true)));

    $this->withSession(scConfirmed())->from($show)
        ->post($show.'/start', $choices(['media' => 'somewhere', 'authors' => ['ada' => 'to:999999']]))
        ->assertRedirect($show)
        ->assertSessionHasErrors(['media' => 'Choose how the media files come across.', 'authors.ada' => 'Choose who this author is here.']);

    expect(ImportRun::query()->count())->toBe(0);

    $this->withSession(scConfirmed())->from($show)->post($show.'/start', $choices())->assertRedirect($show)->assertSessionHasNoErrors();

    $run = ImportRun::query()->sole();

    expect($run->file_id)->toBe($file->id)
        ->and($run->choices['authors'])->toMatchArray(['ada' => 'new:editor', 'harbour' => 'to:'.$this->admin->id])
        ->and($run->choices['media'])->toBe('download');
});

it('takes the articles first, then the pictures, as a file’s media choice (#716)', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $analysis = app(ImportAnalysis::class)->read((string) $file->source_id);
    $input = [
        'owner' => (string) $this->admin->id, 'pause' => '1000',
        'authors' => ['ada' => 'new:editor', 'grace' => 'new:author', 'harbour' => 'to:'.$this->admin->id, 'seo' => 'new:author', 'reader' => 'new:contributor'],
        'media' => 'later', 'category_base' => 'category', 'tag_base' => 'tag', 'types' => [],
    ];

    expect(app(ImportChoices::class)->validatedFile($input, $analysis, $file->id)['choices']['media'])->toBe('later')
        ->and(app(ImportChoices::class)->validatedFile([...$input, 'media' => 'links'], $analysis, $file->id)['choices']['media'])->toBe('links');
});

it('checks the old site again on a second plan reload', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $show = '/admin/tools/import/'.$file->source_id;
    Http::swap(new HttpFactory);
    dlServe(
        [FakeCompanion::HOST => [FakeCompanion::IP]],
        [FakeCompanion::HOST.'*' => Http::response('', 503)],
    );

    $this->actingAs($this->admin)->get($show)
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('plan.oldSiteAnswers', false)));

    Http::swap(new HttpFactory);
    dlServe(
        [FakeCompanion::HOST => [FakeCompanion::IP]],
        [FakeCompanion::HOST.'*' => Http::response('', 200)],
    );

    $this->get($show)
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('plan.oldSiteAnswers', true)));
});

it('deletes the uploaded file from its report, and goes back to it saying so', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $run = FileImports::run($file);
    $show = '/admin/tools/import/'.$file->source_id;

    $this->actingAs($this->admin)->get($show)
        ->assertInertia(fn (Assert $page) => $page->where('step', 'report')->where('run.file.id', $file->id)->where('run.file.deleted', false));

    $this->from($show)->withHeader('X-Inertia', 'true')->post("/admin/tools/import/files/{$file->id}/delete")
        ->assertRedirect($show)
        ->assertInertiaFlash('toast.message', 'Deleted the export file. What was imported stays.');

    expect($file->refresh()->deleted_at)->not->toBeNull()
        ->and(DB::table('contents')->count())->toBeGreaterThan(0)
        ->and(ImportRun::query()->whereKey($run->id)->exists())->toBeTrue();
});

it('keeps the media choices open on the file plan when the old site is silent but an uploads copy is attached', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $show = '/admin/tools/import/'.$file->source_id;
    $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-plan-copy-'.uniqid();
    mkdir($folder.'/2024', recursive: true);
    file_put_contents($folder.'/2024/Harbour One.jpg', dlJpeg(640, 480));
    scElevated(fn (): array => app(AttachUploadsCopy::class)->execute(new AttachUploadsCopyInput($file->source_id, $folder, null)));
    Http::swap(new HttpFactory);
    dlServe(
        [FakeCompanion::HOST => [FakeCompanion::IP]],
        [FakeCompanion::HOST.'*' => Http::response('', 503)],
    );

    $this->actingAs($this->admin)->get($show)
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('plan.oldSiteAnswers', false)
            ->where('plan.copyReady', true)));

    unlink($folder.'/2024/Harbour One.jpg');
    rmdir($folder.'/2024');
    rmdir($folder);
});
