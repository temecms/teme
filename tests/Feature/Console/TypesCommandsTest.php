<?php

declare(strict_types=1);

use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TypesFile;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->folder = sys_get_temp_dir().'/teme-types-'.getmypid().'-'.uniqid();
    mkdir($this->folder);
    $this->write = function (array $file): string {
        $path = $this->folder.'/teme-types.json';
        file_put_contents($path, json_encode($file, JSON_THROW_ON_ERROR));

        return $path;
    };
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->folder);
});

it('prints the site’s types as a teme-types.json file', function (): void {
    ctCreate($this->admin);

    Artisan::call('teme:types:export');
    $printed = Artisan::output();

    expect(json_decode($printed, true))->toEqual(TypesFile::export())
        ->and($printed)->toContain('"pattern": "/films/{slug}/"');
});

it('writes the file where it is told, and says how many types it holds', function (): void {
    ctCreate($this->admin);
    $path = $this->folder.'/export.json';

    $this->artisan('teme:types:export', ['--output' => $path])
        ->expectsOutput("Wrote 1 type to {$path}.")
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($path), true))->toEqual(TypesFile::export());
});

it('refuses to write into a folder that doesn’t exist', function (): void {
    $this->artisan('teme:types:export', ['--output' => $this->folder.'/missing/export.json'])
        ->expectsOutput("{$this->folder}/missing doesn’t exist.")
        ->assertFailed();
});

it('brings a file’s types in and says what it did', function (): void {
    ctCreate($this->admin, ['key' => 'note', 'singular' => 'Note', 'plural' => 'Notes', 'segment' => 'notes', 'public' => false, 'archive' => false, 'searchable' => false, 'comments' => null]);
    $path = ($this->write)(ctFile([ctDefinition(['pattern' => '/films/{slug}/'])->toArray()]));

    $this->artisan('teme:types:import', ['file' => $path])
        ->expectsOutput('Added film (Films).')
        ->expectsOutput('Left in place, not in the file: note. An import never removes a type.')
        ->assertSuccessful();

    expect(app(ContentTypeRegistry::class)->has('film'))->toBeTrue();

    $this->artisan('teme:types:import', ['file' => $path])
        ->expectsOutput('Unchanged: film.')
        ->assertSuccessful();
});

it('says when it kept the site’s own address pattern', function (): void {
    ctCreate($this->admin);
    $path = ($this->write)(ctFile([ctDefinition(['plural' => 'Movies', 'pattern' => '/watch/{slug}/'])->toArray()]));

    $this->artisan('teme:types:import', ['file' => $path])
        ->expectsOutput('Updated film (Movies).')
        ->expectsOutput('Kept this site’s address pattern for film: change it in Settings › Addresses.')
        ->assertSuccessful();
});

it('says when it kept the site’s own permission grants', function (): void {
    ctCreate($this->admin, ['permissions' => 'own']);
    $path = ($this->write)(ctFile([ctDefinition(['permissions' => 'own'])->toArray()], ['grants' => [['type' => 'film', 'role' => 'author', 'abilities' => ['create']]]]));

    $this->artisan('teme:types:import', ['file' => $path])
        ->expectsOutput('Unchanged: film.')
        ->expectsOutput('Kept this site’s permission grants for film: the file’s differ.')
        ->assertSuccessful();
});

it('says what is wrong with a file it refuses, and changes nothing', function (): void {
    $path = ($this->write)(ctFile([['key' => 'clip', 'singular' => 'Clip', 'plural' => 'Clips', 'segment' => 'media']]));

    $this->artisan('teme:types:import', ['file' => $path])
        ->expectsOutput('/admin/media is one of the admin’s own screens.')
        ->assertFailed();

    expect(DB::table('content_type_definitions')->count())->toBe(0);
});

it('says when there is no file, or it isn’t JSON', function (): void {
    $this->artisan('teme:types:import', ['file' => $this->folder.'/none.json'])
        ->expectsOutput("There is no file at {$this->folder}/none.json.")
        ->assertFailed();

    file_put_contents($this->folder.'/bad.json', '{');

    $this->artisan('teme:types:import', ['file' => $this->folder.'/bad.json'])
        ->expectsOutputToContain("{$this->folder}/bad.json isn’t JSON:")
        ->assertFailed();
});

it('brings types into a site whose types are locked, as the operator', function (): void {
    config(['teme.types_locked' => true]);
    $path = ($this->write)(ctFile([ctDefinition()->toArray()]));

    $this->artisan('teme:types:import', ['file' => $path])->assertSuccessful();

    expect(app(ContentTypeRegistry::class)->has('film'))->toBeTrue();
});
