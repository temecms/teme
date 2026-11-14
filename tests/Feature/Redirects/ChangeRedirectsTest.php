<?php

declare(strict_types=1);

use App\Domain\Redirects\Operations\CreateRedirect;
use App\Domain\Redirects\Operations\DeleteRedirects;
use App\Domain\Redirects\Operations\EditRedirect;
use App\Domain\Redirects\Operations\Input\CreateRedirectInput;
use App\Domain\Redirects\Operations\Input\DeleteRedirectsInput;
use App\Domain\Redirects\Operations\Input\EditRedirectInput;
use App\Domain\Redirects\Operations\Input\SwitchRedirectsInput;
use App\Domain\Redirects\Operations\SwitchRedirects;
use App\Domain\Redirects\RedirectLookup;
use App\Domain\Redirects\RedirectState;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->create = fn (string $from, string $to = '', int $status = 301, ?User $actor = null, ?string $item = null, string $note = ''): array => app(CreateRedirect::class)->execute(
        new CreateRedirectInput($from, $status, $to, contentId: $item, note: $note, actorId: (string) ($actor ?? $this->editor)->id),
    );
    $this->logged = fn (string $type): array => DB::table('security_events')->where('type', $type)->orderBy('id')->get(['user_id', 'context'])
        ->map(fn (object $row): array => ['user' => $row->user_id === null ? null : (int) $row->user_id, ...json_decode((string) $row->context, true)])->all();
});

it('adds a redirect for an editor or an administrator, records it, and says whether it waits (I-850, I-694, I-858)', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);
    addrPublished($this->editor, 'Harbour news');

    $added = ($this->create)('/Old-Fares/', 'https://podcasts.example.net/fares', 302, $person, note: 'On the printed leaflet.');
    $waiting = ($this->create)('/harbour-news', '/news/', actor: $person);

    expect($added['state'])->toBe(RedirectState::On)
        ->and($waiting)->toMatchArray(['state' => RedirectState::WaitingForPage, 'label' => 'Waiting: a page answers this address'])
        ->and((array) DB::table('redirects')->where('id', $added['id'])->first(['source', 'kind', 'status', 'target_kind', 'target', 'origin', 'enabled', 'note', 'created_by']))->toBe([
            'source' => 'old-fares', 'kind' => 'exact', 'status' => 302, 'target_kind' => 'outside', 'target' => 'https://podcasts.example.net/fares',
            'origin' => 'manual', 'enabled' => true, 'note' => 'On the printed leaflet.', 'created_by' => $person->id,
        ])
        ->and(($this->logged)('redirect.created')[0])->toEqual(['user' => $person->id, 'redirect' => $added['id'], 'from' => '/old-fares', 'to' => 'https://podcasts.example.net/fares', 'status' => 302]);
})->with(['editor', 'administrator']);

it('writes nothing for an author or a contributor, whatever the change (authorization)', function (string $change, string $role): void {
    $id = rdSaved('/old-fares', '/news/');
    $person = User::factory()->create()->assignRole($role);
    $actor = (string) $person->id;
    $before = DB::table('redirects')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

    $run = match ($change) {
        'create' => fn () => app(CreateRedirect::class)->execute(new CreateRedirectInput('/elsewhere', 301, '/news/', actorId: $actor)),
        'edit' => fn () => app(EditRedirect::class)->execute(new EditRedirectInput($id, '/old-fares', 301, '/sport/', actorId: $actor)),
        'switch' => fn () => app(SwitchRedirects::class)->execute(new SwitchRedirectsInput([$id], false, $actor)),
        'delete' => fn () => app(DeleteRedirects::class)->execute(new DeleteRedirectsInput([$id], $actor)),
    };

    expect($run)->toThrow(AuthorizationException::class)
        ->and(DB::table('redirects')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before)
        ->and(DB::table('security_events')->where('type', 'like', 'redirect.%')->count())->toBe(0);
})->with(['create', 'edit', 'switch', 'delete'])->with(['author', 'contributor']);

it('refuses under the field the form shows, and writes nothing', function (): void {
    rdSaved('/a', '/b');

    expect(fn () => ($this->create)('/x', '//evil.example'))->toThrow(fn (ValidationException $refused) => expect($refused->errors())->toBe(['to' => ['An address can’t start with // or /\\, because browsers read those as another site. For another site, write the full address, starting https://.']]))
        ->and(fn () => ($this->create)('/b', '/c'))->toThrow(fn (ValidationException $refused) => expect($refused->errors())->toBe(['source' => ['/a already sends readers to /b, so they’d take two steps. Change that redirect first, or choose another address.']]))
        ->and(DB::table('redirects')->count())->toBe(1)
        ->and(DB::table('security_events')->count())->toBe(0);
});

