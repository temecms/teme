<?php

declare(strict_types=1);

use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Addresses\Paths;
use App\Domain\People\Credit;
use App\Domain\People\Credits;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\ViewModels\Person;
use App\Domain\Users\Operations\DeleteUser;
use App\Domain\Users\Operations\Input\DeleteUserInput;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\Input\RevokeInvitationInput;
use App\Domain\Users\Operations\InviteUser;
use App\Domain\Users\Operations\RevokeInvitation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->withElevation();
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace', 'bio' => 'Mathematician'])->assignRole('author');
    $this->heir = User::factory()->create(['name' => 'Grace Hopper'])->assignRole('author');
    app(PathProjection::class)->syncUser($this->ada);
    app(PathProjection::class)->syncUser($this->heir);
    $this->reuters = Credited::guest('Reuters', 'reuters', 'News agency');
    $this->article = addrPublished($this->ada, 'Harbour news');
    $this->delete = fn (?User $user = null, ?User $actor = null): mixed => app(DeleteUser::class)->execute(new DeleteUserInput((string) ($user ?? $this->ada)->id, (string) $this->heir->id, (string) ($actor ?? $this->admin)->id));
    $this->personOf = fn (): string => (string) DB::table('content_bylines')->where('content_id', $this->article->id)->where('position', 0)->value('person_id');
    $this->rows = fn (string $table): array => array_map(fn (object $row): array => (array) $row, DB::table($table)->orderBy(DB::raw('1'))->get()->all());
});

it('keeps the departed writer\'s name on their articles, giving the items to someone else', function (): void {
    Switches::apiOn();
    $page = '/author/'.$this->ada->slug.'/';

    ($this->delete)();
    $article = $this->article->refresh();
    $feed = feedParse((string) addrGet('/feed/')->assertOk()->getContent());
    $people = array_values(array_filter(sdGraph('/harbour-news/'), fn (array $node): bool => ($node['@type'] ?? null) === 'Person'));

    expect(app(ArticleViews::class)->published($article)->authors)->toEqual([new Person('Ada Lovelace', $page, 'Mathematician')])
        ->and(array_map(fn (DOMNode $node): string => $node->textContent, iterator_to_array($feed->query('//item/dc:creator') ?: [])))->toBe(['Ada Lovelace'])
        ->and(array_map(fn (array $node): array => [$node['name'], $node['url']], $people))->toBe([['Ada Lovelace', 'http://localhost'.$page]])
        ->and($this->getJson('/api/v1/items/'.$article->id)->assertOk()->json('data.author.name'))->toBe('Ada Lovelace')
        ->and((int) $article->author_id)->toBe((int) $this->heir->id)
        ->and(Gate::forUser($this->heir)->allows('update', $article))->toBeTrue();
});

it('keeps their author page and its list', function (): void {
    DB::table('paths')->insert(['path' => 'writers/ada', 'target_type' => 'user', 'target_id' => (string) $this->ada->id, 'is_current' => false, 'created_at' => now()->subDay(), 'replaced_at' => now()->subDay()]);
    $columns = ['path', 'is_current', 'created_at', 'replaced_at'];
    $before = DB::table('paths')->where('target_type', 'user')->where('target_id', (string) $this->ada->id)->orderBy('path')->get($columns)->all();

    ($this->delete)();
    $person = ($this->personOf)();

    expect(DB::table('paths')->where('target_type', 'person')->where('target_id', $person)->orderBy('path')->get($columns)->all())->toEqual($before)
        ->and(DB::table('paths')->where('target_type', 'user')->where('target_id', (string) $this->ada->id)->count())->toBe(0);

    addrGet('/author/'.$this->ada->slug.'/')->assertOk()->assertSee('Ada Lovelace')->assertSee('Harbour news');
    expect(addrLocation(addrGet('/writers/ada/')->assertStatus(301)))->toBe('/author/'.$this->ada->slug.'/');
});

it('keeps the name, address and biography the account had', function (): void {
    ($this->delete)();

    expect(DB::table('people')->where('id', ($this->personOf)())->first(['name', 'slug', 'bio', 'user_id']))
        ->toEqual((object) ['name' => 'Ada Lovelace', 'slug' => $this->ada->slug, 'bio' => 'Mathematician', 'user_id' => null]);
});

