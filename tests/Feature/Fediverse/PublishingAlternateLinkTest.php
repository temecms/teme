<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Publishing\ContentActivities;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\People\Models\Person;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\Inbox;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    app(PathProjection::class)->syncUser($this->author);
    $this->blog = Actors::blog();
    Inbox::switch(true);
    Inbox::followable('both');
    ContentActivities::listen(app(HookDispatcher::class));
    $this->item = pageArticle($this->author, 'Harbour news');
    $this->object = FediverseObject::query()->where('content_id', $this->item->id)->value('uri');
    $person = Person::query()->where('user_id', $this->author->id)->first() ?? Person::query()->create(['name' => 'Jane Doe', 'slug' => 'jane-doe', 'user_id' => $this->author->id]);
    $this->jane = Actors::local(FediverseActor::PERSON, 'jane', url('/ap/people/jane'), personId: $person->id);
});

/**
 * The ActivityPub alternates a page links in its head.
 *
 * @return list<string>
 */
function fpubAlternates(string $uri): array
{
    $dom = dtDom((string) addrGet($uri)->assertOk()->getContent());
    $links = '//head/link[@rel="alternate"][@type="application/activity+json"]';
    $found = [];

    for ($i = 1; $i <= (int) $dom->evaluate("count({$links})"); $i++) {
        $found[] = (string) $dom->evaluate("string({$links}[{$i}]/@href)");
    }

    return $found;
}

it('links a federated item’s object, and an author’s account, in their pages’ heads', function (): void {
    expect($this->object)->toBeString()
        ->and(fpubAlternates('/harbour-news/'))->toBe([$this->object])
        ->and(fpubAlternates('/author/jane-doe/'))->toBe([$this->jane->uri])
        ->and(fpubAlternates('/'))->toBe([]);
});

it('links nothing while the fediverse is off', function (): void {
    Inbox::switch(false);

    expect(fpubAlternates('/harbour-news/'))->toBe([])
        ->and(fpubAlternates('/author/jane-doe/'))->toBe([]);
});

it('finds an author’s account in the same queries however many authors have one', function (): void {
    $queries = function (): int {
        fpubAlternates('/author/jane-doe/');
        DB::flushQueryLog();
        DB::enableQueryLog();
        fpubAlternates('/author/jane-doe/');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $before = $queries();

    // Other authors' accounts, and Jane's again after them.
    $jane = $this->jane->person_id;
    $this->jane->delete();

    foreach (['ann', 'ben', 'cal'] as $name) {
        $person = Person::query()->create(['name' => ucfirst($name), 'slug' => $name]);
        Actors::local(FediverseActor::PERSON, $name, url('/ap/people/'.$name), personId: $person->id);
    }

    $again = Actors::local(FediverseActor::PERSON, 'jane', url('/ap/people/jane'), personId: $jane);

    expect($queries())->toBe($before)
        ->and(fpubAlternates('/author/jane-doe/'))->toBe([$again->uri]);
});

it('links no author’s account while only the blog can be followed', function (): void {
    Inbox::followable('blog');

    expect(fpubAlternates('/author/jane-doe/'))->toBe([])
        ->and(fpubAlternates('/harbour-news/'))->toBe([$this->object]);
});
