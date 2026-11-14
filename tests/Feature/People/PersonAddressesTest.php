<?php

declare(strict_types=1);

use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Addresses\Operations\Input\RebuildPathsInput;
use App\Domain\Addresses\Operations\RebuildPaths;
use App\Domain\Addresses\PathProjection;
use App\Domain\Addresses\Paths;
use App\Domain\People\Credit;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('editor');
    $this->grace = User::factory()->create(['name' => 'Grace Hopper'])->assignRole('editor');
    app(PathProjection::class)->syncUser($this->ada);
    app(PathProjection::class)->syncUser($this->grace);
    $this->reuters = Credited::guest('Reuters', 'reuters', 'News agency');
});

it('makes the {author} address from the first credit', function (): void {
    addrChange($this->admin, ['patterns' => ['post' => '/{author}/{slug}/']]);
    $article = addrPublished($this->ada, 'Harbour news');

    expect(addrCurrent('content', $article->id))->toBe($this->ada->slug.'/harbour-news');

    Credited::set($article->id, [Credit::person($this->reuters), Credit::account((int) $this->ada->id)]);
    app(PathProjection::class)->syncContent($article->refresh());

    expect(addrCurrent('content', $article->id))->toBe('reuters/harbour-news');

    Credited::set($article->id, [Credit::account((int) $this->grace->id)]);
    app(PathProjection::class)->syncContent($article->refresh());

    expect(addrCurrent('content', $article->id))->toBe($this->grace->slug.'/harbour-news');
});

it('rebuilds people\'s pages', function (): void {
    DB::table('paths')->where('target_type', 'person')->delete();
    DB::table('paths')->where('target_type', 'user')->delete();

    app(RebuildPaths::class)->execute(new RebuildPathsInput('person', [$this->reuters], (string) $this->admin->id));
    app(RebuildPaths::class)->execute(new RebuildPathsInput('user', [(string) $this->ada->id], (string) $this->admin->id));

    expect(Paths::current('person', $this->reuters))->toBe('author/reuters')
        ->and(Paths::current('user', (string) $this->ada->id))->toBe('author/'.$this->ada->slug);

    DB::table('people')->where('id', $this->reuters)->update(['slug' => 'reuters-news']);
    (new RecomputeAddresses(JobActor::person($this->admin->id)))->handle();

    expect(Paths::current('person', $this->reuters))->toBe('author/reuters-news')
        ->and(DB::table('paths')->where('path', 'author/reuters')->value('is_current'))->toBeFalse()
        ->and(Paths::current('user', (string) $this->grace->id))->toBe('author/'.$this->grace->slug);
});

it('rebuilds a guest\'s page by the guest, never an account with a like ID', function (): void {
    // A ULID whose leading digits are an account's ID, as UnknownAddressKindTest builds one.
    $like = substr($this->ada->id.'kv7m3q9x2c5r8t1w4y6z0b3d5f', 0, 26);
    DB::table('people')->insert(['id' => $like, 'name' => 'Like Ada', 'slug' => 'like-ada', 'created_at' => now(), 'updated_at' => now()]);
    $adaBefore = DB::table('paths')->where('target_type', 'user')->where('target_id', (string) $this->ada->id)->get()->all();

    app(RebuildPaths::class)->execute(new RebuildPathsInput('person', [$like], (string) $this->admin->id));

    expect(Paths::current('person', $like))->toBe('author/like-ada')
        ->and(DB::table('paths')->where('target_type', 'user')->where('target_id', (string) $this->ada->id)->get()->all())->toEqual($adaBefore);
});

it('recomputes guests\' pages last', function (): void {
    Queue::fake();
    Cache::forget(RecomputeAddresses::PROGRESS);

    $kinds = [];
    $job = new RecomputeAddresses(JobActor::person($this->admin->id));

    while ($job !== null) {
        $kinds[] = $job->kind;
        $job->handle();
        $job = Queue::pushed(RecomputeAddresses::class)->last();
        Queue::fake();
    }

    expect($kinds)->toBe(['content', 'term', 'user', 'person'])
        ->and(Cache::get(RecomputeAddresses::PROGRESS)['total'])->toBe(DB::table('contents')->count() + DB::table('terms')->count() + DB::table('users')->count() + 1);
});

it('names a guest in the words of an address clash, and never reads an account for a person row', function (): void {
    expect(Paths::describe('person', $this->reuters))->toBe('the author "Reuters"');

    DB::enableQueryLog();
    $words = Paths::describe('gadget', 'x');

    expect($words)->toBe('something this version of Teme doesn’t know (gadget)')
        ->and(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'users'))->all())->toBe([]);

    expect(Paths::conflict('author/reuters', 'content', '01kv7m3q9x2c5r8t1w4y6z0b3d'))->toBe('The address /author/reuters/ is used by the author "Reuters".');
});
