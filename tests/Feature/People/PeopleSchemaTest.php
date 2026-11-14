<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->person = function (array $columns = []): string {
        $id = (string) Str::ulid()->toBase32();
        $id = strtolower($id);
        DB::table('people')->insert([...['id' => $id, 'created_at' => now(), 'updated_at' => now()], ...$columns]);

        return $id;
    };
    // In a savepoint, so the test's own transaction carries on after a refusal.
    $this->code = function (Closure $write): ?string {
        try {
            DB::transaction(fn () => $write());
        } catch (QueryException $exception) {
            return (string) $exception->getCode();
        }

        return null;
    };
});

it('keeps a person with no account only with a name and a slug', function (): void {
    $refused = ($this->code)(fn () => ($this->person)(['slug' => 'reuters']));

    expect($refused)->toBe('23514');

    try {
        DB::transaction(fn () => ($this->person)(['name' => 'Reuters']));
        $message = '';
    } catch (QueryException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toContain('people_named');

    ($this->person)(['name' => 'Reuters', 'slug' => 'reuters']);
    ($this->person)(['user_id' => $this->editor->id]);

    expect(DB::table('people')->count())->toBe(2);
});

it('gives an account at most one person', function (): void {
    ($this->person)(['user_id' => $this->editor->id]);

    expect(($this->code)(fn () => ($this->person)(['user_id' => $this->editor->id])))->toBe('23505');
});

it('keeps one slug for one person', function (): void {
    ($this->person)(['name' => 'Ada', 'slug' => 'ada']);

    expect(($this->code)(fn () => ($this->person)(['name' => 'Ada Two', 'slug' => 'ada'])))->toBe('23505');
});

it('refuses a second byline for the same person on an item, and two bylines at one position', function (): void {
    $item = lifecycleDraft($this->editor, 'Article');
    $ada = ($this->person)(['name' => 'Ada', 'slug' => 'ada']);
    $grace = ($this->person)(['name' => 'Grace', 'slug' => 'grace']);
    DB::table('content_bylines')->insert(['content_id' => $item->id, 'person_id' => $ada, 'position' => 0]);

    expect(($this->code)(fn () => DB::table('content_bylines')->insert(['content_id' => $item->id, 'person_id' => $ada, 'position' => 1])))->toBe('23505')
        ->and(($this->code)(fn () => DB::table('content_bylines')->insert(['content_id' => $item->id, 'person_id' => $grace, 'position' => 0])))->toBe('23505')
        ->and(($this->code)(fn () => DB::table('content_bylines')->insert(['content_id' => $item->id, 'person_id' => $grace, 'position' => 20])))->toBe('23514');
});

it('refuses to delete a person an item credits', function (): void {
    $item = lifecycleDraft($this->editor, 'Article');
    $ada = ($this->person)(['name' => 'Ada', 'slug' => 'ada']);
    DB::table('content_bylines')->insert(['content_id' => $item->id, 'person_id' => $ada, 'position' => 0]);

    expect(($this->code)(fn () => DB::table('people')->where('id', $ada)->delete()))->toBe('23001');
});

it('removes an item\'s bylines with the item', function (): void {
    $item = lifecycleDraft($this->editor, 'Article');
    $ada = ($this->person)(['name' => 'Ada', 'slug' => 'ada']);
    DB::table('content_bylines')->insert(['content_id' => $item->id, 'person_id' => $ada, 'position' => 0]);

    // contents and content_revisions point at each other: clear the pointers first, as DeleteContentPermanently does.
    DB::table('contents')->where('id', $item->id)->update(['published_revision_id' => null, 'working_revision_id' => null]);
    DB::table('content_revisions')->where('content_id', $item->id)->delete();
    DB::table('contents')->where('id', $item->id)->delete();

    expect(DB::table('content_bylines')->count())->toBe(0)
        ->and(DB::table('people')->where('id', $ada)->exists())->toBeTrue();
});

it('removes an account\'s person with the account, unless something credits them, and then refuses', function (): void {
    $gone = User::factory()->create();
    ($this->person)(['user_id' => $gone->id]);
    DB::table('users')->where('id', $gone->id)->delete();

    expect(DB::table('people')->count())->toBe(0);

    $credited = User::factory()->create();
    $person = ($this->person)(['user_id' => $credited->id]);
    $item = lifecycleDraft($this->editor, 'Article');
    DB::table('content_bylines')->insert(['content_id' => $item->id, 'person_id' => $person, 'position' => 0]);

    expect(($this->code)(fn () => DB::table('users')->where('id', $credited->id)->delete()))->toBe('23001')
        ->and(DB::table('people')->where('id', $person)->value('user_id'))->toBe($credited->id);
});

it('lets the release before delete an account with an uncredited person', function (): void {
    $account = User::factory()->create();
    ($this->person)(['user_id' => $account->id]);

    DB::statement('DELETE FROM users WHERE id = ?', [$account->id]);

    expect(DB::table('users')->where('id', $account->id)->exists())->toBeFalse()
        ->and(DB::table('people')->count())->toBe(0);
});
