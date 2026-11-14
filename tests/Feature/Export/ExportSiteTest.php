<?php

declare(strict_types=1);

use App\Domain\Export\ExportedSite;
use App\Domain\Export\ExportFailed;
use App\Domain\Export\ExportKinds;
use App\Domain\Export\ExportSnapshot;
use App\Domain\Export\LeftOut;
use App\Domain\Export\Operations\ExportSite;
use App\Domain\Export\Operations\Input\ExportSiteInput;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Media\MediaDisks;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TypesFile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Export\ExportAs;
use Tests\Fixtures\Export\ProbeKind;
use Tests\Fixtures\Export\SampleSite;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->folder = ExportAs::freshFolder();
    $this->events = fn (): int => DB::table('security_events')->where('type', 'export.made')->count();
});

afterEach(function (): void {
    ExportAs::remove($this->folder);
});

it('writes nothing when authorization fails', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');

    expect(fn () => app(ExportSite::class)->execute(new ExportSiteInput($this->folder, actorId: (string) $admin->id)))
        ->toThrow(AuthorizationException::class, 'Only the operator exports the site, at the server.')
        ->and(fn () => app(ExportSite::class)->execute(new ExportSiteInput($this->folder)))
        ->toThrow(AuthorizationException::class, 'Only the operator exports the site, at the server.')
        ->and(file_exists($this->folder))->toBeFalse()
        ->and(ExportAs::leftovers($this->folder))->toBe([])
        ->and(($this->events)())->toBe(0);
});

it('refuses a folder that exists, or one whose parent doesn’t, before writing anything', function (): void {
    mkdir($this->folder);
    file_put_contents("{$this->folder}/theirs.txt", 'theirs');
    $orphan = $this->folder.'/missing/export';

    expect(fn () => ExportAs::operator($this->folder))
        ->toThrow(ValidationException::class, "{$this->folder} already exists. Name a folder that doesn’t exist yet: an export never writes into one.")
        ->and(fn () => ExportAs::operator($orphan))
        ->toThrow(ValidationException::class, "{$this->folder}/missing doesn’t exist. Make it first, or name a folder inside one that does.")
        ->and(fn () => ExportAs::operator('  '))
        ->toThrow(ValidationException::class, 'Name a folder for the export.')
        ->and(file_get_contents("{$this->folder}/theirs.txt"))->toBe('theirs')
        ->and(ExportAs::leftovers($this->folder))->toBe([])
        ->and(($this->events)())->toBe(0);
});

