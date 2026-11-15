<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\People\Models\Person;
use App\Domain\Settings\Modules;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\Inbox;
use Tests\Fixtures\Fediverse\Requests;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ada Writer'])->assignRole('editor');
    $this->blog = Actors::blog();
    Inbox::switch(true);
});

/**
 * Registers the fediverse's settings as Settings › Fediverse will, each
 * set to the value given (a select's first option is its value).
 */
function fpubSettings(string $objectType = 'Article', string $mode = 'blog'): void
{
    $group = Modules::group();
    $first = fn (string $value, array $options): array => [$value => $value, ...array_diff_key($options, [$value => true])];

    app(SettingsRegistry::class)->replace(new SettingsGroup(
        key: 'fediverse',
        label: 'Fediverse',
        description: 'Test settings.',
        section: $group->section,
        ability: $group->ability,
        settings: [
            Setting::select('object_type', 'Object type', 'Test.', '0.1.0', $first($objectType, ['Article' => 'Article', 'Note' => 'Note'])),
            Setting::select('followable', 'Who can be followed', 'Test.', '0.1.0', $first($mode, ['blog' => 'blog', 'authors' => 'authors', 'both' => 'both'])),
        ],
        since: '0.1.0',
    ));
}

/**
 * The address's path and query, as a remote server asks for it here.
 */
function fpubPath(string $uri): string
{
    return substr($uri, strlen(rtrim(url('/'), '/')));
}

it('answers a published item as an Article at its object address', function (): void {
    $item = addrPublished($this->author, 'Harbour news');
    docSave($item, $this->author, docWith(docText('The ferry is late.')));
    listsPublish($item, $this->author);
    $uri = FediverseObject::query()->where('content_id', $item->id)->value('uri');

    $response = Requests::get(fpubPath($uri))->assertOk();
    $object = $response->json();

    expect($response->headers->get('Content-Type'))->toContain('application/activity+json')
        ->and($object['id'])->toBe($uri)
        ->and($object['type'])->toBe('Article')
        ->and($object['name'])->toBe('Harbour news')
        ->and($object['url'])->toBe(Addresses::urlFor($item->refresh()))
        ->and($object['attributedTo'])->toBe($this->blog->uri)
        ->and($object['content'])->toContain('The ferry is late.')
        ->and($object['to'])->toBe(['https://www.w3.org/ns/activitystreams#Public'])
        ->and($object['cc'])->toBe([$this->blog->urls['followers']])
        ->and($object['published'])->toEndWith('Z');
});

it('sends a Note with the title linked to the page when the settings say Note', function (): void {
    fpubSettings('Note');
    $item = addrPublished($this->author, 'Harbour news');
    $object = Activity::query()->where('type', 'Create')->sole()->payload['object'];

    expect($object['type'])->toBe('Note')
        ->and($object)->not->toHaveKey('name')
        ->and($object['content'])->toStartWith('<p><a href="'.Addresses::urlFor($item).'">Harbour news</a></p>');
});

it('keeps an imported object’s address, answering it there and sending its Update under it', function (): void {
    $item = addrPublished($this->author, 'Harbour news');
    $imported = Requests::url('/?p=12');
    FediverseObject::query()->where('content_id', $item->id)->delete();
    Activity::query()->delete();
    FediverseObject::query()->create(['uri' => $imported, 'kind' => 'post', 'content_id' => $item->id]);

    Requests::get('/?p=12')->assertOk()->assertJson(['id' => $imported, 'type' => 'Article']);

    listsPublish($item, $this->author);

    expect(Activity::query()->sole()->type)->toBe('Update')
        ->and(Activity::query()->sole()->payload['object']['id'])->toBe($imported);
});

it('attributes an item to its first byline with an author actor, the author sending it and the blog announcing it', function (string $mode, array $sent): void {
    fpubSettings('Article', $mode);
    $person = Person::query()->where('user_id', $this->author->id)->first() ?? Person::query()->create(['name' => 'Ada Writer', 'slug' => 'ada-writer', 'user_id' => $this->author->id]);
    $ada = Actors::local(FediverseActor::PERSON, 'ada', url('/ap/people/ada'), personId: $person->id);

    addrPublished($this->author, 'Harbour news');
    $create = Activity::query()->where('type', 'Create')->sole();
    $byActor = [$ada->id => 'ada', $this->blog->id => 'blog'];

    expect($create->payload['object']['attributedTo'])->toBe($sent[0] === 'ada' ? $ada->uri : $this->blog->uri)
        ->and(Activity::query()->orderBy('created_at')->orderBy('id')->get()->map(fn (Activity $activity): string => $activity->type.' by '.$byActor[$activity->local_actor_id])->all())->toBe($sent[1]);
})->with([
    'the blog' => ['blog', ['blog', ['Create by blog']]],
    'each author' => ['authors', ['ada', ['Create by ada']]],
    'the blog and each author' => ['both', ['ada', ['Create by ada', 'Announce by blog']]],
]);

it('answers a removed item’s address with a Tombstone and 410', function (): void {
    $item = addrPublished($this->author, 'Harbour news');
    $uri = FediverseObject::query()->where('content_id', $item->id)->value('uri');
    app(TrashContent::class)->execute(new ContentActionInput($item->id, (string) $this->author->id));

    Requests::get(fpubPath($uri))->assertStatus(410)->assertJson(['id' => $uri, 'type' => 'Tombstone']);
});

it('answers 404 for an object whose item is no longer public, or no longer federated', function (): void {
    $item = addrPublished($this->author, 'Harbour news');
    $uri = FediverseObject::query()->where('content_id', $item->id)->value('uri');
    Inbox::switch(false);
    app(TrashContent::class)->execute(new ContentActionInput($item->id, (string) $this->author->id));

    Requests::get(fpubPath($uri))->assertNotFound();
});