it('edits a redirect, keeping its origin, switch and hits, and records what it was', function (): void {
    $added = ($this->create)('/old-fares', '/news/');
    DB::table('redirects')->where('id', $added['id'])->update(['hits' => 12, 'origin' => 'import', 'enabled' => false]);

    $edited = app(EditRedirect::class)->execute(new EditRedirectInput($added['id'], '/old-fares', 410, note: 'Withdrawn.', actorId: (string) $this->editor->id));

    expect($edited['state'])->toBe(RedirectState::Off)
        ->and((array) DB::table('redirects')->where('id', $added['id'])->first(['status', 'target_kind', 'target', 'hits', 'origin', 'enabled', 'note', 'updated_by']))->toBe([
            'status' => 410, 'target_kind' => null, 'target' => null, 'hits' => 12, 'origin' => 'import', 'enabled' => false, 'note' => 'Withdrawn.', 'updated_by' => $this->editor->id,
        ])
        ->and(($this->logged)('redirect.changed')[0])->toEqual([
            'user' => $this->editor->id, 'redirect' => $added['id'], 'from' => '/old-fares', 'to' => '', 'status' => 410,
            'was_from' => '/old-fares', 'was_to' => '/news/', 'was_status' => 301,
        ])
        ->and(fn () => app(EditRedirect::class)->execute(new EditRedirectInput('01jvm0000000000000000000aa', '/x', 301, '/y/', actorId: (string) $this->editor->id)))
        ->toThrow(fn (ValidationException $refused) => expect($refused->errors())->toBe(['redirect' => ['This redirect was deleted meanwhile.']]));
});

it('switches redirects on and off, and deletes them, counting and recording only those that change', function (): void {
    $one = rdSaved('/one', '/news/');
    $two = rdSaved('/two', '/news/', on: false);
    $switch = fn (array $ids, bool $on): int => app(SwitchRedirects::class)->execute(new SwitchRedirectsInput($ids, $on, (string) $this->editor->id));

    expect($switch([$one, $two], false))->toBe(1)
        ->and(DB::table('redirects')->where('enabled', true)->count())->toBe(0)
        ->and(($this->logged)('redirect.switched'))->toEqual([['user' => $this->editor->id, 'redirect' => $one, 'from' => '/one', 'to' => '/news/', 'status' => 301, 'on' => false]])
        ->and(app(DeleteRedirects::class)->execute(new DeleteRedirectsInput([$one, $two, '01jvm0000000000000000000aa'], (string) $this->editor->id)))->toBe(2)
        ->and(DB::table('redirects')->count())->toBe(0)
        ->and(array_column(($this->logged)('redirect.deleted'), 'from'))->toEqualCanonicalizing(['/one', '/two'])
        ->and(fn () => $switch([], true))->toThrow(fn (ValidationException $refused) => expect($refused->errors())->toBe(['redirects' => ['Choose between 1 and 500 redirects.']]));
});

it('removes the stored not-found page an exact redirect replaces, and every one for a pattern, after commit and never after a rollback (I-297)', function (): void {
    $folder = cacheOn();
    addrPublished($this->editor, 'Harbour news');

    foreach (['/old-fares/', '/2019/05/ferry/', '/other-missing/', '/harbour-news/', '/rolled-back/'] as $uri) {
        addrGet($uri)->assertHeader('X-Teme-Cache', 'MISS');
    }

    ($this->create)('/old-fares', '/news/');
    expect(cacheKept('/old-fares/', '/2019/05/ferry/', '/other-missing/', '/harbour-news/', '/rolled-back/'))->toBe(['/2019/05/ferry/', '/other-missing/', '/harbour-news/', '/rolled-back/']);

    cacheRolledBack(fn () => ($this->create)('/rolled-back', '/news/'));
    expect(cacheKept('/rolled-back/'))->toBe(['/rolled-back/']);

    // To /stories/[slug]/, not /[slug]/: that would lead on through /old-fares, a chain (I-859).
    ($this->create)('/2019/05/[slug]', '/stories/[slug]/');
    expect(cacheKept('/2019/05/ferry/', '/other-missing/', '/harbour-news/', '/rolled-back/'))->toBe(['/harbour-news/']);

    cacheOff($folder);
});

it('forgets the compiled patterns once a change commits, so the next address reads the new ones', function (): void {
    $lookup = app(RedirectLookup::class);

    expect($lookup->answer('/blog/2020/', ''))->toBeNull();

    ($this->create)('/blog/[...rest]', '/news/[...rest]');

    expect($lookup->answer('/blog/2020/', '')?->location)->toBe('http://localhost/news/2020');
});
