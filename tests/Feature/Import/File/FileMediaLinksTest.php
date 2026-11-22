<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Redirects\RedirectLookup;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
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
