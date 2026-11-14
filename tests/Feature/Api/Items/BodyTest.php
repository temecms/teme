<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Api\Items\BodyShape;
use App\Domain\Cards\CardMedia;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\CardType;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Feeds\FeedBody;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://example.com']);
    URL::forceRootUrl('https://example.com');
    URL::forceScheme('https');
});

/**
 * An item's body as the API answers it.
 *
 * @return array{html: string, json: array<string, mixed>, media: array<string, mixed>}
 */
function bodyOf(string $id): array
{
    return test()->getJson('/api/v1/items/'.$id)->assertOk()->json('data.body');
}

/**
 * A post published with this body, through the real Operations.
 *
 * @param  list<array<string, mixed>>  $nodes
 */
function bodyPost(User $author, string $title, array $nodes): Content
{
    $post = lifecycleDraft($author, $title);
    $post->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($post->id, $title, ['type' => 'doc', 'content' => $nodes], [], $post->working_revision_id, (string) $author->id));
    listsPublish($post, $author);

    return $post->refresh();
}

/**
 * A paragraph of one run of text, linked when an address is given.
 *
 * @return array<string, mixed>
 */
function bodyParagraph(string $text, ?string $href = null): array
{
    $run = ['type' => 'text', 'text' => $text];

    if ($href !== null) {
        $run['marks'] = [['type' => 'link', 'attrs' => ['href' => $href]]];
    }

    return ['type' => 'paragraph', 'content' => [$run]];
}

/**
 * The source of a card's class that draws it: everything but the methods
 * that declare, check, describe or count its attributes.
 */
function bodyDrawingSource(CardType $card): string
{
    $source = (string) file_get_contents((string) (new ReflectionClass($card))->getFileName());

    return (string) preg_replace('/function (attributes|validate|describeChange|plainText|references|menu)\(.*?\n    \}\n/s', '', $source);
}

it('passes a paragraph\'s id through unchanged in json, and draws the same html as without it', function (): void {
    $admin = ctAdmin();
    Switches::apiOn();
    $post = bodyPost($admin, 'Ids', [bodyParagraph('First words.'), bodyParagraph('Second words.')]);
    $before = bodyOf($post->id);
    $id = strtoupper((string) Str::ulid());

    // As a later release would write it: the editor's guard refuses an id on a paragraph today.
    $document = json_decode((string) DB::table('content_revisions')->where('id', $post->published_revision_id)->value('document'), true);
    $document['content'][0]['attrs'] = ['id' => $id];
    DB::table('content_revisions')->where('id', $post->published_revision_id)->update(['document' => json_encode($document, JSON_THROW_ON_ERROR)]);

    $after = bodyOf($post->id);

    expect($after['json']['content'][0]['attrs'])->toBe(['id' => $id])
        ->and($after['json']['content'][1])->toBe($before['json']['content'][1])
        ->and($after['html'])->toBe($before['html']);
});

it('gives the heading\'s and the card\'s ids in json as stored', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    $admin = ctAdmin();
    Switches::apiOn();
    $image = mediaUpload($admin, mediaJpeg(800, 600), 'Quay.jpg');
    $heading = strtoupper((string) Str::ulid());
    $card = strtoupper((string) Str::ulid());
    $post = bodyPost($admin, 'Headed', [
        ['type' => 'heading', 'attrs' => ['level' => 2, 'id' => $heading], 'content' => [['type' => 'text', 'text' => 'The quay']]],
        ['type' => 'image', 'attrs' => ['id' => $card, 'mediaId' => $image->id, 'alt' => 'The quay at dawn']],
    ]);

    $json = bodyOf($post->id)['json'];

    expect($json['content'][0]['attrs']['id'])->toBe($heading)
        ->and($json['content'][0]['attrs']['level'])->toBe(2)
        ->and($json['content'][1]['attrs']['id'])->toBe($card)
        ->and($json['content'][1]['attrs']['mediaId'])->toBe($image->id);
});

it('gives the body as the feed\'s HTML, every address absolute', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();
    $document = json_decode((string) DB::table('content_revisions')->where('id', $site->public->published_revision_id)->value('document'), true);

    $html = bodyOf($site->public->id)['html'];
    preg_match_all('/(?:href|src)="([^"]*)"/', $html, $addresses);

    expect($html)->toBe(app(FeedBody::class)->html($document))
        ->and($addresses[1])->not->toBe([])
        ->and(array_filter($addresses[1], fn (string $address): bool => ! str_starts_with($address, 'https://')))->toBe([]);
});

it('gives the body as Tiptap JSON with a link by ID at its address now', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();
    $linkOf = fn (array $json): ?string => collect($json['content'][0]['content'])->firstWhere('text', 'The sequel')['marks'][0]['attrs']['href'] ?? null;

    $was = (string) Addresses::urlFor($site->sequel);
    $before = bodyOf($site->public->id);
    addrRename($site->sequel, 'arrival-two', $site->admin);
    $after = bodyOf($site->public->id);

    expect($was)->toStartWith('https://example.com/')
        ->and($linkOf($before['json']))->toBe($was)
        ->and($linkOf($after['json']))->toBe(Addresses::urlFor($site->sequel->refresh()))
        ->not->toBe($was)
        ->toContain('arrival-two')
        ->and($after['html'])->toContain((string) Addresses::urlFor($site->sequel));
});

it('keeps a link\'s place on the page', function (): void {
    $admin = ctAdmin();
    Switches::apiOn();
    $target = addrPublished($admin, 'Cast list');
    $post = bodyPost($admin, 'Pointer', [bodyParagraph('the cast', 'teme:content/'.$target->id.'#cast')]);

    $mark = bodyOf($post->id)['json']['content'][0]['content'][0]['marks'][0];

    expect($mark['attrs']['href'])->toBe(Addresses::urlFor($target).'#cast');
});

