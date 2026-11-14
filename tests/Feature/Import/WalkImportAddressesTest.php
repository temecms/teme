<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Contents\Visibilities;
use Tests\Fixtures\Import\FakeCompanion;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->companion = importFake();
    $this->source = importSource();
});

/**
 * The walk's exit code and everything it printed, read once.
 *
 * @return array{0: int, 1: string}
 */
function walkRun(string $address = FakeCompanion::SITE): array
{
    $code = Artisan::call('teme:import:walk', ['address' => $address]);

    return [$code, Artisan::output()];
}

it('asks for every old address and counts a page, a redirect to a page, a redirect to a file and one to another site as answering', function (): void {
    $post = addrPublished($this->admin, 'Harbour news');
    addrRename($post, 'harbour', $this->admin);
    $media = mediaUpload($this->admin, mediaJpeg(), 'photo.jpg');
    rdSaved('/attachment/photo/', file: $media->id);
    rdSaved('/elsewhere/', 'https://example.org/');
    $this->companion->items('urls', [
        ['id' => 1, 'kind' => 'post', 'type' => 'post', 'url' => FakeCompanion::SITE.'/harbour/'],
        ['id' => 2, 'kind' => 'post', 'type' => 'post', 'url' => FakeCompanion::SITE.'/harbour-news/'],
        ['id' => 3, 'kind' => 'attachment_page', 'url' => FakeCompanion::SITE.'/attachment/photo/'],
        ['id' => 4, 'kind' => 'post', 'type' => 'post', 'url' => FakeCompanion::SITE.'/elsewhere/'],
    ]);

    [$code, $output] = walkRun();

    expect($code)->toBe(0)
        ->and($output)->toContain('Asking this site for every old address of old.example, as a reader would…')
        ->and($output)->toContain('4 old addresses: 1 answers with a page, 1 with a redirect to a page here, 2 with a redirect to a file or another site; 0 don’t.')
        ->and($output)->toContain('Every old address answers.');
});

it('lists each old address that doesn’t answer, with the status it ended on and where it was sent, and fails', function (): void {
    $withdrawn = addrPublished($this->admin, 'Withdrawn');
    pageTrash($withdrawn, $this->admin);
    rdSaved('/moved/', '/nowhere/');
    $this->companion->items('urls', [
        ['id' => 1, 'kind' => 'post', 'type' => 'post', 'url' => FakeCompanion::SITE.'/never-came/'],
        ['id' => 2, 'kind' => 'post', 'type' => 'post', 'url' => FakeCompanion::SITE.'/moved/'],
        ['id' => 3, 'kind' => 'post', 'type' => 'post', 'url' => FakeCompanion::SITE.'/withdrawn/'],
    ]);

    [$code, $output] = walkRun();

    expect($code)->toBe(1)
        ->and($output)->toContain('404 https://old.example/never-came/')
        ->and($output)->toContain('404 https://old.example/moved/ → http://localhost/nowhere/')
        ->and($output)->toContain('404 https://old.example/withdrawn/')
        ->and($output)->toContain('3 old addresses: 0 answer with a page, 0 with a redirect to a page here, 0 with a redirect to a file or another site; 3 don’t.')
        ->and($output)->toContain('3 old addresses don’t answer: each is listed above.');
});

it('counts an address that differs only after the ? as not answering, and leaves another host’s alone', function (): void {
    addrPublished($this->admin, 'Harbour news');
    $this->companion->items('urls', [
        ['id' => 1, 'kind' => 'post', 'type' => 'post', 'url' => FakeCompanion::SITE.'/?p=4'],
        ['id' => 2, 'kind' => 'post', 'type' => 'post', 'url' => FakeCompanion::SITE.'/harbour-news/'],
        ['id' => 3, 'kind' => 'attachment_file', 'files' => ['full' => 'https://cdn.example/a.jpg']],
    ]);

    [$code, $output] = walkRun();

    expect($code)->toBe(1)
        ->and($output)->toContain('https://old.example/?p=4: Teme doesn’t tell addresses apart by what follows the ?, so readers reach the home page.')
        ->and($output)->toContain('2 old addresses: 1 answers with a page, 0 with a redirect to a page here, 0 with a redirect to a file or another site; 1 doesn’t.')
        ->and($output)->toContain('1 address listed on another host wasn’t asked.')
        ->and($output)->toContain('1 old address doesn’t answer: it is listed above.');
});

it('refuses a site Teme doesn’t know, and one whose application password it no longer keeps, asking the old site nothing', function (): void {
    [$unknownCode, $unknown] = walkRun('https://other.example');
    DB::table('import_sources')->where('id', $this->source->id)->update(['credential' => null, 'forgotten_at' => now()]);
    $before = count($this->companion->requestsTo('urls'));
    [$forgottenCode, $forgotten] = walkRun();

    expect($unknownCode)->toBe(1)
        ->and($unknown)->toContain('Teme doesn’t know https://other.example. Connect it first, in Tools › Import or with php artisan teme:import https://other.example --user=NAME.')
        ->and($forgottenCode)->toBe(1)
        ->and($forgotten)->toContain('Teme no longer keeps the application password for https://old.example. Connect it again first.')
        ->and(count($this->companion->requestsTo('urls')))->toBe($before);
});

it('asks as a reader would, so a private post’s address doesn’t answer even for the operator', function (): void {
    $post = addrPublished($this->admin, 'Staff only');
    walkPrivate($post, $this->admin);
    $this->companion->items('urls', [
        ['id' => 1, 'kind' => 'post', 'type' => 'post', 'url' => FakeCompanion::SITE.'/staff-only/'],
    ]);

    [$code, $output] = walkRun();

    expect($code)->toBe(1)
        ->and($output)->toContain('404 https://old.example/staff-only/');
});

/**
 * Makes the item private through the real Operation, as this person.
 */
function walkPrivate(Content $content, User $actor): void
{
    Visibilities::change($content, 'private', $actor);
}