it('makes the writer\'s person when no byline had named them', function (): void {
    expect(DB::table('people')->where('user_id', $this->ada->id)->count())->toBe(0);

    ($this->delete)();

    expect(DB::table('people')->whereNull('user_id')->where('slug', $this->ada->slug)->count())->toBe(1)
        ->and(DB::table('content_bylines')->where('content_id', $this->article->id)->get(['person_id', 'position'])->all())
        ->toEqual([(object) ['person_id' => ($this->personOf)(), 'position' => 0]]);
});

it('keeps an item\'s other credits as they were', function (): void {
    Credited::set($this->article->id, [Credit::person($this->reuters), Credit::account((int) $this->ada->id)]);
    $before = DB::table('content_bylines')->where('content_id', $this->article->id)->orderBy('position')->pluck('person_id')->all();

    ($this->delete)();

    expect(DB::table('content_bylines')->where('content_id', $this->article->id)->orderBy('position')->pluck('person_id')->all())->toBe($before)
        ->and(app(Credits::class)->forItems([$this->article->id => (int) $this->heir->id])[$this->article->id])->toEqual([Credit::person($this->reuters), Credit::person($before[1])]);
});

it('moves no address, with {author} in a pattern', function (): void {
    addrChange($this->admin, ['patterns' => ['post' => '/{author}/{slug}/']]);
    $this->article->refresh();
    app(PathProjection::class)->syncContent($this->article);
    $paths = DB::table('paths')->orderBy('path')->pluck('path')->all();
    $current = addrCurrent('content', $this->article->id);

    Queue::fake();
    ($this->delete)();

    Queue::assertNotPushed(RecomputeAddresses::class);
    expect(DB::table('paths')->orderBy('path')->pluck('path')->all())->toBe($paths)
        ->and(addrCurrent('content', $this->article->id))->toBe($current)
        ->and($current)->toBe($this->ada->slug.'/harbour-news');

    addrGet('/'.$current.'/')->assertOk()->assertSee('Harbour news');
});

it('queues no address recompute when the operator deletes an account, whether its name stays or not', function (): void {
    addrChange($this->admin, ['patterns' => ['post' => '/{author}/{slug}/']]);
    $idle = User::factory()->create(['name' => 'Idle Writer'])->assignRole('author');
    app(PathProjection::class)->syncUser($idle);

    Queue::fake();
    scElevated(fn () => app(DeleteUser::class)->execute(new DeleteUserInput((string) $this->ada->id, (string) $this->heir->id, null)));
    scElevated(fn () => app(DeleteUser::class)->execute(new DeleteUserInput((string) $idle->id, (string) $this->heir->id, null)));

    Queue::assertNotPushed(RecomputeAddresses::class);
    expect(DB::table('users')->whereIn('id', [$this->ada->id, $idle->id])->count())->toBe(0);
});

it('removes the person and their page when nothing credited them', function (): void {
    $idle = User::factory()->create(['name' => 'Idle Writer'])->assignRole('author');
    app(PathProjection::class)->syncUser($idle);

    ($this->delete)($idle);

    expect(DB::table('people')->count())->toBe(1)
        ->and(DB::table('people')->value('id'))->toBe($this->reuters)
        ->and(DB::table('paths')->where('target_type', 'user')->where('target_id', (string) $idle->id)->count())->toBe(0);
});