it('removes a link to an item a reader can\'t see, and leaves no teme: address or contentId in any answer', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();

    $body = $this->getJson('/api/v1/items/'.$site->public->id)->assertOk()->json();
    $found = [];
    $walk = function (mixed $value, string $at) use (&$walk, &$found): void {
        if (is_string($value) && str_starts_with($value, 'teme:')) {
            $found[] = $at;
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if ($key === 'contentId') {
                    $found[] = $at.'.contentId';
                }

                $walk($child, $at.'.'.$key);
            }
        }
    };
    $walk($body, '');

    $json = json_encode($body['data']['body']['json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $runs = collect($body['data']['body']['json']['content'][0]['content'])->keyBy('text');

    expect($found)->toBe([])
        ->and($json)->not->toContain($site->draft->id)
        ->and($json)->not->toContain($site->private->id)
        ->and($body['data']['body']['html'])->not->toContain($site->draft->id)
        ->and($json)->toContain((string) Addresses::urlFor($site->sequel))
        ->and($runs['the unfinished cut'])->toEqual(['type' => 'text', 'text' => 'the unfinished cut'])
        ->and($runs['the hidden one'])->toEqual(['type' => 'text', 'text' => 'the hidden one']);
});

it('gives the media the body shows, and leaves a trashed file out', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();
    $card = fn (array $body): ?array => collect($body['json']['content'])->firstWhere('type', 'image');

    $before = bodyOf($site->public->id);
    app(TrashMedia::class)->execute(new MediaActionInput($site->shell->id, (string) $site->admin->id));
    // What a request has read it keeps for that request only (CardMedia); the next answer reads anew.
    app()->forgetInstance(CardMedia::class);
    $after = bodyOf($site->public->id);

    expect(array_keys($before['media']))->toBe([$site->shell->id])
        ->and($before['media'][$site->shell->id]['id'])->toBe($site->shell->id)
        ->and($after['media'])->toBe([])
        ->and($card($after)['attrs']['mediaId'])->toBe($site->shell->id);
});

it('gives a protected item no body, excerpt, image, fields or word count', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();

    $response = $this->getJson('/api/v1/items/'.$site->protected->id)->assertOk();
    $item = $response->json('data');

    expect($item['body'])->toBeNull()
        ->and($item['excerpt'])->toBeNull()
        ->and($item['featured_image'])->toBeNull()
        ->and($item['word_count'])->toBeNull()
        ->and($item['fields'])->toBe([])
        ->and((string) $response->getContent())->toContain('"fields":{}')
        ->not->toContain(DeliverySite::PROTECTED_WORDS)
        ->not->toContain(DeliverySite::PROTECTED_TAGLINE)
        ->not->toContain($site->protectedOnly->id);
});

it('leaves out a button card whose article a reader can\'t see, label and all', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();
    $button = strtoupper((string) Str::ulid());
    $post = bodyPost($site->admin, 'Buttons', [
        ['type' => 'button', 'attrs' => ['id' => $button, 'label' => 'Watch the sequel', 'contentId' => $site->sequel->id]],
    ]);

    $arrival = $this->getJson('/api/v1/items/'.$site->public->id)->assertOk();
    $kept = bodyOf($post->id)['json']['content'][0];

    expect(collect($arrival->json('data.body.json.content'))->pluck('type')->all())->not->toContain('button')
        ->and((string) $arrival->getContent())->not->toContain('Read the unfinished cut')
        ->and($kept['attrs'])->toBe(['id' => $button, 'label' => 'Watch the sequel', 'href' => Addresses::urlFor($site->sequel)]);
});

it('never gives an embed card\'s provider markup', function (): void {
    $admin = ctAdmin();
    Switches::apiOn();
    $post = bodyPost($admin, 'Embedded', [
        ['type' => 'embed', 'attrs' => ['id' => strtoupper((string) Str::ulid()), 'url' => 'https://social.example/post/1', 'kind' => 'social', 'provider' => 'Social', 'title' => 'A post', 'html' => '<script src="https://social.example/widget.js"></script>']],
    ]);
    $response = $this->getJson('/api/v1/items/'.$post->id)->assertOk();
    $answer = (string) $response->getContent();
    $embed = $response->json('data.body.json.content.0.attrs');
    $cards = app(CardRegistry::class);
    $unprinted = [];

    // Every core card: each attribute the page never prints is named in DROPPED, or its id, which passes through.
    foreach ($cards->names() as $name) {
        $card = $cards->find($name);

        if ($card === null || $cards->ownerOf($name) !== 'core') {
            continue;
        }

        foreach (array_keys($card->attributes()) as $attribute) {
            $read = preg_match("/'".preg_quote($attribute, '/')."'/", bodyDrawingSource($card)) === 1;

            if (! $read && $attribute !== 'id' && ! in_array($attribute, BodyShape::DROPPED[$name] ?? [], true)) {
                $unprinted[] = "{$name}.{$attribute}";
            }
        }
    }

    expect($answer)->not->toContain('widget.js')->not->toContain('<script')
        ->and(array_keys($embed))->not->toContain('html')->not->toContain('provider')
        ->and($embed['url'])->toBe('https://social.example/post/1')
        ->and($unprinted)->toBe([]);
});

it('keeps a link to a published item dated ahead, as the page draws it', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();
    $post = bodyPost($site->admin, 'Ahead', [bodyParagraph('coming soon', 'teme:content/'.$site->ahead->id)]);

    $body = bodyOf($post->id);

    expect($body['json']['content'][0]['content'][0]['marks'][0]['attrs']['href'])->toBe(Addresses::urlFor($site->ahead))
        ->and($body['html'])->toContain((string) Addresses::urlFor($site->ahead));
});
