<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\ArchiveAutosave;
use App\Domain\Contents\Operations\Input\ArchiveAutosaveInput;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('writes nothing without operator elevation', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    edSave($content->id, $this->author, docWith(docText('Words nobody saved.')));
    DB::table('content_autosaves')->update(['saved_at' => now()->subDays(config('editor.autosave_ttl_days') + 1)]);
    $autosaveId = DB::table('content_autosaves')->value('id');
    $revisions = DB::table('content_revisions')->where('content_id', $content->id)->count();

    // The default binding, as a web request or a queue worker has it.
    $this->app->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(ArchiveAutosave::class)->execute(new ArchiveAutosaveInput($autosaveId)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($revisions)
        ->and(DB::table('content_autosaves')->where('id', $autosaveId)->exists())->toBeTrue();
});

it('leaves an autosave alone when its owner came back before the lock', function (): void {
    $this->withElevation();
    $content = lifecycleDraft($this->author, 'Harbour festival');
    edSave($content->id, $this->author, docWith(docText('Still typing.')));
    $autosaveId = DB::table('content_autosaves')->value('id');
    $revisions = DB::table('content_revisions')->where('content_id', $content->id)->count();

    expect(app(ArchiveAutosave::class)->execute(new ArchiveAutosaveInput($autosaveId)))->toBeNull()
        ->and(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($revisions)
        ->and(DB::table('content_autosaves')->where('id', $autosaveId)->exists())->toBeTrue();
});
