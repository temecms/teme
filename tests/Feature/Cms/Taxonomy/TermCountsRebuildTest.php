<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('finds and fixes drift in term counts', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('author');
    $news = taxonomyTerm('category', 'News');

    $content = app(CreateDraft::class)->execute(new CreateDraftInput('post', 'Tagged', ['type' => 'doc', 'content' => []], [], (string) $author->id, [$news->id]));
    app(PublishRevision::class)->execute(new PublishRevisionInput($content->id, $content->working_revision_id, (string) $author->id));
    DB::table('terms')->update(['published_count' => 42]);

    $this->artisan('teme:counts:rebuild', ['--check' => true])->assertFailed();
    $this->artisan('teme:counts:rebuild')->assertSuccessful();

    expect($news->fresh()->published_count)->toBe(1);
});

it('finds and fixes drift in the live-item counts', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('author');
    $news = taxonomyTerm('category', 'News');

    app(CreateDraft::class)->execute(new CreateDraftInput('post', 'Tagged', ['type' => 'doc', 'content' => []], [], (string) $author->id, [$news->id]));
    DB::table('terms')->update(['working_count' => 42]);

    $this->artisan('teme:counts:rebuild', ['--check' => true])->assertFailed();
    $this->artisan('teme:counts:rebuild')->assertSuccessful();

    expect($news->fresh()->working_count)->toBe(1);
});
