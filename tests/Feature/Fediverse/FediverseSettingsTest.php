<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Fediverse\FediverseSettingsGroup;
use App\Domain\Fediverse\FediverseSwitch;
use App\Domain\Fediverse\Models\BlockedHost;
use App\Domain\Fediverse\Models\Delivery;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Operations\BlockHost;
use App\Domain\Fediverse\Operations\ChangeFediverseSettings;
use App\Domain\Fediverse\Operations\DisableAuthorActor;
use App\Domain\Fediverse\Operations\EnableAuthorActor;
use App\Domain\Fediverse\Operations\Input\AuthorActorInput;
use App\Domain\Fediverse\Operations\Input\BlockHostInput;
use App\Domain\Fediverse\Operations\Input\ChangeFediverseSettingsInput;
use App\Domain\Fediverse\Operations\Input\SwitchFediverseInput;
use App\Domain\Fediverse\Operations\Input\UnblockHostInput;
use App\Domain\Fediverse\Operations\SwitchFediverse;
use App\Domain\Fediverse\Operations\UnblockHost;
use App\Domain\People\Models\Person;
use App\Domain\Settings\Queries\FindSettings;
use App\Domain\Settings\Queries\SettingsNavigation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\Inbox;
use Tests\Fixtures\Fediverse\Requests;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin', 'slug' => 'ada'])->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->switchTo = fn (User $person, string $value, int $version = 0) => $this->actingAs($person)->from(route('settings.fediverse'))
        ->put(route('settings.fediverse.switch'), ['values' => ['fediverse' => $value], 'expected_version' => $version]);
    $this->nothingWritten = function (): void {
        expect(DB::table('settings')->whereIn('group_key', ['modules', 'fediverse'])->exists())->toBeFalse()
            ->and(FediverseActor::query()->count())->toBe(0)
            ->and(BlockedHost::query()->count())->toBe(0);
    };
});

it('switches the fediverse on, making the blog’s and the application’s accounts with keys of their own, once', function (): void {
    ($this->switchTo)($this->admin, 'on')
        ->assertRedirect(route('settings.fediverse'))
        ->assertInertiaFlash('toast.message', 'The fediverse is on. People on other servers can follow the site.');

    $blog = FediverseActor::query()->where('kind', FediverseActor::BLOG)->sole();
    $application = FediverseActor::query()->where('kind', FediverseActor::APPLICATION)->sole();
    $raw = (string) DB::table('fediverse_actors')->where('id', $blog->id)->value('private_key');

    expect(FediverseSwitch::on())->toBeTrue()
        ->and($blog->uri)->toBe(url('/ap/blog'))
        ->and($blog->username)->toBe('blog')
        ->and($blog->urls['inbox'])->toBe(url('/ap/blog/inbox'))
        ->and($blog->enabled)->toBeTrue()
        ->and($application->uri)->toBe(url('/ap/application'))
        ->and($blog->public_key_pem)->toContain('PUBLIC KEY')
        ->and($blog->public_key_pem)->not->toBe($application->public_key_pem)
        ->and($raw)->not->toContain('PRIVATE KEY')
        ->and(Crypt::decryptString($raw))->toContain('PRIVATE KEY');

    // Off, then on again: the same accounts, keys and followers.
    ($this->switchTo)($this->admin, 'off', 1)->assertInertiaFlash('toast.message', 'The fediverse is paused. Followers and keys are kept for when you switch it back on.');
    ($this->switchTo)($this->admin, 'on', 2);

    expect(FediverseActor::query()->count())->toBe(2)
        ->and(FediverseActor::query()->findOrFail($blog->id)->public_key_pem)->toBe($blog->public_key_pem);

    Requests::get('/ap/blog')->assertOk()->assertJson(['id' => url('/ap/blog')]);
});

it('purges every stored page once the switch commits', function (): void {
    $folder = cacheOn();
    cachePage('http://localhost/', tags: [PageTags::EVERYTHING]);

    ($this->switchTo)($this->admin, 'on')->assertRedirect();

    expect(cacheEntry('http://localhost/'))->toBeNull();

    cacheOff($folder);
});

