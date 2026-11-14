<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Restore\RestoreRequest;
use App\Domain\Backups\Restore\RestoreSite;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Fixtures\Backups\NewServer;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScratchSite;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    [$this->old, $this->new, $this->backups] = [null, null, null];
});

afterEach(function (): void {
    URL::forceRootUrl(null);
    $this->backups?->delete();
    $this->new?->close();
    $this->old?->close();
});

it('moves a site to a new server under another address and another APP_KEY: its secrets read, its links and images follow the address (criteria 12 and 14)', function (): void {
    // The old server, at its own address and key.
    NewServer::useAppKey('base64:'.base64_encode(str_repeat('o', 32)));
    config(['app.url' => 'https://old.example.test', 'filesystems.disks.media.url' => 'https://old.example.test/storage/media']);
    URL::forceRootUrl('https://old.example.test');
    $this->old = ScratchSite::open();
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database']);
    app()->forgetInstance(MaintenanceModeManager::class);
    $this->backups = RunnableBackups::atFolders(1);
    $author = User::factory()->create()->assignRole('administrator');
    DB::table('users')->where('id', $author->id)->update(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')]);
    $image = pageImage($author, 1200, 800);
    $linked = pageArticle($author, 'The linked article');
    $article = pageArticleFromDocument($author, 'The moving article', [
        schedLinkTo($linked->id, 'the other one'),
        pageImageNode($image, Str::upper((string) Str::ulid())),
    ]);
    $recovery = NewServer::recoveryKey($this->backups);
    expect(app(BackupSite::class)->run(BackupTrigger::Command)->succeeded)->toBeTrue();

    // The new server: another address, another key, a database that knows nothing.
    $this->new = NewServer::open('base64:'.base64_encode(str_repeat('n', 32)), 'https://new.example.test');
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database']);
    $result = app(RestoreSite::class)->run(new RestoreRequest(from: NewServer::folder($this->backups), key: $recovery->value()));
    // Through the kernel: the test client trims the address's trailing slash, which is its canonical form.
    $page = app(Kernel::class)->handle(Request::create('https://new.example.test'.Addresses::relativeUrl($article->refresh()), 'GET'));

    expect($result->succeeded)->toBeTrue($result->sentence)
        // Criterion 14: the secrets read under the new key.
        ->and(app(BackupKeys::class)->machineKey())->toBe(TemporaryRepository::KEY)
        ->and(decrypt((string) DB::table('users')->where('id', $author->id)->value('two_factor_secret')))->toBe('JBSWY3DPEHPK3PXP')
        // Criterion 12: every internal link and image at the new address, no search-and-replace.
        ->and($page->getStatusCode())->toBe(200)
        ->and($page->getContent())->toContain('href="'.Addresses::relativeUrl($linked->refresh()))
        ->and($page->getContent())->toContain('src="/storage/')
        ->and($page->getContent())->not->toContain('old.example.test')
        ->and(DB::table('content_revisions')->where('document', 'like', '%old.example.test%')->exists())->toBeFalse()
        // The place it came from waits for a person's answer.
        ->and(app(BackupPlaces::class)->isPaused())->toBeTrue();
});
