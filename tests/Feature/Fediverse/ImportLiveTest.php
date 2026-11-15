<?php

declare(strict_types=1);

use App\Domain\Fediverse\Signatures\KeyPairs;
use App\Domain\Import\ImportRunState;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Fediverse\ImportFixtures;
use Tests\Fixtures\Fediverse\Requests;

uses(RefreshDatabase::class);

/*
 * The proof's first half (docs/plans/fediverse-import.md, D4): each site
 * moving from the WordPress plugin has its live actor and WebFinger answers
 * in tests/Fixtures/Fediverse/live/<host>/, fetched once, public data only.
 * The companion's answer is made from them, the import runs, and Teme's
 * answers are compared with WordPress's field by field. A site's key pair
 * stays on WordPress, so the import gets a pair of its own, and the test
 * proves Teme serves the public half byte for byte as the companion sent it.
 */

/**
 * Each fixture site, with its accounts: the blog's and an author's, where it has them.
 *
 * @return array<string, array{0: string}>
 */
function liveSites(): array
{
    $sites = [];

    foreach (glob(dirname(__DIR__, 2).'/Fixtures/Fediverse/live/*', GLOB_ONLYDIR) ?: [] as $directory) {
        $sites[basename($directory)] = [$directory];
    }

    return $sites;
}

/**
 * @return array<string, mixed>
 */
function liveJson(string $path): array
{
    return (array) json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The companion's account entry for a live actor document.
 *
 * @param  array<string, mixed>  $document
 * @param  array{public: string, private: string}  $keys
 *
 * @return array<string, mixed>
 */
function liveActor(array $document, array $keys): array
{
    preg_match('#/actors/(\d+)/inbox$#', (string) $document['inbox'], $user);
    $host = (string) parse_url((string) $document['id'], PHP_URL_HOST);
    $own = array_filter((array) ($document['alsoKnownAs'] ?? []), fn (string $alias): bool => parse_url($alias, PHP_URL_HOST) === $host);

    return [
        'user_id' => (int) $user[1],
        'user_login' => (string) $document['preferredUsername'],
        'preferred_username' => (string) $document['preferredUsername'],
        'id' => $document['id'],
        'url' => $document['url'],
        'inbox' => $document['inbox'],
        'outbox' => $document['outbox'],
        'followers' => $document['followers'],
        'following' => $document['following'],
        'shared_inbox' => $document['endpoints']['sharedInbox'] ?? null,
        'addresses' => array_values(array_unique([$document['id'], $document['url'], ...$own])),
        'public_key_pem' => $keys['public'],
        'private_key_pem' => $keys['private'],
        'use_permalink_as_id' => $document['id'] !== $document['url'] || ! str_contains((string) $document['id'], '?author='),
        'also_known_as' => [],
        'moved_to' => null,
    ];
}

/** What Teme doesn't yet answer as each fixture site's WordPress did, by site. */
const LIVE_GAPS = [
    'notiz.blog' => [
        'blog: endpoints.sharedInbox',
        'blog: no inbox at WordPress\'s address',
        'blog: no shared inbox at WordPress\'s address',
        'author: no document at its id',
        'author: endpoints.sharedInbox',
        'author: no inbox at WordPress\'s address',
        'author: no shared inbox at WordPress\'s address',
    ],
];

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    app()->instance(Elevation::class, new OperatorElevation);
    $this->companion = importFake();
    ImportFixtures::api($this->companion, 3);
    ImportFixtures::steps();
});

afterEach(function (): void {
    URL::forceRootUrl(null);
    URL::forceScheme(null);
});

it('answers each moved account’s id, inbox, key and WebFinger as WordPress did', function (string $directory): void {
    $accounts = array_values(array_filter(['blog', 'author'], fn (string $name): bool => is_file("{$directory}/{$name}.json")));
    $documents = array_map(fn (string $name): array => liveJson("{$directory}/{$name}.json"), $accounts);
    $root = 'https://'.parse_url((string) $documents[0]['id'], PHP_URL_HOST);
    config(['app.url' => $root]);
    URL::forceRootUrl($root);
    URL::forceScheme('https');
    $source = importSource();
    $keys = [];
    $actors = [];

    foreach ($documents as $index => $document) {
        $keys[$index] = app(KeyPairs::class)->make();
        $actors[] = liveActor($document, $keys[$index]);

        if ($accounts[$index] === 'author') {
            ImportFixtures::person($source, $actors[$index]['user_id']);
        }
    }

    $mode = count($accounts) === 2 ? 'actor_blog' : ($accounts[0] === 'blog' ? 'blog' : 'actor');
    $this->companion->fediverse(ImportFixtures::whole($mode, $actors));
    expect(ImportFixtures::run($source)->state)->toBe(ImportRunState::Finished);
    app()->forgetScopedInstances();

    $gaps = [];

    foreach ($documents as $index => $live) {
        $name = $accounts[$index];
        $atId = Requests::get((string) $live['id']);

        if ($atId->getStatusCode() !== 200) {
            $gaps[] = "{$name}: no document at its id";
            $atId = Requests::get('/?author='.$actors[$index]['user_id']);
        }

        $teme = $atId->assertOk()->json();
        $finger = liveJson("{$directory}/{$name}.webfinger.json");
        $answer = Requests::get('/.well-known/webfinger?resource='.urlencode((string) $finger['subject']), 'application/jrd+json')->assertOk()->json();
        $self = fn (array $jrd): ?string => collect($jrd['links'] ?? [])->firstWhere('rel', 'self')['href'] ?? null;
        $https = fn (array $aliases): array => array_values(array_filter($aliases, fn (string $alias): bool => str_starts_with($alias, 'https://')));

        expect($teme['id'])->toBe($live['id'], "{$name}: id")
            ->and($teme['inbox'])->toBe($live['inbox'], "{$name}: inbox")
            ->and($teme['publicKey']['id'])->toBe($live['publicKey']['id'], "{$name}: publicKey.id")
            ->and($teme['publicKey']['publicKeyPem'])->toBe($keys[$index]['public'], "{$name}: publicKey.publicKeyPem as the companion sent it")
            ->and($answer['subject'])->toBe($finger['subject'], "{$name}: WebFinger subject")
            ->and($self($answer))->toBe($self($finger), "{$name}: WebFinger self")
            ->and(array_diff($https($finger['aliases']), $https($answer['aliases'])))->toBe([], "{$name}: WebFinger aliases");

        if ($teme['endpoints']['sharedInbox'] !== $live['endpoints']['sharedInbox']) {
            $gaps[] = "{$name}: endpoints.sharedInbox";
        }

        // Unsigned, so an inbox Teme routes refuses it with 401; any other answer means no inbox there.
        foreach (['inbox' => $live['inbox'], 'shared inbox' => $live['endpoints']['sharedInbox']] as $what => $inbox) {
            if (Requests::post((string) $inbox, '{}')->getStatusCode() !== 401) {
                $gaps[] = "{$name}: no {$what} at WordPress's address";
            }
        }
    }

    // What this comparison found for the actors part to answer (docs/plans/fediverse-import.md, Records): each site's list shrinks as it is fixed.
    expect($gaps)->toBe(LIVE_GAPS[basename($directory)] ?? []);
})->with(liveSites());