it('pauses the inboxes while the fediverse is off, keeping the accounts and their followers', function (): void {
    ($this->switchTo)($this->admin, 'on');
    $blog = FediverseActor::query()->where('kind', FediverseActor::BLOG)->sole();
    $remote = Actors::remote();
    Follower::query()->insert(['local_actor_id' => $blog->id, 'remote_actor_id' => $remote->id, 'since' => now()]);

    ($this->switchTo)($this->admin, 'off', 1);

    expect(FediverseSwitch::on())->toBeFalse()
        ->and(Follower::query()->count())->toBe(1);

    Inbox::post('/ap/blog/inbox', Inbox::follow(url('/ap/blog')))->assertStatus(503)->assertHeader('Retry-After', '3600');
    Requests::get('/ap/blog')->assertOk();
});

it('lets no one without settings.modules.manage switch the fediverse, and writes nothing for them', function (): void {
    $settingsOnly = User::factory()->create()->assignRole('editor')->givePermissionTo('settings.fediverse.manage');

    ($this->switchTo)($settingsOnly, 'on')->assertForbidden();

    expect(fn () => app(SwitchFediverse::class)->execute(new SwitchFediverseInput('on', 0, (string) $this->editor->id)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SwitchFediverse::class)->execute(new SwitchFediverseInput('on', 0, null)))->toThrow(AuthorizationException::class)
        ->and(FediverseSwitch::on())->toBeFalse();

    ($this->nothingWritten)();
});

it('refuses a switch value it does not know, and writes nothing', function (): void {
    ($this->switchTo)($this->admin, 'maybe')->assertSessionHasErrors('values.fediverse');

    ($this->nothingWritten)();
});

it('decides who can be followed: the blog, the authors, or both', function (): void {
    ($this->switchTo)($this->admin, 'on');
    $person = Person::query()->create(['user_id' => $this->admin->id]);
    $save = fn (string $who, int $version) => $this->actingAs($this->admin)->from(route('settings.fediverse'))
        ->put(route('settings.fediverse.update'), ['values' => ['followable' => $who], 'expected_version' => $version]);

    $save(FediverseSettingsGroup::BOTH, 0)->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Settings saved.');
    app(EnableAuthorActor::class)->execute(new AuthorActorInput($person->id, (string) $this->admin->id));

    $save(FediverseSettingsGroup::AUTHORS, 1)->assertSessionHasNoErrors();
    expect(FediverseActor::query()->where('kind', FediverseActor::BLOG)->value('enabled'))->toBeFalse()
        ->and(FediverseActor::query()->where('person_id', $person->id)->value('enabled'))->toBeTrue();
    Requests::get('/ap/blog')->assertNotFound();

    $save(FediverseSettingsGroup::BLOG, 2)->assertSessionHasNoErrors();
    expect(FediverseActor::query()->where('kind', FediverseActor::BLOG)->value('enabled'))->toBeTrue()
        ->and(FediverseActor::query()->where('person_id', $person->id)->value('enabled'))->toBeFalse();
});

it('lets no one without settings.fediverse.manage change who can be followed, and writes nothing for them', function (): void {
    $this->actingAs($this->editor)->put(route('settings.fediverse.update'), ['values' => ['followable' => 'authors'], 'expected_version' => 0])->assertForbidden();

    expect(fn () => app(ChangeFediverseSettings::class)->execute(new ChangeFediverseSettingsInput(['followable' => 'authors'], 0, (string) $this->editor->id)))
        ->toThrow(AuthorizationException::class);

    ($this->nothingWritten)();
});

it('refuses a value out of range, and writes nothing', function (): void {
    $this->actingAs($this->admin)->from(route('settings.fediverse'))
        ->put(route('settings.fediverse.update'), ['values' => ['followable' => 'everyone'], 'expected_version' => 0])
        ->assertSessionHasErrors('values.followable');

    ($this->nothingWritten)();
});

