<?php

declare(strict_types=1);

use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Media\MediaCounts;
use App\Domain\Users\Operations\DeleteUser;
use App\Domain\Users\Operations\Input\DeleteUserInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('reports no drift when the counts are right', function (): void {
    lifecycleDraft($this->author);

    $this->artisan('teme:counts:rebuild', ['--check' => true])
        ->expectsOutputToContain('No drift')
        ->assertSuccessful();
});

it('finds and fixes drift', function (): void {
    $content = lifecycleDraft($this->author);
    app(TrashContent::class)->execute(new ContentActionInput($content->id, (string) $this->author->id));
    DB::table('content_counts')->update(['count' => 99]);

    $this->artisan('teme:counts:rebuild', ['--check' => true])->assertFailed();
    $this->artisan('teme:counts:rebuild')->assertSuccessful();

    expect(ContentCounts::for('post'))->toBe(lifecycleTruth('post'))
        ->and(ContentCounts::for('post', $this->author->id))->toBe(lifecycleTruth('post', $this->author->id));
});

it('moves an author\'s counts to whoever receives their content when they are deleted', function (): void {
    $this->withElevation();
    $admin = User::factory()->create()->assignRole('administrator');
    lifecycleDraft($this->author);
    lifecycleDraft($this->author, 'Second');

    app(DeleteUser::class)->execute(new DeleteUserInput((string) $this->author->id, (string) $admin->id, (string) $admin->id));

    expect(ContentCounts::for('post', $admin->id))->toBe(['draft' => 2])
        ->and(DB::table('content_counts')->where('author_id', $this->author->id)->exists())->toBeFalse()
        ->and(ContentCounts::for('post'))->toBe(['draft' => 2]);
});

it('finds and fixes drift in the media counts', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    $author = User::factory()->create()->assignRole('author');
    mediaUpload($author, mediaPdf(), 'one.pdf');
    DB::table('media_counts')->update(['count' => 7]);

    $this->artisan('teme:counts:rebuild --check')->assertFailed();
    $this->artisan('teme:counts:rebuild')->assertSuccessful();

    expect(MediaCounts::all())->toBe(['document' => ['live' => 1]]);
});

it('finds and fixes drift in each item\'s comment counts and last comment (I-273)', function (): void {
    $item = cmItem($this->author);
    $approved = cmComment($item);
    cmComment($item, 'pending');
    DB::table('comment_stats')->where('content_id', $item->id)->update(['approved_count' => 7, 'pending_count' => 0, 'thread_count' => 5, 'last_comment_id' => null, 'last_comment_at' => null]);

    $this->artisan('teme:counts:rebuild', ['--check' => true])->assertFailed();
    $this->artisan('teme:counts:rebuild')->assertSuccessful();

    expect(cmStats($item))->toMatchArray(['approved_count' => 1, 'pending_count' => 1, 'thread_count' => 1, 'last_comment_id' => $approved->id]);
});