it('writes every kind in order, the originals and a manifest that holds them together', function (): void {
    $site = SampleSite::make();

    $result = ExportAs::operator($this->folder, pageSize: 2);
    $manifest = ExportAs::manifest($this->folder);
    $kinds = array_map(fn ($kind): string => $kind->name(), (new ExportKinds)->all());

    expect($result)->toBeInstanceOf(ExportedSite::class)
        ->and($result->folder)->toBe($this->folder)
        ->and(array_column($manifest['files'], 'kind'))->toBe($kinds)
        ->and($kinds)->toBe(['people', 'bylines', 'terms', 'media', 'contents', 'comments', 'menus', 'menu_items', 'redirects', 'settings'])
        ->and($manifest['format'])->toBe('teme-export')
        ->and($manifest['format_version'])->toBe(1)
        ->and($manifest['teme_version'])->toBe((string) config('teme.version'))
        ->and($manifest['made_from'])->toBe((string) config('app.url'))
        ->and($manifest['made_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/')
        ->and($manifest['left_out'])->toBe(LeftOut::ALL)
        ->and($manifest['media'])->toMatchArray(['folder' => 'media', 'files' => 2, 'missing' => [], 'differs' => []])
        ->and(is_file("{$this->folder}/media/{$site->image->id}.{$site->image->extension}"))->toBeTrue()
        ->and(file_exists("{$this->folder}/media/{$site->trashedPdf->id}.pdf"))->toBeFalse()
        ->and(ExportAs::leftovers($this->folder))->toBe([]);

    foreach ($manifest['files'] as $file) {
        $bytes = (string) file_get_contents("{$this->folder}/{$file['file']}");

        expect($file['file'])->toBe("{$file['kind']}.ndjson")
            ->and($file['version'])->toBe(in_array($file['kind'], ['contents', 'terms'], true) ? 2 : 1)
            ->and($file['bytes'])->toBe(strlen($bytes))
            ->and($file['sha256'])->toBe(hash('sha256', $bytes))
            ->and($file['lines'])->toBe(substr_count($bytes, "\n"))
            ->and($file['lines'])->toBe($result->lines($file['kind']));
    }

    expect($result->lines('contents'))->toBe(3)
        ->and($result->lines('comments'))->toBe(3)
        ->and($result->lines('people'))->toBe(3)
        ->and($result->lines('menu_items'))->toBe(4)
        ->and($result->lines('redirects'))->toBe(3);
});

it('lets no planted secret, IP address, browser or token out, and carries people’s email addresses (I-2408)', function (): void {
    $site = SampleSite::make();
    $hash = (string) DB::table('users')->where('id', $site->author->id)->value('password');
    $twoFactor = (string) DB::table('users')->where('id', $site->author->id)->value('two_factor_secret');

    ExportAs::operator($this->folder);
    $everything = '';

    foreach (ExportAs::manifest($this->folder)['files'] as $file) {
        $everything .= file_get_contents("{$this->folder}/{$file['file']}");
    }

    $everything .= file_get_contents("{$this->folder}/manifest.json");

    foreach ([...SampleSite::NEVER, SampleSite::MACHINE_KEY, SampleSite::TOKEN_HASH, $hash, $twoFactor] as $never) {
        expect($everything)->not->toContain($never);
    }

    expect($everything)->toContain('"email":"ada@example.org"')
        ->and($everything)->toContain('"author_email":"bola@example.org"')
        ->and($everything)->not->toContain('spam@example.net');
});

it('records one security event, with each kind’s count and nothing about anyone', function (): void {
    SampleSite::make();

    ExportAs::operator($this->folder);
    $event = DB::table('security_events')->where('type', 'export.made')->sole();
    $context = json_decode((string) $event->context, true);

    expect($event->user_id)->toBeNull()
        ->and(array_keys($context))->toEqualCanonicalizing(['people', 'bylines', 'terms', 'media', 'contents', 'comments', 'menus', 'menu_items', 'redirects', 'settings', 'media_files'])
        ->and(collect($context)->except('settings')->all())->toEqual(['people' => 3, 'bylines' => 2, 'terms' => 3, 'media' => 2, 'contents' => 3, 'comments' => 3, 'menus' => 1, 'menu_items' => 4, 'redirects' => 3, 'media_files' => 2])
        ->and($context['settings'])->toBe(count(app(SettingsRegistry::class)->all()));
});

it('says which originals were missing or changed, in the manifest, and finishes the export', function (): void {
    $site = SampleSite::make();
    MediaDisks::forOriginal($site->image->kind)->delete($site->image->original_path);

    $result = ExportAs::operator($this->folder);
    $media = ExportAs::manifest($this->folder)['media'];
    $line = collect(ExportAs::lines($this->folder, 'media'))->firstWhere('id', $site->image->id);

    expect($media['missing'])->toBe([$site->image->id])
        ->and($media['files'])->toBe(1)
        ->and($result->media->missing)->toBe([$site->image->id])
        ->and($line['file'])->toBeNull()
        ->and(is_file("{$this->folder}/manifest.json"))->toBeTrue();
});

it('leaves nothing behind, and records nothing, when a kind fails part way', function (): void {
    SampleSite::make();
    app()->instance(ExportKinds::class, new ExportKinds([...(new ExportKinds)->all(), new ProbeKind('probes', [['n' => 1], ['n' => 2]], failAfter: 1)]));

    try {
        ExportAs::operator($this->folder);
        $this->fail('The export should have failed.');
    } catch (ExportFailed $failed) {
        expect($failed->getMessage())->toBe('A kind failed on purpose.')
            ->and($failed->leftBehind)->toBeNull()
            ->and($failed->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }

    expect(file_exists($this->folder))->toBeFalse()
        ->and(ExportAs::leftovers($this->folder))->toBe([])
        ->and(($this->events)())->toBe(0);
});

it('reads one moment of the database when its transaction is the outermost, and leaves another’s alone', function (): void {
    $probe = DB::connectUsing('export_probe', [...DB::connection()->getConfig(), 'name' => 'export_probe'], force: true);

    try {
        $probe->beginTransaction();
        $held = ExportSnapshot::hold($probe);
        $isolation = $probe->scalar('SHOW transaction_isolation');
        $idle = $probe->scalar('SHOW idle_in_transaction_session_timeout');
        $probe->rollBack();
    } finally {
        DB::purge('export_probe');
    }

    // The test's own transaction is the outer one here, so this one is nested.
    $nested = DB::transaction(fn (): array => [ExportSnapshot::hold(DB::connection()), DB::scalar('SHOW transaction_isolation')]);

    expect($held)->toBeTrue()
        ->and($isolation)->toBe('repeatable read')
        ->and($idle)->toBe('0')
        ->and($nested)->toBe([false, 'read committed']);
});

it('writes the site’s own types, taxonomies and fields as teme-types.json, and names it in the manifest', function (): void {
    $admin = ctAdmin();
    ctCreate($admin);
    txCreate($admin, [], ['film']);
    StoredFieldRows::store(StoredFieldRows::entry('01K9F1E1D0000000000000RD01', 'release_date', ['type' => 'film']), 1);

    ExportAs::operator($this->folder);
    $manifest = ExportAs::manifest($this->folder);
    $bytes = (string) file_get_contents("{$this->folder}/teme-types.json");

    expect($bytes)->toBe(TypesFile::json())
        ->and(TypesFile::export()['types'])->not->toBe([])
        ->and(TypesFile::export()['taxonomies'])->not->toBe([])
        ->and(TypesFile::export()['fields'])->not->toBe([])
        ->and($manifest['types_file'])->toBe(['file' => 'teme-types.json', 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)])
        ->and(array_column($manifest['files'], 'file'))->not->toContain('teme-types.json')
        ->and(substr(sprintf('%o', fileperms("{$this->folder}/teme-types.json")), -4))->toBe('0600')
        ->and(ExportAs::leftovers($this->folder))->toBe([]);
});

it('writes teme-types.json even when the site has no types of its own', function (): void {
    ExportAs::operator($this->folder);

    $file = json_decode((string) file_get_contents("{$this->folder}/teme-types.json"), true, 512, JSON_THROW_ON_ERROR);

    expect($file)->toMatchArray(['format' => 'teme-types', 'version' => 1, 'types' => [], 'fields' => [], 'taxonomies' => [], 'grants' => []])
        ->and(ExportAs::manifest($this->folder)['types_file']['file'])->toBe('teme-types.json');
});

it('gives back the same definitions when its teme-types.json is imported on an empty site', function (): void {
    $admin = ctAdmin();
    ctCreate($admin);
    txCreate($admin, [], ['film']);
    StoredFieldRows::store(StoredFieldRows::entry('01K9F1E1D0000000000000RD01', 'release_date', ['type' => 'film']), 1);
    $before = TypesFile::export();

    ExportAs::operator($this->folder);

    DB::table(StoredFields::TABLE)->delete();
    DB::table(StoredTaxonomies::TABLE)->delete();
    DB::table(StoredTypes::TABLE)->delete();
    app(StoredTypes::class)->remember();
    app(ContentTypeRegistry::class)->refreshStored();
    app(FieldRegistry::class)->refreshStored();
    expect(TypesFile::export()['types'])->toBe([]);

    $this->artisan('teme:types:import', ['file' => "{$this->folder}/teme-types.json"])->assertSuccessful();

    expect(TypesFile::export())->toEqual($before);
});