it('gives an author an account of their own at /ap/people/, found by WebFinger, and takes it away again, keeping its followers', function (): void {
    ($this->switchTo)($this->admin, 'on');
    Inbox::followable(FediverseSettingsGroup::BOTH);
    $person = Person::query()->create(['user_id' => $this->admin->id]);

    $this->actingAs($this->admin)->from(route('settings.fediverse'))
        ->post(route('settings.fediverse.authors.enable', ['person' => $person->id]))
        ->assertRedirect(route('settings.fediverse'))
        ->assertInertiaFlash('toast.message', 'Ada Admin can be followed as @ada@localhost.');

    $actor = FediverseActor::query()->where('person_id', $person->id)->sole();

    expect($actor->uri)->toBe(url('/ap/people/'.$person->id))
        ->and($actor->username)->toBe('ada');
    Requests::get('/.well-known/webfinger?resource=acct:ada@localhost', 'application/jrd+json')->assertOk()->assertJsonPath('links.0.href', $actor->uri);

    Follower::query()->insert(['local_actor_id' => $actor->id, 'remote_actor_id' => Actors::remote()->id, 'since' => now()]);

    $this->actingAs($this->admin)->from(route('settings.fediverse'))
        ->delete(route('settings.fediverse.authors.disable', ['person' => $person->id]))
        ->assertInertiaFlash('toast.message', 'Ada Admin can no longer be followed. Their followers are kept.');

    expect($actor->refresh()->enabled)->toBeFalse()
        ->and(Follower::query()->count())->toBe(1);
    Requests::get('/ap/people/'.$person->id)->assertNotFound();
});