it('does the same when an invitation is revoked', function (): void {
    Notification::fake();
    app(InviteUser::class)->execute(new InviteUserInput('Ivy Invited', 'ivy@example.com', 'author', (string) $this->admin->id, deliver: false));
    $ivy = User::query()->where('email', 'ivy@example.com')->firstOrFail();
    $wire = addrPublished($this->heir, 'Wire story');
    Credited::set($wire->id, [Credit::person($this->reuters), Credit::account((int) $ivy->id)]);
    $person = (string) DB::table('people')->where('user_id', $ivy->id)->value('id');
    $address = Paths::current('user', (string) $ivy->id);

    app(RevokeInvitation::class)->execute(new RevokeInvitationInput((string) $ivy->id, (string) $this->admin->id));

    expect(User::query()->find($ivy->id))->toBeNull()
        ->and(DB::table('people')->where('id', $person)->first(['name', 'slug', 'user_id']))->toEqual((object) ['name' => 'Ivy Invited', 'slug' => $ivy->slug, 'user_id' => null])
        ->and(Paths::current('person', $person))->toBe($address)
        ->and(DB::table('content_bylines')->where('content_id', $wire->id)->orderBy('position')->pluck('person_id')->all())->toBe([$this->reuters, $person]);

    // An invitee nothing credits goes with their page, as before.
    app(InviteUser::class)->execute(new InviteUserInput('Ned New', 'ned@example.com', 'author', (string) $this->admin->id, deliver: false));
    $ned = User::query()->where('email', 'ned@example.com')->firstOrFail();
    app(RevokeInvitation::class)->execute(new RevokeInvitationInput((string) $ned->id, (string) $this->admin->id));

    expect(DB::table('people')->count())->toBe(2)
        ->and(DB::table('paths')->where('target_type', 'user')->where('target_id', (string) $ned->id)->count())->toBe(0);
});

it('reports the kept writer by the person\'s ID after the delete', function (): void {
    $folder = cacheOn();
    Credited::set($this->article->id, [Credit::account((int) $this->ada->id)]);
    $person = ($this->personOf)();
    cacheShown(['/by-account/' => ['person:'.$this->ada->id], '/by-person/' => ['person:'.$person], '/their-list/' => ['author:'.$person], '/heir/' => ['author:'.$this->heir->id], '/other/' => ['person:'.$this->reuters]]);

    ($this->delete)();

    expect(cacheKept('/by-account/', '/by-person/', '/their-list/', '/heir/', '/other/'))->toBe(['/other/']);

    cacheOff($folder);
});

it('writes nothing when someone without users.manage asks', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    Credited::set($this->article->id, [Credit::person($this->reuters), Credit::account((int) $this->ada->id)]);
    $before = array_map(fn (string $table): array => ($this->rows)($table), ['users', 'people', 'content_bylines', 'paths', 'contents']);

    expect(fn () => ($this->delete)(actor: $editor))->toThrow(AuthorizationException::class);

    expect(array_map(fn (string $table): array => ($this->rows)($table), ['users', 'people', 'content_bylines', 'paths', 'contents']))->toEqual($before);
});

it('locks the departed writer\'s items by ID before crediting them', function (): void {
    $statements = array_column(planStatements(fn () => ($this->delete)()), 'query');
    $user = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'from "users"') && str_contains($sql, 'for update'));
    $items = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'FROM contents WHERE author_id = ? ORDER BY id FOR UPDATE'));
    $credit = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'INSERT INTO content_bylines'));
    $person = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'UPDATE people'));

    expect($user)->toBeInt()
        ->and($items)->toBeInt()
        ->and($credit)->toBeInt()
        ->and($person)->toBeInt()
        ->and($user)->toBeLessThan($items)
        ->and($items)->toBeLessThan($credit)
        ->and($credit)->toBeLessThan($person);
});

it('keeps the credit when their item had bylines naming someone else', function (): void {
    $wire = addrPublished($this->ada, 'Wire story');
    Credited::set($wire->id, [Credit::person($this->reuters)]);

    ($this->delete)();

    expect(DB::table('content_bylines')->where('content_id', $wire->id)->pluck('person_id')->all())->toBe([$this->reuters])
        ->and(DB::table('content_bylines')->where('content_id', $this->article->id)->pluck('person_id')->all())->toBe([($this->personOf)()])
        ->and(Paths::current('person', ($this->personOf)()))->toBe('author/'.$this->ada->slug);
});

it('asks who should own their content', function (): void {
    $refused = null;

    try {
        app(DeleteUser::class)->execute(new DeleteUserInput((string) $this->ada->id, null, (string) $this->admin->id));
    } catch (ValidationException $exception) {
        $refused = $exception->errors()['reassign_to'][0] ?? null;
    }

    expect($refused)->toBe('Choose who should own their content.')
        ->and(User::query()->find($this->ada->id))->not->toBeNull();
});