it('refuses an author an account while only the blog can be followed, or under a name another account has', function (): void {
    ($this->switchTo)($this->admin, 'on');
    $person = Person::query()->create(['user_id' => $this->admin->id]);
    $guest = Person::query()->create(['name' => 'Blog Writer', 'slug' => 'blog']);

    expect(fn () => app(EnableAuthorActor::class)->execute(new AuthorActorInput($person->id, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'Only the blog can be followed.');

    Inbox::followable(FediverseSettingsGroup::BOTH);

    expect(fn () => app(EnableAuthorActor::class)->execute(new AuthorActorInput($guest->id, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'Another account is called @blog.')
        ->and(FediverseActor::query()->whereNotNull('person_id')->count())->toBe(0);
});

it('lets no one without settings.fediverse.manage give or take an author’s account, and writes nothing for them', function (): void {
    $person = Person::query()->create(['user_id' => $this->editor->id]);
    Inbox::followable(FediverseSettingsGroup::BOTH);

    $this->actingAs($this->editor)->post(route('settings.fediverse.authors.enable', ['person' => $person->id]))->assertForbidden();

    expect(fn () => app(EnableAuthorActor::class)->execute(new AuthorActorInput($person->id, (string) $this->editor->id)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DisableAuthorActor::class)->execute(new AuthorActorInput($person->id, (string) $this->editor->id)))->toThrow(AuthorizationException::class)
        ->and(FediverseActor::query()->count())->toBe(0);
});

it('blocks a server and its subdomains, then unblocks it', function (): void {
    $this->actingAs($this->admin)->from(route('settings.fediverse'))
        ->post(route('settings.fediverse.blocked.store'), ['host' => ' Spam.Example. ', 'reason' => 'Spam'])
        ->assertRedirect(route('settings.fediverse'))
        ->assertInertiaFlash('toast.message', 'spam.example is blocked. Nothing from it is accepted, and nothing is sent to it.');

    $blocked = BlockedHost::query()->sole();

    expect($blocked->host)->toBe('spam.example')
        ->and($blocked->reason)->toBe('Spam')
        ->and($blocked->created_by)->toBe($this->admin->id);

    Inbox::switch(true);
    Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog')), from: 'https://social.spam.example/users/alice')->assertForbidden();

    $this->actingAs($this->admin)->from(route('settings.fediverse'))
        ->delete(route('settings.fediverse.blocked.destroy', ['host' => 'spam.example']))
        ->assertInertiaFlash('toast.message', 'spam.example is unblocked.');

    expect(BlockedHost::query()->count())->toBe(0);
});

it('refuses to block a server name that isn’t one, or the site itself, and writes nothing', function (string $host): void {
    $this->actingAs($this->admin)->from(route('settings.fediverse'))
        ->post(route('settings.fediverse.blocked.store'), ['host' => $host])
        ->assertSessionHasErrors('host');

    ($this->nothingWritten)();
})->with(['https://spam.example/', 'spam', 'localhost', 'spam example']);

it('lets no one without settings.fediverse.manage block or unblock a server, and writes nothing for them', function (): void {
    BlockedHost::query()->create(['host' => 'kept.example', 'created_at' => now()]);

    $this->actingAs($this->editor)->post(route('settings.fediverse.blocked.store'), ['host' => 'spam.example'])->assertForbidden();
    $this->actingAs($this->editor)->delete(route('settings.fediverse.blocked.destroy', ['host' => 'kept.example']))->assertForbidden();

    expect(fn () => app(BlockHost::class)->execute(new BlockHostInput('spam.example', null, (string) $this->editor->id)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UnblockHost::class)->execute(new UnblockHostInput('kept.example', (string) $this->editor->id)))->toThrow(AuthorizationException::class)
        ->and(BlockedHost::query()->pluck('host')->all())->toBe(['kept.example']);
});

it('shows the switch, who can be followed, the accounts, the servers not answering, the blocked servers and the import', function (): void {
    ($this->switchTo)($this->admin, 'on');
    $blog = FediverseActor::query()->where('kind', FediverseActor::BLOG)->sole();
    $alice = Actors::remote();
    $quiet = Actors::remote('https://quiet.example/users/bo', ['failing_since' => now()->subDays(3)]);
    Follower::query()->insert([
        ['local_actor_id' => $blog->id, 'remote_actor_id' => $alice->id, 'since' => now()],
        ['local_actor_id' => $blog->id, 'remote_actor_id' => $quiet->id, 'since' => now()],
    ]);
    $activity = (string) str()->ulid();
    DB::table('fediverse_activities')->insert(['id' => $activity, 'local_actor_id' => $blog->id, 'type' => 'Create', 'object_uri' => url('/ap/objects/1'), 'payload' => '{}', 'created_at' => now()]);
    Delivery::query()->create(['activity_id' => $activity, 'inbox_url' => 'https://down.example/inbox', 'host' => 'down.example', 'state' => 'failed', 'attempts' => 8, 'next_attempt_at' => now(), 'last_error' => 'Timed out']);
    BlockedHost::query()->create(['host' => 'spam.example', 'reason' => 'Spam', 'created_at' => now()]);

    $this->actingAs($this->admin)->get(route('settings.fediverse'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/fediverse')
            ->where('fediverse.value', 'on')
            ->where('fediverse.canSwitch', true)
            ->where('values.followable', FediverseSettingsGroup::BLOG)
            ->where('accounts.0.handle', '@blog@localhost')
            ->where('accounts.0.followers', 2)
            ->where('accounts.0.kind', 'blog')
            ->where('unreachable', [
                ['host' => 'down.example', 'accounts' => 0, 'failed' => 1],
                ['host' => 'quiet.example', 'accounts' => 1, 'failed' => 0],
            ])
            ->where('blocked.0.host', 'spam.example')
            ->where('blocked.0.reason', 'Spam')
            ->where('imported', ['accounts' => 0, 'followers' => 0]));
});

it('shows an imported account and its followers as the import’s status', function (): void {
    Actors::local(FediverseActor::BLOG, 'site', Requests::url('/?author=0'));
    $blog = FediverseActor::query()->sole();
    Follower::query()->insert(['local_actor_id' => $blog->id, 'remote_actor_id' => Actors::remote()->id, 'since' => now()]);

    $this->actingAs($this->admin)->get(route('settings.fediverse'))
        ->assertInertia(fn (Assert $page) => $page->where('imported', ['accounts' => 1, 'followers' => 1])
            ->where('fediverse.value', 'off'));
});

it('lists authors who could be followed while authors can be, each with one action', function (): void {
    Inbox::followable(FediverseSettingsGroup::BOTH);
    $person = Person::query()->create(['user_id' => $this->admin->id]);

    $this->actingAs($this->admin)->get(route('settings.fediverse'))
        ->assertInertia(fn (Assert $page) => $page->where('accounts', [[
            'kind' => 'person',
            'personId' => $person->id,
            'name' => 'Ada Admin',
            'handle' => '@ada@localhost',
            'followers' => 0,
            'enabled' => false,
        ]]));
});

it('is a Settings page for whoever holds settings.fediverse.manage, where Find a setting finds the switch', function (): void {
    $pages = collect(app(SettingsNavigation::class)->forUser($this->admin->id))->pluck('href', 'key');
    $found = collect(app(FindSettings::class)->run('fediverse', $this->admin)['settings'])->firstWhere('key', 'modules.fediverse');

    expect($pages->get('fediverse'))->toBe('/admin/settings/fediverse')
        ->and($found['href'] ?? null)->toBe('/admin/settings/fediverse#field-fediverse')
        ->and(collect(app(SettingsNavigation::class)->forUser($this->editor->id))->pluck('key'))->not->toContain('fediverse');

    $this->actingAs($this->admin)->get('/admin/settings/fediverse')->assertOk();
    $this->actingAs($this->editor)->get(route('settings.fediverse'))->assertForbidden();
});
